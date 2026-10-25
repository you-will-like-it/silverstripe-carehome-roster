<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use Throwable;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Roster\Control\Portal\RosterPortalController;
use YouWillLikeIT\CareHome\Roster\Domain\PatternExpander;
use YouWillLikeIT\CareHome\Roster\Domain\PlanCopier;
use YouWillLikeIT\CareHome\Roster\Domain\PlannedShift;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftPattern;
use YouWillLikeIT\CareHome\Roster\Model\ShiftSwapRequest;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Service\AuditLogger;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Every change to the plan goes through here: validation, override reasons, period status,
 * "changed after publish" flag and notifications.
 */
class PlanningService
{
    use Injectable;

    public function __construct(
        private readonly AssignmentValidator $validator,
        private readonly RosterNotifier $notifier,
    ) {
    }

    /* ======================================================== single cell */

    /**
     * Sets the worker's shifts on one date to exactly the given shift types
     * (empty list = free day). Unchanged types are kept as they are.
     *
     * @param list<int> $typeIds
     */
    public function setCell(Member $member, DateTimeImmutable $date, array $typeIds, ?string $reason = null): PlanResult
    {
        $date = $date->setTime(0, 0);
        if ($this->validator->isLocked($date)) {
            return PlanResult::error($this->lockedMessage($date));
        }

        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        $existing = ShiftAssignment::get()->filter([
            'MemberID' => (int) $member->ID,
            'Date' => $date->format('Y-m-d'),
            'Status' => ShiftAssignment::STATUS_PLANNED,
        ])->toArray();

        $keep = [];
        $remove = [];
        foreach ($existing as $assignment) {
            if (in_array((int) $assignment->ShiftTypeID, $typeIds, true) && !isset($keep[(int) $assignment->ShiftTypeID])) {
                $keep[(int) $assignment->ShiftTypeID] = $assignment;
            } else {
                $remove[] = $assignment;
            }
        }

        $add = [];
        foreach ($typeIds as $typeId) {
            if (isset($keep[$typeId])) {
                continue;
            }
            $type = ShiftType::get()->byID($typeId);
            if (!$type) {
                return PlanResult::error(_t(__CLASS__ . '.TYPE_UNKNOWN', 'Nepoznata smjena.'));
            }
            $add[] = $type;
        }

        if (!$add && !$remove) {
            return PlanResult::saved();
        }

        $report = new ValidationReport();
        $ignore = array_map(static fn (ShiftAssignment $a): int => (int) $a->ID, $remove);
        $pending = [];
        foreach ($add as $type) {
            $report->merge($this->validator->check($member, $type, $date, $ignore, $pending));
            [$start, $end] = $type->windowFor($date);
            $pending[] = new PlannedShift(-count($pending) - 1, $start, $end, (int) $type->BreakMinutes, (string) $type->Code);
        }
        if ($add && !$report->allowsSave($reason)) {
            return PlanResult::rejected($report);
        }

        $this->transaction(function () use ($member, $date, $add, $remove, $report, $reason): void {
            foreach ($remove as $assignment) {
                $this->removeAssignment($assignment, _t(__CLASS__ . '.REMOVED_BY_PLANNER', 'uklonio planer'));
            }
            foreach ($add as $type) {
                $this->createAssignment($member, $type, $date, $report->hasWarnings() ? $reason : null);
            }
        });

        return PlanResult::saved($report);
    }

    /**
     * Assign a worker to an open shift, or replace the worker of a planned shift.
     * The first planned worker is kept in OriginalMember.
     */
    public function assignWorker(ShiftAssignment $assignment, Member $member, ?string $reason = null): PlanResult
    {
        if ($this->validator->isLocked($assignment->dateObject())) {
            return PlanResult::error($this->lockedMessage($assignment->dateObject()));
        }
        if (!$assignment->isOpen() && (int) $assignment->MemberID === (int) $member->ID) {
            return PlanResult::saved();
        }

        $report = $this->validator->checkAssignmentFor($member, $assignment, [(int) $assignment->ID]);
        if (!$report->allowsSave($reason)) {
            return PlanResult::rejected($report);
        }

        $this->transaction(function () use ($assignment, $member, $report, $reason): void {
            $this->transfer($assignment, $member, $report->hasWarnings() ? $reason : null);
        });

        return PlanResult::saved($report);
    }

