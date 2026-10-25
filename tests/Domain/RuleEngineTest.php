<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use YouWillLikeIT\CareHome\Roster\Domain\PlannedShift;
use YouWillLikeIT\CareHome\Roster\Domain\RuleConfig;
use YouWillLikeIT\CareHome\Roster\Domain\RuleEngine;
use YouWillLikeIT\CareHome\Roster\Domain\Violation;

class RuleEngineTest extends TestCase
{
    private static int $id = 0;

    private static function shift(string $start, string $end, int $break = 0): PlannedShift
    {
        $tz = new DateTimeZone('Europe/Sarajevo');

        return new PlannedShift(++self::$id, new DateTimeImmutable($start, $tz), new DateTimeImmutable($end, $tz), $break);
    }

    /** @return list<string> */
    private static function codes(array $violations): array
    {
        return array_map(static fn (Violation $v): string => $v->code, $violations);
    }

    private function engine(): RuleEngine
    {
        return new RuleEngine(new RuleConfig(12, 24, 12, 50, 6));
    }

    public function testOverlapBlocks(): void
    {
        $result = $this->engine()->check(
            self::shift('2026-11-02 06:00', '2026-11-02 14:00'),
            [self::shift('2026-11-02 13:00', '2026-11-02 21:00')]
        );
        $this->assertSame([Violation::OVERLAP], self::codes($result));
        $this->assertTrue($result[0]->isBlocking());
    }

    public function testCleanTwelveTwentyFourTwelveFortyEightRotation(): void
    {
        // D 07-19, N 19-07, off, off, D ...
        $others = [
            self::shift('2026-11-02 07:00', '2026-11-02 19:00'),
            self::shift('2026-11-03 19:00', '2026-11-04 07:00'),
            self::shift('2026-11-10 07:00', '2026-11-10 19:00'),
        ];
        $candidate = self::shift('2026-11-06 07:00', '2026-11-06 19:00');
        $this->assertSame([], self::codes($this->engine()->check($candidate, $others)));
    }

    public function testDailyRestBeforeAndAfter(): void
    {
        $others = [
            self::shift('2026-11-02 14:00', '2026-11-02 22:00'),
            self::shift('2026-11-03 16:00', '2026-11-03 22:00'),
        ];
        // 22:00 → 06:00 = 8 h rest before; 14:00 → 16:00 = 2 h after
        $result = $this->engine()->check(self::shift('2026-11-03 06:00', '2026-11-03 14:00'), $others);
        $this->assertSame([Violation::DAILY_REST, Violation::DAILY_REST], self::codes($result));
        $this->assertSame('before', $result[0]->params['side']);
        $this->assertSame('after', $result[1]->params['side']);
    }

    public function testMaxShiftUsesWorkTime(): void
    {
        $long = self::shift('2026-11-02 07:00', '2026-11-02 20:00', 30);
        $this->assertSame([Violation::MAX_SHIFT], self::codes($this->engine()->check($long, [])));
        $ok = self::shift('2026-11-02 07:00', '2026-11-02 19:30', 30);
        $this->assertSame([], self::codes($this->engine()->check($ok, [])));
    }

    public function testWeeklyHoursAndConsecutiveDays(): void
    {
        $others = [];
        // Mon..Sat 2.–7.11.2026, 9 h each = 54 h
        for ($d = 2; $d <= 6; $d++) {
            $others[] = self::shift(sprintf('2026-11-%02d 06:00', $d), sprintf('2026-11-%02d 15:00', $d));
        }
        $candidate = self::shift('2026-11-07 06:00', '2026-11-07 15:00');
        $this->assertSame([Violation::WEEKLY_HOURS], self::codes($this->engine()->check($candidate, $others)));

        $others[] = $candidate;
        // Sunday makes it 7 days in a row
        $sunday = self::shift('2026-11-08 06:00', '2026-11-08 10:00');
        $codes = self::codes($this->engine()->check($sunday, $others));
        $this->assertContains(Violation::CONSECUTIVE_DAYS, $codes);
        $this->assertContains(Violation::WEEKLY_HOURS, $codes);
    }

    public function testWeeklyRestOnlyWhenBoundedOnBothSides(): void
    {
        $engine = new RuleEngine(new RuleConfig(0, 24, 0, 0, 0));
        // shifts every day 06-18 → 12 h gaps all week, bounded by shifts in previous and next week
        $others = [];
        for ($d = 1; $d <= 9; $d++) {
            if ($d === 4) {
                continue;
            }
            $others[] = self::shift(sprintf('2026-11-%02d 06:00', $d), sprintf('2026-11-%02d 18:00', $d));
        }
        $candidate = self::shift('2026-11-04 06:00', '2026-11-04 18:00');
        $this->assertSame([Violation::WEEKLY_REST], self::codes($engine->check($candidate, $others)));

        // nothing planned after the week yet → unknown future counts as free
        $partial = array_values(array_filter($others, static fn (PlannedShift $s): bool => $s->start->format('j') < 6));
        $this->assertSame([], self::codes($engine->check($candidate, $partial)));
    }

    public function testDisabledRules(): void
    {
        $engine = new RuleEngine(new RuleConfig(0, 0, 0, 0, 0));
        $others = [self::shift('2026-11-02 14:00', '2026-11-02 22:00')];
        $this->assertSame([], self::codes($engine->check(self::shift('2026-11-02 23:00', '2026-11-03 13:00'), $others)));
    }
}
