<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Domain;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Splits a shift into night / Sunday / holiday minutes by the actual clock time, i.e. a night
 * shift Saturday 19:00 → Sunday 07:00 has 7 Sunday hours.
 *
 * Lengths use real elapsed time, so the DST night (23 or 25 h day) is counted correctly.
 * The unpaid break is not located in time, so every category is reduced proportionally
 * (category × work / duration). Rounded to whole minutes.
 */
final class HoursCalculator
{
    private readonly int $nightStart;

    private readonly int $nightEnd;

    /** @var Closure(DateTimeImmutable):bool */
    private readonly Closure $isHoliday;

    /**
     * @param string $nightStart "HH:MM", e.g. "22:00"
     * @param string $nightEnd   "HH:MM", e.g. "06:00" (may be before start = wraps midnight)
     * @param callable(DateTimeImmutable):bool $isHoliday
     */
    public function __construct(string $nightStart, string $nightEnd, callable $isHoliday)
    {
        $this->nightStart = self::minuteOfDay($nightStart);
        $this->nightEnd = self::minuteOfDay($nightEnd);
        $this->isHoliday = Closure::fromCallable($isHoliday);
    }

    public function breakdown(PlannedShift $shift): HoursBreakdown
    {
        $night = 0;
        $sunday = 0;
        $holiday = 0;

        $startTs = $shift->start->getTimestamp();
        $endTs = $shift->end->getTimestamp();

        $dayStart = $shift->start->setTime(0, 0);
        while ($dayStart->getTimestamp() < $endTs) {
            $nextDay = $dayStart->modify('+1 day');

            // real elapsed seconds of this calendar day covered by the shift (DST-safe)
            $segFrom = max($startTs, $dayStart->getTimestamp());
            $segTo = min($endTs, $nextDay->getTimestamp());
            $length = max(0, $segTo - $segFrom);

            if ((int) $dayStart->format('N') === 7) {
                $sunday += $length;
            }
            if (($this->isHoliday)($dayStart)) {
                $holiday += $length;
            }
            foreach ($this->nightWindows($dayStart, $nextDay) as [$ws, $we]) {
                $night += max(0, min($segTo, $we) - max($segFrom, $ws));
            }

            $dayStart = $nextDay;
        }

        $duration = $shift->durationMinutes();
        $work = $shift->workMinutes();
        $scale = static fn (int $seconds): int => (int) round($seconds / 60 * $work / $duration);

        return new HoursBreakdown($work, $scale($night), $scale($sunday), $scale($holiday));
    }

    /**
     * Night windows of one calendar day as [fromTs, toTs), built from wall-clock times.
     *
     * @return list<array{0:int,1:int}>
     */
    private function nightWindows(DateTimeImmutable $dayStart, DateTimeImmutable $nextDay): array
    {
        if ($this->nightStart === $this->nightEnd) {
            return [];
        }
        $at = static fn (int $minute): int => $minute >= 1440
            ? $nextDay->getTimestamp()
            : $dayStart->setTime(intdiv($minute, 60), $minute % 60)->getTimestamp();

        return $this->nightStart < $this->nightEnd
            ? [[$at($this->nightStart), $at($this->nightEnd)]]
            : [[$dayStart->getTimestamp(), $at($this->nightEnd)], [$at($this->nightStart), $nextDay->getTimestamp()]];
    }

    private static function minuteOfDay(string $time): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) || (int) $m[1] > 24 || (int) $m[2] > 59) {
            throw new InvalidArgumentException('Invalid time: ' . $time);
        }

        return min(1440, (int) $m[1] * 60 + (int) $m[2]);
    }
}