    /**
     * Moves an (already validated) assignment to a worker; used by assignWorker and swaps.
     */
    public function transfer(ShiftAssignment $assignment, Member $member, ?string $overrideReason): void
    {
        $previousId = $assignment->isOpen() ? 0 : (int) $assignment->MemberID;
        $this->cancelSwapsFor($assignment, _t(__CLASS__ . '.SWAP_OBSOLETE', 'Smjena je u međuvremenu promijenjena.'));

        if (!$assignment->OriginalMemberID) {
            $assignment->OriginalMemberID = $previousId ?: (int) $member->ID;
        }
        $assignment->MemberID = (int) $member->ID;
        $assignment->Status = ShiftAssignment::STATUS_PLANNED;
        $assignment->OverrideReason = $overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null;
        $this->markChanged($assignment);
        $assignment->write();

        if ($this->isPublished($assignment)) {
            $this->notifyAssignment((int) $member->ID, $assignment, _t(__CLASS__ . '.N_ASSIGNED', 'Dodijeljena vam je smjena'));
            if ($previousId) {
                $this->notifyAssignment($previousId, $assignment, _t(__CLASS__ . '.N_TAKEN_OVER', 'Smjenu je preuzeo/la {name}', ['name' => $member->getName()]));
            }
        }
    }

    /**
     * Turns a planned shift into an open slot (e.g. sick worker reported by phone).
     */
    public function openAssignment(ShiftAssignment $assignment, string $why): PlanResult
    {
        if ($this->validator->isLocked($assignment->dateObject())) {
            return PlanResult::error($this->lockedMessage($assignment->dateObject()));
        }
        if ($assignment->isOpen()) {
            return PlanResult::saved();
        }
        if (trim($why) === '') {
            return PlanResult::error(_t(__CLASS__ . '.REASON_REQUIRED', 'Unesite razlog.'));
        }

        $this->transaction(function () use ($assignment, $why): void {
            $this->openInternal($assignment, $why);
        });

        return PlanResult::saved();
    }

    /** Creates an additional open slot (no worker yet). */
    public function addOpenShift(ShiftType $type, DateTimeImmutable $date, string $why = ''): PlanResult
    {
        $date = $date->setTime(0, 0);
        if ($this->validator->isLocked($date)) {
            return PlanResult::error($this->lockedMessage($date));
        }

        $this->transaction(function () use ($type, $date, $why): void {
            $assignment = ShiftAssignment::create();
            $assignment->applyShiftType($type, $date);
            $assignment->Status = ShiftAssignment::STATUS_OPEN;
            $assignment->OpenReason = mb_substr(trim($why) ?: _t(__CLASS__ . '.OPEN_EXTRA', 'dodatna smjena'), 0, 255);
            $this->markChanged($assignment);
            $assignment->write();
        });

        return PlanResult::saved();
    }

    public function deleteAssignment(ShiftAssignment $assignment): PlanResult
    {
        if ($this->validator->isLocked($assignment->dateObject())) {
            return PlanResult::error($this->lockedMessage($assignment->dateObject()));
        }
        $this->transaction(function () use ($assignment): void {
            $this->removeAssignment($assignment, _t(__CLASS__ . '.REMOVED_BY_PLANNER', 'uklonio planer'));
        });

        return PlanResult::saved();
    }

    /* ========================================================= batch fill */

    /**
     * Fills [from, to] with a rotation for each worker. Days that already have a shift are
     * skipped; days with blocking problems are skipped; days with warnings are skipped unless
     * a reason is given.
     *
     * @param list<Member> $members
     */
    public function applyPattern(
        array $members,
        ShiftPattern $pattern,
        DateTimeImmutable $anchor,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $reason = null,
    ): PlanResult {
        $steps = $pattern->steps();
        $types = [];
        foreach (ShiftType::get() as $type) {
            $types[(string) $type->Code] = $type;
        }
        foreach ($steps as $code) {
            if ($code !== null && !isset($types[$code])) {
                return PlanResult::error(_t(__CLASS__ . '.PATTERN_UNKNOWN', 'Obrazac sadrži nepoznatu smjenu {code}.', ['code' => $code]));
            }
        }
        if ($from > $to || $from->diff($to)->days > 92) {
            return PlanResult::error(_t(__CLASS__ . '.RANGE', 'Period mora biti između 1 i 93 dana.'));
        }

        $map = PatternExpander::expand($steps, $anchor, $from, $to);
        $wanted = [];
        foreach ($map as $ymd => $code) {
            if ($code !== null) {
                $wanted[$ymd] = [$types[$code]];
            }
        }

        return $this->fillDays($members, $wanted, $reason);
    }

