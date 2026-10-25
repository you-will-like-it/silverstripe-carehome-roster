<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One concrete shift of one worker: absolute start/end plus unpaid break.
 * Framework-free so the rule engine and hour calculations are unit-testable.
 */
final class PlannedShift
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly int $breakMinutes = 0,
        public readonly string $code = '',
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('Shift end must be after its start.');
        }
        if ($breakMinutes < 0 || $breakMinutes >= $this->durationMinutes()) {
            throw new InvalidArgumentException('Break must be shorter than the shift.');
        }
    }

    public function durationMinutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    public function workMinutes(): int
    {
        return $this->durationMinutes() - $this->breakMinutes;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** Calendar date the shift belongs to (the day it starts). */
    public function dateKey(): string
    {
        return $this->start->format('Y-m-d');
    }
}
