<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Domain\HoursBreakdown;
use YouWillLikeIT\CareHome\Roster\Domain\HoursCalculator;
use YouWillLikeIT\CareHome\Roster\Domain\LeaveDayCounter;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Monthly hours per worker from the plan (no time tracking):
 * worked = planned shifts minus unpaid break; night/Sunday/holiday split by clock time;
 * target = contract weekly hours / 5 per workday (Mon–Fri, not a holiday) while employed,
 * reduced by paid absences (CreditsHours) on such workdays; difference = worked − target.
 *
 * A shift counts in the month of the date it starts.
 */
class HoursReportService
{
    use Injectable;

    /**
     * @return array{
     *   from:DateTimeImmutable, to:DateTimeImmutable,
     *   absenceTypes:list<AbsenceType>,
     *   rows:list<array<string,mixed>>,
     *   totals:array<string,mixed>
     * }
     */
    public function month(int $year, int $month, ?string $group = null): array
    {
        $clock = Clock::singleton();
        $from = $clock->combine(sprintf('%04d-%02d-01', $year, $month), '00:00');
        $to = $from->modify('last day of this month');

        $settings = RosterSettings::current();
        $holidays = HolidayCalendar::singleton();
        $calculator = new HoursCalculator($settings->nightStart(), $settings->nightEnd(), $holidays->predicate());
        $absenceTypes = AbsenceType::get()->sort('Sort')->toArray();

        $rows = [];
        $totals = ['shifts' => 0, 'work' => 0, 'night' => 0, 'sunday' => 0, 'holiday' => 0, 'target' => 0, 'diff' => 0, 'absences' => []];

        foreach (WorkerDirectory::singleton()->workers($group, $from, $to) as $member) {
            $row = $this->rowFor($member, $from, $to, $calculator);
            $rows[] = $row;
            $totals['shifts'] += $row['shifts'];
            foreach (['work', 'night', 'sunday', 'holiday', 'target', 'diff'] as $key) {
                $totals[$key] += $row[$key];
            }
            foreach ($row['absences'] as $code => $days) {
                $totals['absences'][$code] = ($totals['absences'][$code] ?? 0) + $days;
            }
        }

        return ['from' => $from, 'to' => $to, 'absenceTypes' => $absenceTypes, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<string,mixed> minutes for hour columns, days for absences
     */
    private function rowFor(Member $member, DateTimeImmutable $from, DateTimeImmutable $to, HoursCalculator $calculator): array
    {
        $clock = Clock::singleton();
        $holidays = HolidayCalendar::singleton();

        $sum = new HoursBreakdown();
        $shifts = 0;
        foreach (ShiftAssignment::get()->filter([
            'MemberID' => (int) $member->ID,
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'Date:GreaterThanOrEqual' => $from->format('Y-m-d'),
            'Date:LessThanOrEqual' => $to->format('Y-m-d'),
        ]) as $assignment) {
            $sum = $sum->add($calculator->breakdown($assignment->toPlannedShift()));
            $shifts++;
        }

        // employment window inside the month
        $empFrom = $member->EmploymentStart && (string) $member->EmploymentStart > $from->format('Y-m-d')
            ? $clock->parseDate((string) $member->EmploymentStart) : $from;
        $empTo = $member->EmploymentEnd && (string) $member->EmploymentEnd < $to->format('Y-m-d')
            ? $clock->parseDate((string) $member->EmploymentEnd) : $to;

        $workdays = $empFrom && $empTo && $empFrom <= $empTo
            ? LeaveDayCounter::count($empFrom, $empTo, [1, 2, 3, 4, 5], $holidays->predicate())
            : 0;
        $dailyMinutes = (int) round($member->contractWeeklyHours() / 5 * 60);

        $absenceDays = [];
        $creditedDays = 0;
        foreach (Absence::get()->filter([
            'MemberID' => (int) $member->ID,
            'Status' => Absence::STATUS_APPROVED,
            'DateFrom:LessThanOrEqual' => $to->format('Y-m-d'),
            'DateTo:GreaterThanOrEqual' => $from->format('Y-m-d'),
        ]) as $absence) {
            $type = $absence->Type();
            if ($type->IsPreference || !$absence->from() || !$absence->to()) {
                continue;
            }
            $clip = LeaveDayCounter::clip($absence->from(), $absence->to(), $from, $to);
            if (!$clip) {
                continue;
            }
            $calendarDays = $clip[0]->diff($clip[1])->days + 1;
            $absenceDays[(string) $type->Code] = ($absenceDays[(string) $type->Code] ?? 0) + $calendarDays;

            if ($type->CreditsHours && $empFrom && $empTo) {
                $inEmployment = LeaveDayCounter::clip($clip[0], $clip[1], $empFrom, $empTo);
                if ($inEmployment) {
                    $creditedDays += LeaveDayCounter::count($inEmployment[0], $inEmployment[1], [1, 2, 3, 4, 5], $holidays->predicate());
                }
            }
        }

        $target = max(0, ($workdays - $creditedDays) * $dailyMinutes);

        return [
            'member' => $member,
            'name' => trim($member->Surname . ' ' . $member->FirstName),
            'qualification' => $member->RosterQualificationID ? (string) $member->RosterQualification()->Code : '',
            'weeklyHours' => $member->contractWeeklyHours(),
            'shifts' => $shifts,
            'work' => $sum->work,
            'night' => $sum->night,
            'sunday' => $sum->sunday,
            'holiday' => $sum->holiday,
            'target' => $target,
            'diff' => $sum->work - $target,
            'absences' => $absenceDays,
        ];
    }

    public static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, ',', '.');
    }
}