    /**
     * Copies the previous month (28-day offset) or the previous week into the target range.
     * Existing shifts in the target are kept; open slots are not copied.
     *
     * @param list<Member> $members
     */
    public function copyPrevious(array $members, DateTimeImmutable $from, DateTimeImmutable $to, int $offsetDays, ?string $reason = null): PlanResult
    {
        if ($from > $to || $from->diff($to)->days > 62) {
            return PlanResult::error(_t(__CLASS__ . '.RANGE_COPY', 'Neispravan period za kopiranje.'));
        }

        $sourceFrom = $from->modify('-' . $offsetDays . ' days');
        $sourceTo = $from->modify('-1 day');
        $types = [];
        foreach (ShiftType::get() as $type) {
            $types[(int) $type->ID] = $type;
        }

        $result = PlanResult::saved();
        $created = 0;
        foreach ($members as $member) {
            $source = [];
            foreach (ShiftAssignment::get()->filter([
                'MemberID' => (int) $member->ID,
                'Status' => ShiftAssignment::STATUS_PLANNED,
                'Date:GreaterThanOrEqual' => $sourceFrom->format('Y-m-d'),
                'Date:LessThanOrEqual' => $sourceTo->format('Y-m-d'),
            ]) as $assignment) {
                if (isset($types[(int) $assignment->ShiftTypeID])) {
                    $source[(string) $assignment->Date][] = $types[(int) $assignment->ShiftTypeID];
                }
            }
            if (!$source) {
                continue;
            }
            $wanted = array_filter(PlanCopier::copy($source, $from, $to, $offsetDays));
            $partial = $this->fillDays([$member], $wanted, $reason);
            $created += (int) ($partial->info['created'] ?? 0);
            $result->report->merge($partial->report);
            foreach ($partial->info['skipped'] ?? [] as $line) {
                $result->info['skipped'][] = $line;
            }
        }
        $result->info['created'] = $created;

        return $result;
    }

    /**
     * @param list<Member>                     $members
     * @param array<string,list<ShiftType>>    $wanted Y-m-d => types
     */
    private function fillDays(array $members, array $wanted, ?string $reason): PlanResult
    {
        $clock = Clock::singleton();
        $result = PlanResult::saved();
        $created = 0;
        $skipped = [];

        foreach ($members as $member) {
            $occupied = [];
            if ($wanted) {
                $dates = array_keys($wanted);
                foreach (ShiftAssignment::get()->filter([
                    'MemberID' => (int) $member->ID,
                    'Status' => ShiftAssignment::STATUS_PLANNED,
                    'Date' => $dates,
                ])->column('Date') as $ymd) {
                    $occupied[(string) $ymd] = true;
                }
            }

            foreach ($wanted as $ymd => $types) {
                if (isset($occupied[$ymd])) {
                    continue;
                }
                $date = $clock->parseDate($ymd);
                if (!$date) {
                    continue;
                }
                foreach ($types as $type) {
                    $report = $this->validator->check($member, $type, $date);
                    if ($report->isBlocked() || ($report->hasWarnings() && trim((string) $reason) === '')) {
                        $skipped[] = sprintf('%s %s %s: %s', $member->getName(), $date->format('d.m.'), $type->Code, implode(' ', $report->messages()));
                        continue;
                    }
                    $this->transaction(function () use ($member, $type, $date, $report, $reason): void {
                        $this->createAssignment($member, $type, $date, $report->hasWarnings() ? $reason : null);
                    });
                    $created++;
                }
            }
        }

        $result->info['created'] = $created;
        $result->info['skipped'] = $skipped;

        return $result;
    }

