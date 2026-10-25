<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use DateTimeImmutable;

/**
 * Working-time checks for one candidate shift against the worker's other shifts.
 *
 * Conventions (documented in README):
 * - A shift belongs to the date and ISO week it starts in (weekly hours, consecutive days).
 * - Weekly rest: within the candidate's ISO week there must be at least one gap between two
 *   shifts (or before the first / after the last known shift) of minWeeklyRestHours. Unknown
 *   (not yet planned) time counts as free, so partially planned months do not warn.
 * - Overlap is always blocking; everything else is a warning.
 */
final class RuleEngine
{
    public function __construct(private readonly RuleConfig $config)
    {
    }

    /**
     * @param list<PlannedShift> $others the worker's other shifts (candidate excluded),
     *                                   at least ±14 days around the candidate
     * @return list<Violation>
     */
    public function check(PlannedShift $candidate, array $others): array
    {
        $violations = [];

        foreach ($others as $other) {
            if ($candidate->overlaps($other)) {
                $violations[] = new Violation(Violation::OVERLAP, Violation::BLOCK, [
                    'start' => $other->start->format('d.m. H:i'),
                    'end' => $other->end->format('d.m. H:i'),
                ]);

                // the remaining checks are meaningless with overlapping data
                return $violations;
            }
        }

        $c = $this->config;

        if ($c->maxShiftHours > 0 && $candidate->workMinutes() > $c->maxShiftHours * 60) {
            $violations[] = new Violation(Violation::MAX_SHIFT, Violation::WARN, [
                'hours' => self::fmt($candidate->workMinutes() / 60),
                'max' => self::fmt($c->maxShiftHours),
            ]);
        }

        if ($c->minDailyRestHours > 0) {
            array_push($violations, ...$this->dailyRest($candidate, $others));
        }

        if ($c->maxWeeklyHours > 0) {
            $week = self::isoWeekKey($candidate->start);
            $minutes = $candidate->workMinutes();
            foreach ($others as $other) {
                if (self::isoWeekKey($other->start) === $week) {
                    $minutes += $other->workMinutes();
                }
            }
            if ($minutes > $c->maxWeeklyHours * 60) {
                $violations[] = new Violation(Violation::WEEKLY_HOURS, Violation::WARN, [
                    'hours' => self::fmt($minutes / 60),
                    'max' => self::fmt($c->maxWeeklyHours),
                    'week' => $week,
                ]);
            }
        }

        if ($c->maxConsecutiveDays > 0) {
            $run = $this->consecutiveRun($candidate, $others);
            if ($run > $c->maxConsecutiveDays) {
                $violations[] = new Violation(Violation::CONSECUTIVE_DAYS, Violation::WARN, [
                    'days' => $run,
                    'max' => $c->maxConsecutiveDays,
                ]);
            }
        }

        if ($c->minWeeklyRestHours > 0) {
            $longest = $this->longestRestInWeek($candidate, $others);
            if ($longest !== null && $longest < $c->minWeeklyRestHours * 60) {
                $violations[] = new Violation(Violation::WEEKLY_REST, Violation::WARN, [
                    'hours' => self::fmt($longest / 60),
                    'min' => self::fmt($c->minWeeklyRestHours),
                ]);
            }
        }

        return $violations;
    }

    /**
     * @param list<PlannedShift> $others
     * @return list<Violation>
     */
    private function dailyRest(PlannedShift $candidate, array $others): array
    {
        $previous = null;
        $next = null;
        foreach ($others as $other) {
            if ($other->end <= $candidate->start && (!$previous || $other->end > $previous->end)) {
                $previous = $other;
            }
            if ($other->start >= $candidate->end && (!$next || $other->start < $next->start)) {
                $next = $other;
            }
        }

        $min = $this->config->minDailyRestHours * 60;
        $violations = [];
        foreach ([[$previous, 'before'], [$next, 'after']] as [$neighbour, $side]) {
            if (!$neighbour) {
                continue;
            }
            $gap = $side === 'before'
                ? self::minutesBetween($neighbour->end, $candidate->start)
                : self::minutesBetween($candidate->end, $neighbour->start);
            if ($gap < $min) {
                $violations[] = new Violation(Violation::DAILY_REST, Violation::WARN, [
                    'hours' => self::fmt($gap / 60),
                    'min' => self::fmt($this->config->minDailyRestHours),
                    'side' => $side,
                ]);
            }
        }

        return $violations;
    }

    /**
     * @param list<PlannedShift> $others
     */
    private function consecutiveRun(PlannedShift $candidate, array $others): int
    {
        $days = [$candidate->dateKey() => true];
        foreach ($others as $other) {
            $days[$other->dateKey()] = true;
        }

        $run = 1;
        foreach (['-1 day', '+1 day'] as $step) {
            $day = $candidate->start->setTime(0, 0)->modify($step);
            while (isset($days[$day->format('Y-m-d')])) {
                $run++;
                $day = $day->modify($step);
            }
        }

        return $run;
    }

    /**
     * Longest free period (minutes) touching the candidate's ISO week; null = unbounded
     * (no planned shift on one side), i.e. the requirement is certainly met.
     *
     * @param list<PlannedShift> $others
     */
    private function longestRestInWeek(PlannedShift $candidate, array $others): ?int
    {
        $weekStart = $candidate->start->setTime(0, 0)->modify('-' . ((int) $candidate->start->format('N') - 1) . ' days');
        $weekEnd = $weekStart->modify('+7 days');

        $shifts = [$candidate, ...$others];
        usort($shifts, static fn (PlannedShift $a, PlannedShift $b): int => $a->start <=> $b->start);

        $firstInWeek = null;
        $lastInWeek = null;
        foreach ($shifts as $i => $shift) {
            if ($shift->start < $weekEnd && $shift->end > $weekStart) {
                $firstInWeek ??= $i;
                $lastInWeek = $i;
            }
        }
        if ($firstInWeek === 0 || $lastInWeek === count($shifts) - 1) {
            return null;
        }

        $longest = 0;
        // gaps from the shift before the week's first shift to the one after the week's last
        $cursorEnd = $shifts[$firstInWeek - 1]->end;
        for ($i = $firstInWeek; $i <= $lastInWeek + 1; $i++) {
            $shift = $shifts[$i];
            if ($shift->start > $cursorEnd) {
                $gapStart = $cursorEnd;
                $gapEnd = $shift->start;
                if ($gapStart < $weekEnd && $gapEnd > $weekStart) {
                    $longest = max($longest, self::minutesBetween($gapStart, $gapEnd));
                }
            }
            if ($shift->end > $cursorEnd) {
                $cursorEnd = $shift->end;
            }
        }

        return $longest;
    }

    public static function isoWeekKey(DateTimeImmutable $date): string
    {
        return $date->format('o-\WW');
    }

    private static function minutesBetween(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
    }

    private static function fmt(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',');
    }
}
