<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Expands a rotation such as 12-24-12-48 = ["D12", "N12", null, null] over a date range.
 * The anchor is the date on which step 0 applies; dates before the anchor continue the cycle
 * backwards, so the same anchor gives the same rhythm in every month.
 */
final class PatternExpander
{
    /**
     * @param list<string|null> $steps shift codes, null = free day
     * @return array<string,string|null> Y-m-d => code|null (every date in range)
     */
    public static function expand(array $steps, DateTimeImmutable $anchor, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $length = count($steps);
        if ($length === 0) {
            throw new InvalidArgumentException('Pattern has no steps.');
        }

        $anchorDay = self::dayNumber($anchor);
        $result = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $offset = (self::dayNumber($day) - $anchorDay) % $length;
            if ($offset < 0) {
                $offset += $length;
            }
            $result[$day->format('Y-m-d')] = $steps[$offset];
        }

        return $result;
    }

    /**
     * Parses "D12, N12, -, -" (comma/space separated, "-" or "0" = free).
     *
     * @return list<string|null>
     */
    public static function parse(string $pattern): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($pattern), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(static fn (string $p): ?string => in_array($p, ['-', '0', '_'], true) ? null : $p, $parts);
    }

    /** Days since epoch for the calendar date (DST-independent). */
    private static function dayNumber(DateTimeImmutable $date): int
    {
        $utc = new DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));

        return intdiv($utc->getTimestamp(), 86400);
    }
}
