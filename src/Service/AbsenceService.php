<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use RuntimeException;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Admin\RosterAdmin;
use YouWillLikeIT\CareHome\Roster\Control\Admin\PlanBoardController;
use YouWillLikeIT\CareHome\Roster\Control\Portal\RosterPortalController;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Absence rules and side effects.
 *
 * Approved (and not a wish) → every planned shift of the worker in the range becomes an
 * open slot (AbsenceID set) and planners are notified to assign a substitute.
 */
class AbsenceService
{
    use Injectable;
    use Configurable;

    /** Longest single absence/request. */
    private static int $max_days = 366;

    /** How far back a worker may report sick leave in the portal. */
    private static int $sick_backdate_days = 3;

    public function __construct(
        private readonly PlanningService $planning,
        private readonly RosterNotifier $notifier,
        private readonly LeaveBalanceService $balances,
    ) {
    }

    /* ============================================================ portal */

    /**
     * Request by the worker (portal). Becomes Approved immediately when the type needs no approval.
     *
     * @throws RuntimeException with a user-facing message
     */
    public function requestByWorker(Member $worker, AbsenceType $type, DateTimeImmutable $from, DateTimeImmutable $to, string $note): Absence
    {
        if (!$type->IsActive || !$type->AllowSelfRequest) {
            throw new RuntimeException(_t(__CLASS__ . '.TYPE_NOT_ALLOWED', 'Ovu vrstu odsustva unosi administrator.'));
        }
        $today = Clock::singleton()->today();
        $earliest = $type->IsSickLeave
            ? $today->modify('-' . (int) static::config()->get('sick_backdate_days') . ' days')
            : $today;
        if ($from < $earliest) {
            throw new RuntimeException($type->IsSickLeave
                ? _t(__CLASS__ . '.SICK_TOO_OLD', 'Bolovanje se može prijaviti najviše {days} dana unazad.', ['days' => (int) static::config()->get('sick_backdate_days')])
                : _t(__CLASS__ . '.PAST', 'Zahtjev ne može početi u prošlosti.'));
        }

        $absence = Absence::create([
            'MemberID' => (int) $worker->ID,
            'TypeID' => (int) $type->ID,
            'DateFrom' => $from->format('Y-m-d'),
            'DateTo' => $to->format('Y-m-d'),
            'Source' => Absence::SOURCE_PORTAL,
            'Status' => $type->RequiresApproval ? Absence::STATUS_PENDING : Absence::STATUS_APPROVED,
            'WorkerNote' => mb_substr(trim($note), 0, 2000) ?: null,
            'CreatedByID' => (int) $worker->ID,
        ]);
        // ValidationException carries the message for the portal
        $absence->write();

        return $absence;
    }

    /**
     * Worker withdraws a request that is still pending.
     *
     * @throws RuntimeException
     */
    public function cancelByWorker(Absence $absence, Member $worker): void
    {
        if ((int) $absence->MemberID !== (int) $worker->ID || !$absence->isPending()) {
            throw new RuntimeException(_t(__CLASS__ . '.CANNOT_CANCEL', 'Može se povući samo vlastiti zahtjev na čekanju.'));
        }
        $absence->Status = Absence::STATUS_CANCELLED;
        $absence->DecisionNote = _t(__CLASS__ . '.WITHDRAWN', 'Radnik je povukao zahtjev.');
        $absence->write();
    }

    /* ======================================================== validation */

