<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Roster\Domain\PlannedShift;
use YouWillLikeIT\CareHome\Roster\Domain\RuleEngine;
use YouWillLikeIT\CareHome\Roster\Domain\Violation;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * All checks for "member works shift type X on date D".
 *
 * Blocking: not a plannable worker, not employed, shift type not for the role / inactive,
 * month locked, approved absence (not a wish), overlapping shift.
 * Warnings: working-time rules, pending absence request, wish for a free day.
 */
class AssignmentValidator
{
    use Injectable;

    /** Days loaded around the candidate for the rule engine. */
    private const int CONTEXT_DAYS = 14;

    /**
     * @param list<int>          $ignoreAssignmentIds assignments that will be replaced/moved away
     * @param list<PlannedShift> $extraShifts         unsaved shifts of the same member (batch operations)
     */
    public function check(
        Member $member,
        ShiftType $type,
        DateTimeImmutable $date,
        array $ignoreAssignmentIds = [],
        array $extraShifts = [],
    ): ValidationReport {
        $date = $date->setTime(0, 0);
        $report = $this->basicChecks($member, $type, $date, true);
        if ($report->isBlocked()) {
            return $report;
        }
        [$start, $end] = $type->windowFor($date);

        return $this->checkRules(
            $member,
            new PlannedShift(0, $start, $end, (int) $type->BreakMinutes, (string) $type->Code),
            $ignoreAssignmentIds,
            $extraShifts,
            $report
        );
    }

    /**
     * Checks for an existing assignment moved to another worker (substitution, swap).
     * Times come from the assignment snapshot; a since-deactivated shift type does not block.
     *
     * @param list<int>          $ignoreAssignmentIds
     * @param list<PlannedShift> $extraShifts
     */
    public function checkAssignmentFor(
        Member $member,
        ShiftAssignment $assignment,
        array $ignoreAssignmentIds = [],
        array $extraShifts = [],
    ): ValidationReport {
        $report = $this->basicChecks($member, $assignment->ShiftType(), $assignment->dateObject(), false);
        if ($report->isBlocked()) {
            return $report;
        }
        $shift = $assignment->toPlannedShift();

        return $this->checkRules(
            $member,
            new PlannedShift(0, $shift->start, $shift->end, $shift->breakMinutes, $shift->code),
            $ignoreAssignmentIds,
            $extraShifts,
            $report
        );
    }

    private function basicChecks(Member $member, ShiftType $type, DateTimeImmutable $date, bool $requireActiveType): ValidationReport
    {
        $report = new ValidationReport();

        if (!$member->isRosterCandidate()) {
            return $report->block(_t(__CLASS__ . '.NOT_WORKER', '{name} nije radnik koji se planira u smjene.', ['name' => $member->getName()]));
        }
        if (!$member->isEmployedOn($date)) {
            $report->block(_t(__CLASS__ . '.NOT_EMPLOYED', '{name} nije zaposlen/a na dan {date}.', [
                'name' => $member->getName(),
                'date' => $date->format('d.m.Y.'),
            ]));
        }
        if ($requireActiveType && !$type->IsActive) {
            $report->block(_t(__CLASS__ . '.TYPE_INACTIVE', 'Smjena {code} nije aktivna.', ['code' => $type->Code]));
        }
        if (!$type->appliesToRole((string) $member->WorkerRole)) {
            $report->block(_t(__CLASS__ . '.TYPE_ROLE', 'Smjena {code} nije predviđena za ulogu „{role}".', [
                'code' => $type->Code,
                'role' => MemberCareExtension::roleOptions()[$member->WorkerRole] ?? $member->WorkerRole,
            ]));
        }
        if ($this->isLocked($date)) {
            $report->block(_t(__CLASS__ . '.LOCKED', 'Mjesec {month} je zaključan.', ['month' => $date->format('m/Y')]));
        }
        $this->checkAbsences($member, $date, $report);

        return $report;
    }

    /**
     * @param list<int>          $ignoreAssignmentIds
     * @param list<PlannedShift> $extraShifts
     */
    private function checkRules(
        Member $member,
        PlannedShift $candidate,
        array $ignoreAssignmentIds,
        array $extraShifts,
        ValidationReport $report,
    ): ValidationReport {
        $date = $candidate->start->setTime(0, 0);
        $others = $this->timeline(
            (int) $member->ID,
            $date->modify('-' . self::CONTEXT_DAYS . ' days'),
            $date->modify('+' . (self::CONTEXT_DAYS + 1) . ' days'),
            $ignoreAssignmentIds
        );
        foreach ($extraShifts as $extra) {
            $others[] = $extra;
        }

        $engine = new RuleEngine(RosterSettings::current()->toRuleConfig());
        foreach ($engine->check($candidate, $others) as $violation) {
            $message = $this->message($violation, $member, $date, $candidate->code);
            $violation->isBlocking() ? $report->block($message) : $report->warn($message);
        }

        return $report;
    }

