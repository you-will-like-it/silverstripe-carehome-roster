<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

/**
 * Paid minutes of one or more shifts, split into allowance categories.
 * Categories overlap: a Sunday night on a holiday counts in night, sunday and holiday.
 */
final class HoursBreakdown
{
    public function __construct(
        public readonly int $work = 0,
        public readonly int $night = 0,
        public readonly int $sunday = 0,
        public readonly int $holiday = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            $this->work + $other->work,
            $this->night + $other->night,
            $this->sunday + $other->sunday,
            $this->holiday + $other->holiday,
        );
    }
}
