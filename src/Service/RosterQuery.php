<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Read-only queries on the published plan for the worker portal.
 * Draft months are invisible to workers.
 */
class RosterQuery
{
    use Injectable;
    use Configurable;

    /** Handover: next shift starting within ± this many minutes of "now / current shift end". */
    private static int $handover_window_minutes = 90;

    public function __construct(private readonly WorkerDirectory $directory)
    {
    }

    /**
     * Planned shifts running at the given moment, optionally restricted to workers covering
     * one of the zones (zone IDs incl. descendants, null = all).
     *
     * @param list<int>|null $zoneIds
     * @return list<ShiftAssignment>
     */
    public function onShiftAt(DateTimeImmutable $at, ?array $zoneIds = null): array
    {
        $clock = Clock::singleton();
        $list = ShiftAssignment::get()->filter([
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'StartsAt:LessThanOrEqual' => $clock->toDbDatetime($at),
            'EndsAt:GreaterThan' => $clock->toDbDatetime($at),
        ])->sort(['StartsAt' => 'ASC']);

        return $this->filterVisible($list->toArray(), $zoneIds);
    }

    /**
     * Shifts starting in [from, to) — e.g. the next shift for the handover receiver list.
     *
     * @param list<int>|null $zoneIds
     * @return list<ShiftAssignment>
     */
    public function startingBetween(DateTimeImmutable $from, DateTimeImmutable $to, ?array $zoneIds = null): array
    {
        $clock = Clock::singleton();
        $list = ShiftAssignment::get()->filter([
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'StartsAt:GreaterThanOrEqual' => $clock->toDbDatetime($from),
            'StartsAt:LessThan' => $clock->toDbDatetime($to),
        ])->sort(['StartsAt' => 'ASC']);

        return $this->filterVisible($list->toArray(), $zoneIds);
    }

    /**
     * Member IDs of the next shift around $shiftEnd (handover receivers).
     *
     * @param list<int>|null $zoneIds
     * @return list<int>
     */
    public function nextShiftMemberIds(DateTimeImmutable $shiftEnd, ?array $zoneIds = null): array
    {
        $window = (int) static::config()->get('handover_window_minutes');
        $assignments = $this->startingBetween(
            $shiftEnd->modify('-' . $window . ' minutes'),
            $shiftEnd->modify('+' . $window . ' minutes'),
            $zoneIds
        );

        return array_values(array_unique(array_map(static fn (ShiftAssignment $a): int => (int) $a->MemberID, $assignments)));
    }

    /**
     * Upcoming shifts of one worker (published months only).
     *
     * @return list<ShiftAssignment>
     */
    public function upcomingFor(Member $member, int $limit = 10): array
    {
        $clock = Clock::singleton();
        $list = ShiftAssignment::get()->filter([
            'MemberID' => (int) $member->ID,
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'EndsAt:GreaterThan' => $clock->toDbDatetime($clock->now()),
        ])->sort(['StartsAt' => 'ASC'])->limit($limit * 2);

        return array_slice($this->filterVisible($list->toArray(), null), 0, $limit);
    }

    public function isVisibleToWorkers(DateTimeImmutable $date): bool
    {
        $period = RosterPeriod::forDate($date, false);

        return $period !== null && !$period->isDraft();
    }

    /**
     * @param list<ShiftAssignment> $assignments
     * @param list<int>|null        $zoneIds
     * @return list<ShiftAssignment>
     */
    private function filterVisible(array $assignments, ?array $zoneIds): array
    {
        $visibleMonths = [];
        $result = [];
        foreach ($assignments as $assignment) {
            $key = substr((string) $assignment->Date, 0, 7);
            $visibleMonths[$key] ??= $this->isVisibleToWorkers($assignment->dateObject());
            if (!$visibleMonths[$key]) {
                continue;
            }
            if ($zoneIds !== null) {
                $covered = $this->directory->coveredZoneIds($assignment->Member());
                if ($covered !== null && !array_intersect($covered, $zoneIds)) {
                    continue;
                }
            }
            $result[] = $assignment;
        }

        return $result;
    }
}
