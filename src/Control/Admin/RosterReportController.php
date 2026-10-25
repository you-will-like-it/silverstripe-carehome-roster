<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Control\Admin;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Model\Audit\AuditLogEntry;
use YouWillLikeIT\CareHome\Model\Task\DaysOfWeek;
use YouWillLikeIT\CareHome\Pdf\PdfRendererInterface;
use YouWillLikeIT\CareHome\Roster\Control\RosterAssets;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\HolidayCalendar;
use YouWillLikeIT\CareHome\Roster\Service\HoursReportService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Service\AuditLogger;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\MedicalReportService;

/**
 * Reports for CMS users with CAREHOME_ROSTER_REPORTS (or PLAN):
 *   /raspored-izvjestaj/hours?m=2026-11&g=care[&format=csv|pdf]   hours per worker
 *   /raspored-izvjestaj/plan?m=2026-11&g=care&z=0                 printable plan (PDF, A4 landscape)
 */
class RosterReportController extends Controller
{
    private static string $url_segment = 'raspored-izvjestaj';

    private static array $allowed_actions = ['hours', 'plan'];

    public static function hoursLink(int $year, int $month, ?string $group = null, ?string $format = null): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), 'hours')
            . '?m=' . sprintf('%04d-%02d', $year, $month)
            . ($group ? '&g=' . rawurlencode($group) : '')
            . ($format ? '&format=' . rawurlencode($format) : '');
    }

    public static function planPdfLink(int $year, int $month, string $group, int $zoneId = 0): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), 'plan')
            . '?m=' . sprintf('%04d-%02d', $year, $month) . '&g=' . rawurlencode($group) . ($zoneId ? '&z=' . $zoneId : '');
    }

    protected function init(): void
    {
        parent::init();
        HTTPCacheControlMiddleware::singleton()->disableCache(true);
        $member = Security::getCurrentUser();
        if (!$member || !(RosterPermissions::check(RosterPermissions::REPORTS, $member) || RosterPermissions::check(RosterPermissions::PLAN, $member))) {
            Security::permissionFailure($this);
        }
    }

    /* ============================================================ hours */

    public function hours(HTTPRequest $request): HTTPResponse|DBHTMLText
    {
        [$year, $month] = $this->month($request);
        $group = $this->group($request);
        $report = HoursReportService::singleton()->month($year, $month, $group);
        $format = (string) $request->getVar('format');

        if ($format === 'csv') {
            $this->audit('CSV', $year, $month, $group);

            return $this->download($this->csv($report), 'text/csv; charset=utf-8', sprintf('sati-%04d-%02d-%s.csv', $year, $month, $group));
        }

        $view = $this->hoursView($report, $year, $month, $group);
        if ($format === 'pdf') {
            $this->audit('PDF', $year, $month, $group);
            $html = (string) $view->renderWith('YouWillLikeIT\\CareHome\\Roster\\Pdf\\HoursReport');
            $pdf = $this->pdf($html, (string) $view->getField('Heading'));

            return $this->download($pdf, 'application/pdf', sprintf('sati-%04d-%02d-%s.pdf', $year, $month, $group), true);
        }

        return $view->customise([
            'Styles' => RosterAssets::singleton()->stylesheet('roster-report.css'),
            'CsvLink' => self::hoursLink($year, $month, $group, 'csv'),
            'PdfLink' => self::hoursLink($year, $month, $group, 'pdf'),
            'BoardLink' => PlanBoardController::monthLink($year, $month, $group),
            'PrevLink' => self::hoursLink((int) date('Y', mktime(0, 0, 0, $month - 1, 1, $year)), (int) date('n', mktime(0, 0, 0, $month - 1, 1, $year)), $group),
            'NextLink' => self::hoursLink((int) date('Y', mktime(0, 0, 0, $month + 1, 1, $year)), (int) date('n', mktime(0, 0, 0, $month + 1, 1, $year)), $group),
            'Groups' => $this->groupLinks($year, $month, $group),
        ])->renderWith('YouWillLikeIT\\CareHome\\Roster\\Report\\Hours');
    }

    /**
     * @param array<string,mixed> $report
     */
    private function hoursView(array $report, int $year, int $month, string $group): ArrayData
    {
        $settings = RosterSettings::current();
        /** @var list<AbsenceType> $types */
        $types = $report['absenceTypes'];
        $used = [];
        foreach ($report['rows'] as $row) {
            foreach (array_keys($row['absences']) as $code) {
                $used[$code] = true;
            }
        }
        $types = array_values(array_filter($types, static fn (AbsenceType $t): bool => isset($used[(string) $t->Code])));

        $h = static fn (int $minutes): string => HoursReportService::hours($minutes);
        $rows = ArrayList::create();
        foreach ($report['rows'] as $row) {
            $absences = ArrayList::create();
            foreach ($types as $type) {
                $absences->push(ArrayData::create(['Days' => $row['absences'][(string) $type->Code] ?? 0]));
            }
            $rows->push(ArrayData::create([
                'Name' => $row['name'],
                'Qualification' => $row['qualification'],
                'WeeklyHours' => rtrim(rtrim(number_format((float) $row['weeklyHours'], 2, ',', ''), '0'), ','),
                'Shifts' => $row['shifts'],
                'Work' => $h($row['work']),
                'Night' => $h($row['night']),
                'Sunday' => $h($row['sunday']),
                'Holiday' => $h($row['holiday']),
                'Target' => $h($row['target']),
                'Diff' => $h($row['diff']),
                'DiffPositive' => $row['diff'] > 0,
                'DiffNegative' => $row['diff'] < 0,
                'Absences' => $absences,
            ]));
        }

        $t = $report['totals'];
        $totalAbsences = ArrayList::create();
        foreach ($types as $type) {
            $totalAbsences->push(ArrayData::create(['Days' => $t['absences'][(string) $type->Code] ?? 0]));
        }

        $period = RosterPeriod::forMonth($year, $month, false);
        $pct = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . ' %';

        return ArrayData::create([
            'Title' => _t(__CLASS__ . '.HOURS_TITLE', 'Izvještaj o satima'),
            'Heading' => sprintf('%s %02d/%04d – %s', _t(__CLASS__ . '.HOURS_TITLE', 'Izvještaj o satima'), $month, $year, WorkerDirectory::groupOptions()[$group] ?? $group),
            'Facility' => $this->facilityName(),
            'PeriodStatus' => $period ? $period->getStatusLabel() : RosterPeriod::statusOptions()[RosterPeriod::STATUS_DRAFT],
            'IsLocked' => $period && $period->isLocked(),
            'Rows' => $rows,
            'AbsenceTypes' => ArrayList::create(array_map(static fn (AbsenceType $type): ArrayData => ArrayData::create([
                'Code' => $type->Code,
                'Title' => $type->Title,
            ]), $types)),
            'TotalShifts' => $t['shifts'],
            'TotalWork' => $h($t['work']),
            'TotalNight' => $h($t['night']),
            'TotalSunday' => $h($t['sunday']),
            'TotalHoliday' => $h($t['holiday']),
            'TotalTarget' => $h($t['target']),
            'TotalDiff' => $h($t['diff']),
            'TotalAbsences' => $totalAbsences,
            'NightWindow' => $settings->nightStart() . '–' . $settings->nightEnd(),
            'NightPct' => $pct((float) $settings->NightAllowancePct),
            'SundayPct' => $pct((float) $settings->SundayAllowancePct),
            'HolidayPct' => $pct((float) $settings->HolidayAllowancePct),
            'OvertimePct' => $pct((float) $settings->OvertimeAllowancePct),
            'GeneratedAt' => Clock::singleton()->now()->format('d.m.Y. H:i'),
        ]);
    }

    /**
     * Semicolon separated, decimal comma, UTF-8 BOM (opens correctly in Excel with BiH/DACH locale).
     *
     * @param array<string,mixed> $report
     */
    private function csv(array $report): string
    {
        /** @var list<AbsenceType> $types */
        $types = $report['absenceTypes'];
        $h = static fn (int $minutes): string => HoursReportService::hours($minutes);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $header = ['Radnik', 'Kvalifikacija', 'Ugovor h/sedm.', 'Smjene', 'Sati', 'Noćni sati', 'Nedjelja sati', 'Praznik sati', 'Norma sati', 'Razlika'];
        foreach ($types as $type) {
            $header[] = $type->Code . ' (dana)';
        }
        fputcsv($out, $header, ';', '"', '');

        foreach ($report['rows'] as $row) {
            $line = [
                $row['name'],
                $row['qualification'],
                number_format((float) $row['weeklyHours'], 2, ',', ''),
                $row['shifts'],
                $h($row['work']),
                $h($row['night']),
                $h($row['sunday']),
                $h($row['holiday']),
                $h($row['target']),
                $h($row['diff']),
            ];
            foreach ($types as $type) {
                $line[] = $row['absences'][(string) $type->Code] ?? 0;
            }
            fputcsv($out, array_map(self::csvSafe(...), $line), ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Prevents spreadsheet formula injection from names. */
    private static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) && !preg_match('/^-?\d/', $value) ? "'" . $value : $value;
    }

    /* ============================================================= plan PDF */

    public function plan(HTTPRequest $request): HTTPResponse
    {
        [$year, $month] = $this->month($request);
        $group = $this->group($request);
        $zoneId = (int) $request->getVar('z');
        $clock = Clock::singleton();
        $from = $clock->combine(sprintf('%04d-%02d-01', $year, $month), '00:00');
        $to = $from->modify('last day of this month');
        $holidays = HolidayCalendar::singleton();
        $directory = WorkerDirectory::singleton();
        $weekdays = DaysOfWeek::shortOptions();

        $days = ArrayList::create();
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $days->push(ArrayData::create([
                'Day' => (int) $d->format('j'),
                'Weekday' => $weekdays[(int) $d->format('N')],
                'IsWeekend' => (int) $d->format('N') >= 6,
                'IsHoliday' => $holidays->isHoliday($d),
            ]));
        }

        $assignments = [];
        foreach (ShiftAssignment::get()->filter([
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'Date:GreaterThanOrEqual' => $from->format('Y-m-d'),
            'Date:LessThanOrEqual' => $to->format('Y-m-d'),
        ])->sort('StartsAt') as $a) {
            $assignments[(int) $a->MemberID][(string) $a->Date][] = $a;
        }
        $absences = [];
        foreach (Absence::get()->filter([
            'Status' => Absence::STATUS_APPROVED,
            'DateFrom:LessThanOrEqual' => $to->format('Y-m-d'),
            'DateTo:GreaterThanOrEqual' => $from->format('Y-m-d'),
        ]) as $absence) {
            if ($absence->Type()->IsPreference) {
                continue;
            }
            for ($d = max($from, $absence->from()); $d <= min($to, $absence->to()); $d = $d->modify('+1 day')) {
                $absences[(int) $absence->MemberID][$d->format('Y-m-d')] = $absence->Type();
            }
        }

        $rows = ArrayList::create();
        foreach ($directory->workers($group, $from, $to) as $member) {
            if ($zoneId && !$directory->coversZone($member, $zoneId)) {
                continue;
            }
            $cells = ArrayList::create();
            for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
                $ymd = $d->format('Y-m-d');
                $codes = [];
                $colour = '';
                $text = '';
                foreach ($assignments[(int) $member->ID][$ymd] ?? [] as $a) {
                    $codes[] = $a->ShiftCode;
                    $type = $a->ShiftType();
                    $colour = (string) $type->Colour;
                    $text = $type->TextColour();
                }
                if (!$codes && isset($absences[(int) $member->ID][$ymd])) {
                    $type = $absences[(int) $member->ID][$ymd];
                    $codes[] = $type->Code;
                    $colour = (string) $type->Colour;
                    $text = $type->TextColour();
                }
                $cells->push(ArrayData::create([
                    'Codes' => implode(' ', $codes),
                    'Colour' => $colour,
                    'Text' => $text,
                    'IsWeekend' => (int) $d->format('N') >= 6,
                ]));
            }
            $rows->push(ArrayData::create([
                'Name' => trim($member->Surname . ' ' . $member->FirstName),
                'Qualification' => $member->RosterQualificationID ? $member->RosterQualification()->Code : '',
                'Cells' => $cells,
            ]));
        }

        $legend = ArrayList::create();
        $role = WorkerDirectory::groupRoles()[$group];
        foreach (ShiftType::get()->filter('IsActive', true)->sort('Sort') as $type) {
            if ($type->appliesToRole($role)) {
                $legend->push(ArrayData::create(['Code' => $type->Code, 'Title' => $type->Title, 'Time' => $type->getTimeRange(), 'Colour' => $type->Colour, 'Text' => $type->TextColour()]));
            }
        }
        foreach (AbsenceType::get()->filter(['IsActive' => true, 'IsPreference' => false])->sort('Sort') as $type) {
            $legend->push(ArrayData::create(['Code' => $type->Code, 'Title' => $type->Title, 'Time' => '', 'Colour' => $type->Colour, 'Text' => $type->TextColour()]));
        }

        $period = RosterPeriod::forMonth($year, $month, false);
        $heading = sprintf('%s %02d/%04d – %s', _t(__CLASS__ . '.PLAN_TITLE', 'Raspored smjena'), $month, $year, WorkerDirectory::groupOptions()[$group] ?? $group);
        $html = (string) ArrayData::create([
            'Heading' => $heading,
            'Facility' => $this->facilityName(),
            'Status' => $period ? $period->getStatusLabel() : RosterPeriod::statusOptions()[RosterPeriod::STATUS_DRAFT],
            'Days' => $days,
            'Rows' => $rows,
            'Legend' => $legend,
            'GeneratedAt' => $clock->now()->format('d.m.Y. H:i'),
        ])->renderWith('YouWillLikeIT\\CareHome\\Roster\\Pdf\\Plan');

        $this->audit('PDF plan', $year, $month, $group);

        return $this->download($this->pdf($html, $heading), 'application/pdf', sprintf('raspored-%04d-%02d-%s.pdf', $year, $month, $group), true);
    }

    /* ============================================================ helpers */

    /** @return array{0:int,1:int} */
    private function month(HTTPRequest $request): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $request->getVar('m'), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $today = Clock::singleton()->today();

        return [(int) $today->format('Y'), (int) $today->format('n')];
    }

    private function group(HTTPRequest $request): string
    {
        $group = (string) $request->getVar('g');

        return array_key_exists($group, WorkerDirectory::groupRoles()) ? $group : WorkerDirectory::GROUP_CARE;
    }

    private function groupLinks(int $year, int $month, string $current): ArrayList
    {
        $list = ArrayList::create();
        foreach (WorkerDirectory::groupOptions() as $key => $label) {
            $list->push(ArrayData::create([
                'Title' => $label,
                'Link' => self::hoursLink($year, $month, $key),
                'Current' => $key === $current,
            ]));
        }

        return $list;
    }

    private function facilityName(): string
    {
        return (string) (MedicalReportService::config()->get('facility_name') ?: '');
    }

    private function audit(string $what, int $year, int $month, string $group): void
    {
        AuditLogger::singleton()->logEvent(
            AuditLogEntry::ACTION_EVENT,
            _t(__CLASS__ . '.AUDIT', 'Raspored – izvoz {what} {month}', ['what' => $what, 'month' => sprintf('%02d/%04d', $month, $year)]),
            null,
            ['group' => $group]
        );
    }

    private function pdf(string $html, string $title): string
    {
        $footer = '<div style="font-size:7pt;color:#64748b;text-align:right">' . htmlspecialchars($title) . ' · {PAGENO}/{nbpg}</div>';

        return Injector::inst()->get(PdfRendererInterface::class)->render($html, '', $footer, $title);
    }

    private function download(string $body, string $type, string $filename, bool $inline = false): HTTPResponse
    {
        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', $type);
        $response->addHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