    /* ===================================================== period status */

    public function publish(RosterPeriod $period, Member $actor): PlanResult
    {
        if (!$period->isDraft()) {
            return PlanResult::error(_t(__CLASS__ . '.NOT_DRAFT', 'Objaviti se može samo nacrt.'));
        }

        $this->transaction(function () use ($period, $actor): void {
            $period->Status = RosterPeriod::STATUS_PUBLISHED;
            $period->PublishedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
            $period->PublishedByID = (int) $actor->ID;
            $period->write();

            $memberIds = array_unique(array_map('intval', ShiftAssignment::get()
                ->filter(['PeriodID' => (int) $period->ID, 'Status' => ShiftAssignment::STATUS_PLANNED])
                ->column('MemberID')));
            $this->notifier->notifyMany(
                $memberIds,
                _t(__CLASS__ . '.N_PUBLISHED', 'Objavljen raspored za {month}', ['month' => $period->getLabel()]),
                _t(__CLASS__ . '.N_PUBLISHED_BODY', 'Vaš raspored smjena za {month} je objavljen.', ['month' => $period->getLabel()]),
                RosterPortalController::monthLink((int) $period->Year, (int) $period->Month)
            );
        });

        return PlanResult::saved();
    }

    public function lock(RosterPeriod $period, Member $actor): PlanResult
    {
        if (!$period->isPublished()) {
            return PlanResult::error(_t(__CLASS__ . '.NOT_PUBLISHED', 'Zaključati se može samo objavljen raspored.'));
        }
        $open = ShiftAssignment::get()->filter(['PeriodID' => (int) $period->ID, 'Status' => ShiftAssignment::STATUS_OPEN])->count();

        $this->transaction(function () use ($period, $actor): void {
            $period->Status = RosterPeriod::STATUS_LOCKED;
            $period->LockedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
            $period->LockedByID = (int) $actor->ID;
            $period->write();
        });

        $result = PlanResult::saved();
        if ($open) {
            $result->info[] = _t(__CLASS__ . '.LOCKED_OPEN', 'Napomena: {count} smjena je ostalo nepopunjeno.', ['count' => $open]);
        }

        return $result;
    }

    public function unlock(RosterPeriod $period, Member $actor, string $reason): PlanResult
    {
        if (!RosterPermissions::isSuperAdmin($actor)) {
            return PlanResult::error(_t(__CLASS__ . '.UNLOCK_SUPERADMIN', 'Otključati može samo superadmin.'));
        }
        if (!$period->isLocked()) {
            return PlanResult::error(_t(__CLASS__ . '.NOT_LOCKED', 'Raspored nije zaključan.'));
        }
        if (trim($reason) === '') {
            return PlanResult::error(_t(__CLASS__ . '.REASON_REQUIRED', 'Unesite razlog.'));
        }

        $this->transaction(function () use ($period, $actor, $reason): void {
            $period->Status = RosterPeriod::STATUS_PUBLISHED;
            $period->UnlockReason = trim($reason);
            $period->write();
            AuditLogger::singleton()->logEvent(
                AuditLogEntry::ACTION_EVENT,
                _t(__CLASS__ . '.AUDIT_UNLOCK', 'Raspored {month} otključan', ['month' => $period->getLabel()]),
                $actor,
                ['periodID' => (int) $period->ID, 'reason' => trim($reason)]
            );
        });

        return PlanResult::saved();
    }

    /* ========================================================== internals */

    public function createAssignment(Member $member, ShiftType $type, DateTimeImmutable $date, ?string $overrideReason): ShiftAssignment
    {
        $assignment = ShiftAssignment::create();
        $assignment->applyShiftType($type, $date);
        $assignment->MemberID = (int) $member->ID;
        $assignment->OriginalMemberID = (int) $member->ID;
        $assignment->Status = ShiftAssignment::STATUS_PLANNED;
        $assignment->OverrideReason = $overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null;
        $this->markChanged($assignment);
        $assignment->write();

        if ($this->isPublished($assignment)) {
            $this->notifyAssignment((int) $member->ID, $assignment, _t(__CLASS__ . '.N_ADDED', 'Nova smjena u rasporedu'));
        }

        return $assignment;
    }