    public function validate(Absence $absence, ValidationResult $result): void
    {
        $clock = Clock::singleton();
        $from = $absence->from();
        $to = $absence->to();

        if (!$absence->MemberID) {
            $result->addFieldError('MemberID', _t(__CLASS__ . '.MEMBER_REQUIRED', 'Radnik je obavezan.'));
        }
        if (!$absence->TypeID || !$absence->Type()->exists()) {
            $result->addFieldError('TypeID', _t(__CLASS__ . '.TYPE_REQUIRED', 'Vrsta odsustva je obavezna.'));

            return;
        }
        if (!$from || !$to) {
            $result->addError(_t(__CLASS__ . '.DATES_REQUIRED', 'Unesite period (od – do).'));

            return;
        }
        if ($to < $from) {
            $result->addFieldError('DateTo', _t(__CLASS__ . '.TO_BEFORE_FROM', 'Datum „do" je prije datuma „od".'));

            return;
        }
        if ($from->diff($to)->days + 1 > (int) static::config()->get('max_days')) {
            $result->addFieldError('DateTo', _t(__CLASS__ . '.TOO_LONG', 'Period je predug.'));

            return;
        }
        if (!array_key_exists((string) $absence->Status, Absence::statusOptions())) {
            $result->addFieldError('Status', _t(__CLASS__ . '.STATUS_INVALID', 'Nepoznat status.'));
        }

        $type = $absence->Type();
        $active = in_array($absence->Status, [Absence::STATUS_PENDING, Absence::STATUS_APPROVED], true);
        if (!$active) {
            return;
        }

        $member = $absence->Member();
        if ($member->exists() && !$member->isEmployedBetween($from, $to)) {
            $result->addError(_t(__CLASS__ . '.NOT_EMPLOYED', 'Radnik nije zaposlen u tom periodu.'));
        }

        $overlap = Absence::get()
            ->filter([
                'MemberID' => (int) $absence->MemberID,
                'Status' => [Absence::STATUS_PENDING, Absence::STATUS_APPROVED],
                'DateFrom:LessThanOrEqual' => $to->format('Y-m-d'),
                'DateTo:GreaterThanOrEqual' => $from->format('Y-m-d'),
            ])
            ->exclude('ID', (int) $absence->ID);
        foreach ($overlap as $other) {
            // a wish may sit inside a real absence, two real absences may not overlap
            if ((bool) $other->Type()->IsPreference === (bool) $type->IsPreference) {
                $result->addError(_t(__CLASS__ . '.OVERLAP', 'Preklapa se sa: {other}.', ['other' => $other->getTitle()]));
                break;
            }
        }

        $becomingApproved = $absence->Status === Absence::STATUS_APPROVED
            && (!$absence->isInDB() || $absence->isChanged('Status') || $absence->isChanged('DateFrom') || $absence->isChanged('DateTo') || $absence->isChanged('TypeID'));
        if (!$becomingApproved) {
            return;
        }

        if (!$type->IsPreference && $this->touchesLockedPeriod($absence)) {
            $result->addError(_t(__CLASS__ . '.LOCKED', 'Period obuhvata zaključan mjesec; prvo ga otključajte.'));
        }

        if ($type->DeductsAnnualLeave && $member->exists()) {
            for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
                $this->balances->reset();
                $balance = $this->balances->balance($member, $year);
                $needed = $this->balances->daysInYear($absence, $year);
                // an absence that is already approved in the database is inside "used"
                $alreadyCounted = $absence->isInDB() ? $this->storedDaysInYear($absence, $year) : 0;
                $available = $balance->remaining() + $alreadyCounted;
                if ($needed > $available) {
                    $result->addError(_t(
                        __CLASS__ . '.NO_BALANCE',
                        'Nedovoljno dana godišnjeg odmora za {year}: preostalo {available}, potrebno {needed}.',
                        ['year' => $year, 'available' => $available, 'needed' => $needed]
                    ));
                }
            }
        }
    }

    public function touchesLockedPeriod(Absence $absence): bool
    {
        $from = $absence->from();
        $to = $absence->to();
        if (!$from || !$to) {
            return false;
        }
        for ($month = $from->modify('first day of this month'); $month <= $to; $month = $month->modify('+1 month')) {
            $period = RosterPeriod::forDate($month, false);
            if ($period && $period->isLocked()) {
                return true;
            }
        }

        return false;
    }

    public function leaveDays(Absence $absence): int
    {
        $from = $absence->from();
        $to = $absence->to();
        if (!$from || !$to || $to < $from || !$absence->TypeID || !$absence->Type()->DeductsAnnualLeave) {
            return 0;
        }

        return $this->balances->countDays($from, $to);
    }

    /* ======================================================= side effects */

    /**
     * Called from Absence::onAfterWrite when status, dates or type changed.
     */
    public function afterWrite(Absence $absence, ?string $statusBefore, bool $statusChanged): void
    {
        $this->balances->reset();
        $type = $absence->Type();
        $member = $absence->Member();
        $label = $absence->getTitle();

        $this->planning->transaction(function () use ($absence, $statusBefore, $statusChanged, $type, $member, $label): void {
            if ($absence->isApproved() && !$type->IsPreference) {
                $opened = $this->openShifts($absence);
                if ($opened) {
                    $first = $opened[0]->dateObject();
                    $this->notifier->notifyPlanners(
                        _t(__CLASS__ . '.N_OPENED', 'Potrebna zamjena: {count} smjena', ['count' => count($opened)]),
                        _t(__CLASS__ . '.N_OPENED_BODY', '{absence}. Smjene su otvorene i čekaju zamjenu.', ['absence' => $label]),
                        PlanBoardController::monthLink((int) $first->format('Y'), (int) $first->format('n'))
                    );
                }
            }

            if (!$statusChanged) {
                return;
            }

            $workerLink = RosterPortalController::homeLink();
            if ($absence->Status === Absence::STATUS_PENDING) {
                $this->notifier->notifyPlanners(
                    _t(__CLASS__ . '.N_REQUEST', 'Novi zahtjev: {type}', ['type' => $type->Title]),
                    $label . ($absence->WorkerNote ? ' – ' . $absence->WorkerNote : ''),
                    $this->cmsLink()
                );
            } elseif ($absence->Status === Absence::STATUS_APPROVED) {
                if ($absence->Source === Absence::SOURCE_PORTAL && $statusBefore === Absence::STATUS_PENDING) {
                    $this->notifier->notify($member, _t(__CLASS__ . '.N_APPROVED', 'Zahtjev odobren: {type}', ['type' => $type->Title]), $label . $this->noteSuffix($absence), $workerLink);
                } elseif ($absence->Source === Absence::SOURCE_PORTAL && $statusBefore === null) {
                    // effective immediately (e.g. sick leave) → planners must know
                    $this->notifier->notifyPlanners(_t(__CLASS__ . '.N_REPORTED', 'Prijavljeno: {type}', ['type' => $type->Title]), $label, $this->cmsLink());
                } elseif ($absence->Source === Absence::SOURCE_CMS) {
                    $this->notifier->notify($member, _t(__CLASS__ . '.N_ENTERED', 'Upisano odsustvo: {type}', ['type' => $type->Title]), $label . $this->noteSuffix($absence), $workerLink);
                }
            } elseif ($absence->Status === Absence::STATUS_REJECTED) {
                $this->notifier->notify($member, _t(__CLASS__ . '.N_REJECTED', 'Zahtjev odbijen: {type}', ['type' => $type->Title]), $label . $this->noteSuffix($absence), $workerLink);
            } elseif ($absence->Status === Absence::STATUS_CANCELLED && $statusBefore === Absence::STATUS_APPROVED) {
                $this->notifier->notify($member, _t(__CLASS__ . '.N_CANCELLED', 'Odsustvo otkazano: {type}', ['type' => $type->Title]), $label . $this->noteSuffix($absence), $workerLink);
                $this->notifier->notifyPlanners(
                    _t(__CLASS__ . '.N_CANCELLED', 'Odsustvo otkazano: {type}', ['type' => $type->Title]),
                    _t(__CLASS__ . '.N_CANCELLED_PLANNER', '{absence}. Otvorene smjene nisu automatski vraćene radniku.', ['absence' => $label]),
                    $this->cmsLink()
                );
            }
        });
    }

    /**
     * @return list<ShiftAssignment> shifts that were opened
     */
    private function openShifts(Absence $absence): array
    {
        $reason = _t(__CLASS__ . '.OPEN_REASON', 'Odsustvo: {type} ({name})', [
            'type' => $absence->Type()->Title,
            'name' => $absence->Member()->getName(),
        ]);

        $opened = [];
        $assignments = ShiftAssignment::get()->filter([
            'MemberID' => (int) $absence->MemberID,
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'Date:GreaterThanOrEqual' => (string) $absence->DateFrom,
            'Date:LessThanOrEqual' => (string) $absence->DateTo,
        ]);
        foreach ($assignments as $assignment) {
            if ($this->isLockedDate($assignment)) {
                continue;
            }
            $this->planning->openInternal($assignment, $reason, (int) $absence->ID);
            $opened[] = $assignment;
        }

        return $opened;
    }

    /**
     * Info box for the CMS edit form.
     */
    public function describeImpact(Absence $absence): ?string
    {
        if (!$absence->isInDB() || !$absence->MemberID || !$absence->DateFrom || !$absence->DateTo) {
            return null;
        }
        $lines = [];

        if (!$absence->isApproved() && $absence->isPending() && !$absence->Type()->IsPreference) {
            $shifts = ShiftAssignment::get()->filter([
                'MemberID' => (int) $absence->MemberID,
                'Status' => ShiftAssignment::STATUS_PLANNED,
                'Date:GreaterThanOrEqual' => (string) $absence->DateFrom,
                'Date:LessThanOrEqual' => (string) $absence->DateTo,
            ]);
            if ($shifts->exists()) {
                $items = [];
                foreach ($shifts as $shift) {
                    $items[] = date('d.m.', strtotime((string) $shift->Date)) . ' ' . $shift->ShiftCode;
                }
                $lines[] = _t(__CLASS__ . '.IMPACT_SHIFTS', 'Odobravanjem se otvara {count} smjena za zamjenu: {list}', [
                    'count' => count($items),
                    'list' => implode(', ', $items),
                ]);
            }
        }

        if ($absence->Type()->DeductsAnnualLeave) {
            $from = $absence->from();
            $balance = $this->balances->balance($absence->Member(), (int) $from->format('Y'));
            $lines[] = _t(__CLASS__ . '.IMPACT_LEAVE', 'Godišnji odmor {year}: preostalo {remaining} dana, ovaj zahtjev {days} dana.', [
                'year' => $from->format('Y'),
                'remaining' => $balance->remaining(),
                'days' => (int) $absence->LeaveDays,
            ]);
        }

        return $lines ? implode('<br>', array_map('htmlspecialchars', $lines)) : null;
    }

    /* ============================================================ helpers */

    private function isLockedDate(ShiftAssignment $assignment): bool
    {
        $period = RosterPeriod::forDate($assignment->dateObject(), false);

        return $period !== null && $period->isLocked();
    }

    private function storedDaysInYear(Absence $absence, int $year): int
    {
        $stored = Absence::get()->byID((int) $absence->ID);

        return $stored && $stored->isApproved() ? $this->balances->daysInYear($stored, $year) : 0;
    }

    private function noteSuffix(Absence $absence): string
    {
        return $absence->DecisionNote ? ' – ' . $absence->DecisionNote : '';
    }

    private function cmsLink(): string
    {
        return Controller::join_links(AdminRootController::admin_url(), RosterAdmin::config()->get('url_segment'));
    }
}