    /**
     * Planned shifts of a member overlapping [from, to).
     *
     * @param list<int> $ignoreIds
     * @return list<PlannedShift>
     */
    public function timeline(int $memberId, DateTimeImmutable $from, DateTimeImmutable $to, array $ignoreIds = []): array
    {
        $clock = Clock::singleton();
        $list = ShiftAssignment::get()->filter([
            'MemberID' => $memberId,
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'StartsAt:LessThan' => $clock->toDbDatetime($to),
            'EndsAt:GreaterThan' => $clock->toDbDatetime($from),
        ]);
        $ignoreIds = array_values(array_filter(array_map('intval', $ignoreIds)));
        if ($ignoreIds) {
            $list = $list->exclude('ID', $ignoreIds);
        }

        $shifts = [];
        foreach ($list as $assignment) {
            $shifts[] = $assignment->toPlannedShift();
        }

        return $shifts;
    }

    public function isLocked(DateTimeImmutable $date): bool
    {
        $period = RosterPeriod::forDate($date, false);

        return $period !== null && $period->isLocked();
    }

    /**
     * Approved, not-a-wish absence of the member on the date (blocks planning).
     */
    public function blockingAbsence(int $memberId, DateTimeImmutable $date): ?Absence
    {
        $ymd = $date->format('Y-m-d');
        foreach (Absence::get()->filter([
            'MemberID' => $memberId,
            'Status' => Absence::STATUS_APPROVED,
            'DateFrom:LessThanOrEqual' => $ymd,
            'DateTo:GreaterThanOrEqual' => $ymd,
        ]) as $absence) {
            if (!$absence->Type()->IsPreference) {
                return $absence;
            }
        }

        return null;
    }

    private function checkAbsences(Member $member, DateTimeImmutable $date, ValidationReport $report): void
    {
        $ymd = $date->format('Y-m-d');
        $absences = Absence::get()->filter([
            'MemberID' => (int) $member->ID,
            'Status' => [Absence::STATUS_APPROVED, Absence::STATUS_PENDING],
            'DateFrom:LessThanOrEqual' => $ymd,
            'DateTo:GreaterThanOrEqual' => $ymd,
        ]);

        foreach ($absences as $absence) {
            $type = $absence->Type();
            $params = ['name' => $member->getName(), 'type' => $type->Title, 'range' => $absence->getRangeLabel()];
            if ($type->IsPreference) {
                $report->warn(_t(__CLASS__ . '.WISH', '{name} želi slobodan dan ({range}).', $params));
            } elseif ($absence->isApproved()) {
                $report->block(_t(__CLASS__ . '.ABSENT', '{name} je odsutan/na: {type}, {range}.', $params));
            } else {
                $report->warn(_t(__CLASS__ . '.PENDING_ABSENCE', '{name} ima zahtjev na čekanju: {type}, {range}.', $params));
            }
        }
    }

    private function message(Violation $violation, Member $member, DateTimeImmutable $date, string $code): string
    {
        $p = $violation->params + [
            'name' => $member->getName(),
            'date' => $date->format('d.m.'),
            'code' => $code,
        ];

        return match ($violation->code) {
            Violation::OVERLAP => _t(__CLASS__ . '.OVERLAP', '{name}: preklapanje sa smjenom {start}–{end}.', $p),
            Violation::MAX_SHIFT => _t(__CLASS__ . '.MAX_SHIFT', '{name}, {date} {code}: smjena traje {hours} h (max {max} h).', $p),
            Violation::DAILY_REST => ($p['side'] ?? '') === 'before'
                ? _t(__CLASS__ . '.DAILY_REST_BEFORE', '{name}, {date} {code}: samo {hours} h odmora nakon prethodne smjene (min {min} h).', $p)
                : _t(__CLASS__ . '.DAILY_REST_AFTER', '{name}, {date} {code}: samo {hours} h odmora do sljedeće smjene (min {min} h).', $p),
            Violation::WEEKLY_HOURS => _t(__CLASS__ . '.WEEKLY_HOURS', '{name}: {hours} h u sedmici {week} (max {max} h).', $p),
            Violation::CONSECUTIVE_DAYS => _t(__CLASS__ . '.CONSECUTIVE', '{name}: {days} radnih dana zaredom (max {max}).', $p),
            Violation::WEEKLY_REST => _t(__CLASS__ . '.WEEKLY_REST', '{name}: najduži sedmični odmor {hours} h (min {min} h neprekidno).', $p),
            default => $violation->code,
        };
    }
}