    /**
     * Converts to open: the worker is removed, the first planned worker is kept.
     * Used by planners and by AbsenceService (approved absence).
     */
    public function openInternal(ShiftAssignment $assignment, string $why, int $absenceId = 0): void
    {
        $previousId = (int) $assignment->MemberID;
        $this->cancelSwapsFor($assignment, _t(__CLASS__ . '.SWAP_OBSOLETE', 'Smjena je u međuvremenu promijenjena.'));

        if (!$assignment->OriginalMemberID) {
            $assignment->OriginalMemberID = $previousId;
        }
        $assignment->MemberID = 0;
        $assignment->Status = ShiftAssignment::STATUS_OPEN;
        $assignment->OpenReason = mb_substr(trim($why), 0, 255);
        $assignment->AbsenceID = $absenceId;
        $this->markChanged($assignment);
        $assignment->write();

        if ($previousId && $this->isPublished($assignment)) {
            $this->notifyAssignment($previousId, $assignment, _t(__CLASS__ . '.N_RELEASED', 'Oslobođeni ste smjene'));
        }
    }

    private function removeAssignment(ShiftAssignment $assignment, string $why): void
    {
        $memberId = (int) $assignment->MemberID;
        $published = $this->isPublished($assignment);
        $label = $assignment->getTitle();
        $date = $assignment->dateObject();
        $this->cancelSwapsFor($assignment, _t(__CLASS__ . '.SWAP_REMOVED', 'Smjena je uklonjena iz rasporeda.'));
        $assignment->delete();

        if ($memberId && $published) {
            $this->notifier->notify(
                $memberId,
                _t(__CLASS__ . '.N_REMOVED', 'Smjena uklonjena iz rasporeda'),
                $label . ' (' . $why . ')',
                RosterPortalController::monthLink((int) $date->format('Y'), (int) $date->format('n'))
            );
        }
    }

    public function cancelSwapsFor(ShiftAssignment $assignment, string $why): void
    {
        if (!$assignment->isInDB()) {
            return;
        }
        $requests = ShiftSwapRequest::get()
            ->filterAny(['AssignmentID' => (int) $assignment->ID, 'CounterAssignmentID' => (int) $assignment->ID])
            ->filter('Status', [ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, ShiftSwapRequest::STATUS_AWAITING_APPROVAL]);
        foreach ($requests as $request) {
            $request->Status = ShiftSwapRequest::STATUS_CANCELLED;
            $request->DecisionNote = $why;
            // SwapService::afterStatusChange() notifies both workers
            $request->write();
        }
    }

    private function markChanged(ShiftAssignment $assignment): void
    {
        if ($this->isPublished($assignment)) {
            $assignment->ChangedAfterPublish = true;
        }
    }

    private function isPublished(ShiftAssignment $assignment): bool
    {
        $period = RosterPeriod::forDate($assignment->dateObject(), false);

        return $period !== null && !$period->isDraft();
    }

    private function notifyAssignment(int $memberId, ShiftAssignment $assignment, string $subject): void
    {
        $date = $assignment->dateObject();
        $this->notifier->notify(
            $memberId,
            $subject,
            sprintf('%s, %s %s (%s)', $date->format('d.m.Y.'), $assignment->ShiftCode, $assignment->getTimeRange(), $assignment->ShiftTitle),
            RosterPortalController::monthLink((int) $date->format('Y'), (int) $date->format('n'))
        );
    }

    private function lockedMessage(DateTimeImmutable $date): string
    {
        return _t(AssignmentValidator::class . '.LOCKED', 'Mjesec {month} je zaključan.', ['month' => $date->format('m/Y')]);
    }

    /**
     * @param callable():void $callback
     */
    public function transaction(callable $callback): void
    {
        $conn = DB::get_conn();
        $nested = $conn->transactionDepth() > 0;
        if (!$nested) {
            $conn->transactionStart();
        }
        try {
            $callback();
            if (!$nested) {
                $conn->transactionEnd();
            }
        } catch (Throwable $e) {
            if (!$nested) {
                $conn->transactionRollback();
            }
            throw $e;
        }
        if (!$nested) {
            $this->notifier->flush();
        }
    }
}
