<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use YouWillLikeIT\CareHome\Roster\Domain\HoursCalculator;
use YouWillLikeIT\CareHome\Roster\Domain\LeaveBalance;
use YouWillLikeIT\CareHome\Roster\Domain\LeaveDayCounter;
use YouWillLikeIT\CareHome\Roster\Domain\PatternExpander;
use YouWillLikeIT\CareHome\Roster\Domain\PlanCopier;
use YouWillLikeIT\CareHome\Roster\Domain\PlannedShift;

class CalculationTest extends TestCase
{
    private static function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Europe/Sarajevo'));
    }

    public function testNightSundayHolidaySplit(): void
    {
        // Sat 31.10.2026 19:00 → Sun 1.11. 07:00; 1.11. treated as holiday
        $calc = new HoursCalculator('22:00', '06:00', static fn (DateTimeImmutable $d): bool => $d->format('m-d') === '11-01');
        $b = $calc->breakdown(new PlannedShift(1, self::at('2026-10-31 19:00'), self::at('2026-11-01 07:00')));
        $this->assertSame(720, $b->work);
        $this->assertSame(480, $b->night);
        $this->assertSame(420, $b->sunday);
        $this->assertSame(420, $b->holiday);
    }

    public function testBreakScalesCategories(): void
    {
        $calc = new HoursCalculator('22:00', '06:00', static fn (): bool => false);
        $b = $calc->breakdown(new PlannedShift(1, self::at('2026-11-02 22:00'), self::at('2026-11-03 06:00'), 60));
        $this->assertSame(420, $b->work);
        $this->assertSame(420, $b->night);
    }

    public function testDstNightCountsRealTime(): void
    {
        // 25.10.2026: clocks go back at 03:00 → night shift 22:00-06:00 lasts 9 h
        $calc = new HoursCalculator('22:00', '06:00', static fn (): bool => false);
        $b = $calc->breakdown(new PlannedShift(1, self::at('2026-10-24 22:00'), self::at('2026-10-25 06:00')));
        $this->assertSame(540, $b->work);
        $this->assertSame(540, $b->night);
        // 00:00–06:00 on Sunday is 7 real hours (02:00–03:00 happens twice)
        $this->assertSame(420, $b->sunday);
    }

    public function testPatternExpansionIsAnchored(): void
    {
        $steps = PatternExpander::parse('D12, N12, -, -');
        $this->assertSame(['D12', 'N12', null, null], $steps);
        $map = PatternExpander::expand($steps, self::at('2026-11-02'), self::at('2026-10-30'), self::at('2026-11-07'));
        $this->assertSame([
            '2026-10-30' => 'N12',
            '2026-10-31' => null,
            '2026-11-01' => null,
            '2026-11-02' => 'D12',
            '2026-11-03' => 'N12',
            '2026-11-04' => null,
            '2026-11-05' => null,
            '2026-11-06' => 'D12',
            '2026-11-07' => 'N12',
        ], $map);
    }

    public function testMonthCopyContinuesInsideTarget(): void
    {
        $source = ['2026-10-04' => ['A'], '2026-10-05' => ['B']];
        $result = PlanCopier::copy($source, self::at('2026-11-01'), self::at('2026-11-30'), PlanCopier::MONTH_OFFSET);
        $this->assertSame(['A'], $result['2026-11-01']);
        $this->assertSame(['B'], $result['2026-11-02']);
        $this->assertSame(['A'], $result['2026-11-29']);
        $this->assertSame(['B'], $result['2026-11-30']);
        $this->assertSame([], $result['2026-11-03']);
        $this->assertCount(30, $result);
    }

    public function testLeaveDaysSkipWeekendsAndHolidays(): void
    {
        // Mon 23.11. – Fri 27.11.2026; 25.11. = Dan državnosti
        $count = LeaveDayCounter::count(
            self::at('2026-11-21'),
            self::at('2026-11-29'),
            [1, 2, 3, 4, 5],
            static fn (DateTimeImmutable $d): bool => $d->format('m-d') === '11-25'
        );
        $this->assertSame(4, $count);
    }

    public function testLeaveBalanceCarryOverExpires(): void
    {
        $before = new LeaveBalance(20, 5, 3, 0, false, 2);
        $this->assertSame(3, $before->carryUsed);
        $this->assertSame(22, $before->remaining());
        $this->assertSame(20, $before->remainingAfterPending());

        $after = new LeaveBalance(20, 5, 3, 4, true);
        $this->assertSame(0, $after->carryRemaining);
        $this->assertSame(16, $after->remaining());
        $this->assertSame(7, $after->used());
    }
}
