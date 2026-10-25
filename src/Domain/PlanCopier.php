<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Copies a plan forward by a fixed day offset.
 *
 * Month copy uses 28 days: weekdays stay aligned and 4-day rotations (12-24-12-48) keep their
 * rhythm. Target days whose source lies inside the target range itself (the 29th–31st) take the
 * value just produced for that source day, so the pattern simply continues.
 */
final class PlanCopier
{
    public const int MONTH_OFFSET = 28;
    public const int WEEK_OFFSET = 7;

    /**
     * @template T
     * @param array<string,list<T>> $source Y-m-d => entries (dates before $from)
     * @return array<string,list<T>> Y-m-d => entries for every day in [$from, $to] (empty list = free)
     */
    public static function copy(array $source, DateTimeImmutable $from, DateTimeImmutable $to, int $offsetDays): array
    {
        if ($offsetDays < 1) {
            throw new InvalidArgumentException('Offset must be positive.');
        }

        $result = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $sourceKey = $day->modify('-' . $offsetDays . ' days')->format('Y-m-d');
            $result[$day->format('Y-m-d')] = $result[$sourceKey] ?? $source[$sourceKey] ?? [];
        }

        return $result;
    }
}
