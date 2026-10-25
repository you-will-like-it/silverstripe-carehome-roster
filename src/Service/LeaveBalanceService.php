<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Domain\LeaveBalance;
use YouWillLikeIT\CareHome\Roster\Domain\LeaveDayCounter;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\LeaveEntitlement;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Annual leave balance per worker and year, computed from approved/pending absences of types
 * that deduct annual leave. Absences spanning New Year count into each year separately.
 */
class LeaveBalanceService
{
    use Injectable;

    /** @var array<string,LeaveBalance> */
    private array $cache = [];

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Entitlement record, created on first use with the contract default and the
     * unused regular days of the previous year as carry-over.
     */
    public function entitlementFor(Member $member, int $year, bool $create = true): ?LeaveEntitlement
    {
        /** @var LeaveEntitlement|null $entitlement */
        $entitlement = LeaveEntitlement::get()->filter(['MemberID' => (int) $member->ID, 'Year' => $year])->first();
        if ($entitlement || !$create || !$member->isRosterCandidate()) {
            return $entitlement;
        }

        $carry = 0;
        $previous = LeaveEntitlement::get()->filter(['MemberID' => (int) $member->ID, 'Year' => $year - 1])->first();
        if ($previous) {
            $carry = max(0, $this->compute($previous)->regularRemaining);
        }

        $entitlement = LeaveEntitlement::create([
            'MemberID' => (int) $member->ID,
            'Year' => $year,
            'Days' => $member->contractLeaveDays(),
            'CarriedOver' => $carry,
        ]);
        $entitlement->write();

        return $entitlement;
    }

    public function balance(Member $member, int $year): LeaveBalance
    {
        $entitlement = $this->entitlementFor($member, $year);
        if (!$entitlement) {
            return new LeaveBalance(0, 0, 0, 0, false);
        }

        return $this->balanceFor($entitlement);
    }

    public function balanceFor(LeaveEntitlement $entitlement): LeaveBalance
    {
        $key = $entitlement->MemberID . '-' . $entitlement->Year;

        return $this->cache[$key] ??= $this->compute($entitlement);
    }

    /**
     * Days an absence would deduct within one year.
     */
    public function daysInYear(Absence $absence, int $year): int
    {
        $from = $absence->from();
        $to = $absence->to();
        if (!$from || !$to || !$absence->Type()->DeductsAnnualLeave) {
            return 0;
        }
        $clock = Clock::singleton();
        $clip = LeaveDayCounter::clip($from, $to, $clock->combine($year . '-01-01', '00:00'), $clock->combine($year . '-12-31', '00:00'));

        return $clip ? $this->countDays($clip[0], $clip[1]) : 0;
    }

    public function countDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return LeaveDayCounter::count(
            $from,
            $to,
            RosterSettings::current()->leaveWorkdays(),
            HolidayCalendar::singleton()->predicate()
        );
    }

    private function compute(LeaveEntitlement $entitlement): LeaveBalance
    {
        $year = (int) $entitlement->Year;
        $clock = Clock::singleton();
        $yearStart = $clock->combine($year . '-01-01', '00:00');
        $yearEnd = $clock->combine($year . '-12-31', '00:00');

        $deadlineMd = RosterSettings::current()->carryOverDeadline();
        $deadline = $deadlineMd ? $clock->parseDate($year . '-' . $deadlineMd) : null;
        $deadlinePassed = $deadline !== null && $clock->today() > $deadline;

        $until = 0;
        $after = 0;
        $pending = 0;

        $absences = Absence::get()->filter([
            'MemberID' => (int) $entitlement->MemberID,
            'Status' => [Absence::STATUS_APPROVED, Absence::STATUS_PENDING],
            'Type.DeductsAnnualLeave' => true,
            'DateFrom:LessThanOrEqual' => $yearEnd->format('Y-m-d'),
            'DateTo:GreaterThanOrEqual' => $yearStart->format('Y-m-d'),
        ]);

        foreach ($absences as $absence) {
            $from = $absence->from();
            $to = $absence->to();
            if (!$from || !$to) {
                continue;
            }
            $clip = LeaveDayCounter::clip($from, $to, $yearStart, $yearEnd);
            if (!$clip) {
                continue;
            }
            if ($absence->isPending()) {
                $pending += $this->countDays($clip[0], $clip[1]);
                continue;
            }
            if ($deadline === null) {
                $until += $this->countDays($clip[0], $clip[1]);
                continue;
            }
            $before = LeaveDayCounter::clip($clip[0], $clip[1], $yearStart, $deadline);
            $later = LeaveDayCounter::clip($clip[0], $clip[1], $deadline->modify('+1 day'), $yearEnd);
            $until += $before ? $this->countDays($before[0], $before[1]) : 0;
            $after += $later ? $this->countDays($later[0], $later[1]) : 0;
        }

        return new LeaveBalance(
            (int) $entitlement->Days,
            (int) $entitlement->CarriedOver,
            $until,
            $after,
            $deadlinePassed,
            $pending
        );
    }
}
