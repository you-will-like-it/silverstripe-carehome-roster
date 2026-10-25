<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use DateTimeImmutable;

/**
 * Counts leave days: configured workdays (ISO 1–7) in a range, public holidays excluded
 * (holidays are not counted into annual leave).
 */
final class LeaveDayCounter
{
    /**
     * @param list<int> $workdays
     * @param callable(DateTimeImmutable):bool $isHoliday
     */
    public static function count(DateTimeImmutable $from, DateTimeImmutable $to, array $workdays, callable $isHoliday): int
    {
        $count = 0;
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            if (in_array((int) $day->format('N'), $workdays, true) && !$isHoliday($day)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Intersection of [$from, $to] with [$rangeFrom, $rangeTo], or null when disjoint.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null
     */
    public static function clip(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $rangeFrom,
        DateTimeImmutable $rangeTo,
    ): ?array {
        $start = $from > $rangeFrom ? $from : $rangeFrom;
        $end = $to < $rangeTo ? $to : $rangeTo;

        return $start <= $end ? [$start, $end] : null;
    }
}
