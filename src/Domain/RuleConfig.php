<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

/**
 * Working-time limits. A value of 0 disables the respective check.
 */
final class RuleConfig
{
    public function __construct(
        public readonly float $minDailyRestHours = 12.0,
        public readonly float $minWeeklyRestHours = 24.0,
        public readonly float $maxShiftHours = 12.0,
        public readonly float $maxWeeklyHours = 50.0,
        public readonly int $maxConsecutiveDays = 6,
    ) {
    }
}
