<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

/**
 * Annual leave balance for one year.
 *
 * Carried-over days are consumed first and only by leave taken up to the carry-over deadline
 * (FBiH: 30 June). Whatever is left of them after the deadline expires.
 */
final class LeaveBalance
{
    public readonly int $carryUsed;

    public readonly int $carryRemaining;

    public readonly int $regularUsed;

    public readonly int $regularRemaining;

    public function __construct(
        public readonly int $entitlement,
        public readonly int $carriedOver,
        int $usedUntilDeadline,
        int $usedAfterDeadline,
        public readonly bool $deadlinePassed,
        public readonly int $pending = 0,
    ) {
        $this->carryUsed = min($carriedOver, $usedUntilDeadline);
        $this->carryRemaining = $deadlinePassed ? 0 : $carriedOver - $this->carryUsed;
        $this->regularUsed = $usedUntilDeadline - $this->carryUsed + $usedAfterDeadline;
        $this->regularRemaining = $entitlement - $this->regularUsed;
    }

    public function used(): int
    {
        return $this->carryUsed + $this->regularUsed;
    }

    /** Days still available (may be negative if over-booked by an administrator). */
    public function remaining(): int
    {
        return $this->regularRemaining + $this->carryRemaining;
    }

    /** Available after all pending requests would be approved. */
    public function remainingAfterPending(): int
    {
        return $this->remaining() - $this->pending;
    }
}
