<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Control\Admin;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\i18n\i18n;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use Throwable;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Task\DaysOfWeek;
use YouWillLikeIT\CareHome\Roster\Control\RosterAssets;
use YouWillLikeIT\CareHome\Roster\Domain\PlanCopier;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftPattern;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\AssignmentValidator;
use YouWillLikeIT\CareHome\Roster\Service\HolidayCalendar;
use YouWillLikeIT\CareHome\Roster\Service\PlanningService;
use YouWillLikeIT\CareHome\Roster\Service\PlanResult;
use YouWillLikeIT\CareHome\Roster\Service\StaffingService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\View\ViteAssets;

/**
 * Monthly planning board (CMS users with CAREHOME_ROSTER_PLAN).
 *
 *   GET  /raspored-plan?m=2026-11&g=care&z=12   board shell
 *   GET  /raspored-plan/data?…                  board JSON
 *   GET  /raspored-plan/candidates?assignment=ID who could take this shift
 *   POST /raspored-plan/{cell|assign|open|addopen|delete|pattern|copy|publish|lock|unlock}
 *
 * All POST endpoints answer JSON (PlanResult::toArray()).
 */
class PlanBoardController extends Controller
{
    private static string $url_segment = 'raspored-plan';

    private static array $allowed_actions = [
        'index',
        'data',
        'candidates',
        'cell',
        'assign',
        'open',
        'addopen',
        'delete',
        'pattern',
        'copy',
        'publish',
        'lock',
        'unlock',
    ];

    public static function monthLink(int $year, int $month, ?string $group = null): string
    {
        $query = '?m=' . sprintf('%04d-%02d', $year, $month) . ($group ? '&g=' . rawurlencode($group) : '');

        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment')) . $query;
    }

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), $action);
    }

    public function ContentLocale(): string
    {
        return (string) i18n::convert_rfc1766(i18n::get_locale());
    }

    protected function init(): void
    {
        parent::init();
        HTTPCacheControlMiddleware::singleton()->disableCache(true);
        $this->getResponse()->addHeader('X-Frame-Options', 'SAMEORIGIN');

        $member = Security::getCurrentUser();
        if (!$member || !RosterPermissions::check(RosterPermissions::PLAN, $member)) {
            Security::permissionFailure($this);

            return;
        }
        AuditContext::singleton()->setChannel(AuditContext::CHANNEL_CMS);
    }

    /* ================================================================ views */

    public function index(HTTPRequest $request): DBHTMLText
    {
        [$year, $month] = $this->month($request);

        return $this->customise([
            'Title' => _t(__CLASS__ . '.TITLE', 'Planer smjena'),
            'BoardConfig' => json_encode([
                'dataUrl' => $this->Link('data'),
                'actionBase' => $this->Link(),
                'month' => sprintf('%04d-%02d', $year, $month),
                'group' => $this->group($request),
                'zone' => (int) $request->getVar('z'),
                'token' => ['name' => SecurityToken::inst()->getName(), 'value' => SecurityToken::inst()->getValue()],
                'cmsUrl' => Controller::join_links(Director::baseURL(), 'admin', 'carehome-roster'),
                'isSuperAdmin' => RosterPermissions::isSuperAdmin(),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'BoardStyles' => RosterAssets::singleton()->stylesheet('roster-board.css'),
            'BoardScript' => RosterAssets::singleton()->script('roster-board.js'),
            'ViteTags' => ViteAssets::singleton()->tags(),
        ])->renderWith('YouWillLikeIT\\CareHome\\Roster\\Board\\Board');
    }

    public function data(HTTPRequest $request): HTTPResponse
    {
        [$year, $month] = $this->month($request);
        $group = $this->group($request);
        $zoneId = (int) $request->getVar('z');

        return $this->json($this->boardData($year, $month, $group, $zoneId));
    }

    /**
     * Workers of the shift's role group with their check result, best first.
     */
    public function candidates(HTTPRequest $request): HTTPResponse
    {
        /** @var ShiftAssignment|null $assignment */
        $assignment = ShiftAssignment::get()->byID((int) $request->getVar('assignment'));
        if (!$assignment) {
            return $this->json(['error' => 'not found'], 404);
        }

        $type = $assignment->ShiftType();
        $date = $assignment->dateObject();
        $validator = AssignmentValidator::singleton();
        $rows = [];
        foreach (WorkerDirectory::singleton()->workers(null, $date, $date) as $member) {
            if ((int) $member->ID === (int) $assignment->MemberID || !$type->appliesToRole((string) $member->WorkerRole)) {
                continue;
            }
            $report = $validator->checkAssignmentFor($member, $assignment, [(int) $assignment->ID]);
            $rows[] = [
                'id' => (int) $member->ID,
                'name' => $member->getName(),
                'qual' => $member->RosterQualificationID ? (string) $member->RosterQualification()->Code : '',
                'state' => $report->isBlocked() ? 'block' : ($report->hasWarnings() ? 'warn' : 'ok'),
                'messages' => $report->messages(),
            ];
        }
        $rank = ['ok' => 0, 'warn' => 1, 'block' => 2];
        usort($rows, static fn (array $a, array $b): int => [$rank[$a['state']], $a['name']] <=> [$rank[$b['state']], $b['name']]);

        return $this->json(['candidates' => $rows]);
    }

    /* ============================================================ actions */

    public function cell(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $member = $this->member((int) $r->postVar('member'));
            $date = Clock::singleton()->parseDate((string) $r->postVar('date'));
            if (!$member || !$date) {
                return PlanResult::error(_t(__CLASS__ . '.BAD_INPUT', 'Neispravan zahtjev.'));
            }

            return PlanningService::singleton()->setCell($member, $date, (array) ($r->postVar('types') ?? []), $this->reason($r));
        });
    }

    public function assign(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $assignment = ShiftAssignment::get()->byID((int) $r->postVar('assignment'));
            $member = $this->member((int) $r->postVar('member'));
            if (!$assignment || !$member) {
                return PlanResult::error(_t(__CLASS__ . '.BAD_INPUT', 'Neispravan zahtjev.'));
            }

            return PlanningService::singleton()->assignWorker($assignment, $member, $this->reason($r));
        });
    }

    public function open(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $assignment = ShiftAssignment::get()->byID((int) $r->postVar('assignment'));
            if (!$assignment) {
                return PlanResult::error(_t(__CLASS__ . '.BAD_INPUT', 'Neispravan zahtjev.'));
            }

            return PlanningService::singleton()->openAssignment($assignment, (string) $this->reason($r));
        });
    }

    public function addopen(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $type = ShiftType::get()->byID((int) $r->postVar('type'));
            $date = Clock::singleton()->parseDate((string) $r->postVar('date'));
            if (!$type || !$date) {
                return PlanResult::error(_t(__CLASS__ . '.BAD_INPUT', 'Neispravan zahtjev.'));
            }

            return PlanningService::singleton()->addOpenShift($type, $date, (string) $this->reason($r));
        });
    }

    public function delete(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $assignment = ShiftAssignment::get()->byID((int) $r->postVar('assignment'));
            if (!$assignment) {
                return PlanResult::error(_t(__CLASS__ . '.BAD_INPUT', 'Neispravan zahtjev.'));
            }

            return PlanningService::singleton()->deleteAssignment($assignment);
        });
    }

    public function pattern(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $clock = Clock::singleton();
            $pattern = ShiftPattern::get()->byID((int) $r->postVar('pattern'));
            $anchor = $clock->parseDate((string) $r->postVar('anchor'));
            $from = $clock->parseDate((string) $r->postVar('from'));
            $to = $clock->parseDate((string) $r->postVar('to'));
            $members = $this->members((array) ($r->postVar('members') ?? []));
            if (!$pattern || !$anchor || !$from || !$to || !$members) {
                return PlanResult::error(_t(__CLASS__ . '.PATTERN_INPUT', 'Odaberite obrazac, radnike i period.'));
            }

            return PlanningService::singleton()->applyPattern($members, $pattern, $anchor, $from, $to, $this->reason($r));
        });
    }

    public function copy(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            $clock = Clock::singleton();
            $members = WorkerDirectory::singleton()->workers($this->group($r));
            if ((string) $r->postVar('mode') === 'week') {
                $from = $clock->parseDate((string) $r->postVar('week'));
                if (!$from) {
                    return PlanResult::error(_t(__CLASS__ . '.WEEK_INPUT', 'Odaberite sedmicu.'));
                }
                $from = $from->modify('-' . ((int) $from->format('N') - 1) . ' days');

                return PlanningService::singleton()->copyPrevious($members, $from, $from->modify('+6 days'), PlanCopier::WEEK_OFFSET, $this->reason($r));
            }

            [$year, $month] = $this->month($r);
            $from = $clock->combine(sprintf('%04d-%02d-01', $year, $month), '00:00');

            return PlanningService::singleton()->copyPrevious($members, $from, $from->modify('last day of this month'), PlanCopier::MONTH_OFFSET, $this->reason($r));
        });
    }

    public function publish(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            [$year, $month] = $this->month($r);

            return PlanningService::singleton()->publish(RosterPeriod::forMonth($year, $month), Security::getCurrentUser());
        });
    }

    public function lock(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            [$year, $month] = $this->month($r);

            return PlanningService::singleton()->lock(RosterPeriod::forMonth($year, $month), Security::getCurrentUser());
        });
    }

    public function unlock(HTTPRequest $request): HTTPResponse
    {
        return $this->mutate($request, function (HTTPRequest $r): PlanResult {
            [$year, $month] = $this->month($r);

            return PlanningService::singleton()->unlock(RosterPeriod::forMonth($year, $month), Security::getCurrentUser(), (string) $this->reason($r));
        });
    }

    /* ============================================================ board data */

    /**
     * @return array<string,mixed>
     */
    private function boardData(int $year, int $month, string $group, int $zoneId): array
    {
        $clock = Clock::singleton();
        $period = RosterPeriod::forMonth($year, $month);
        $from = $period->firstDay();
        $to = $period->lastDay();
        $todayYmd = $clock->today()->format('Y-m-d');
        $holidays = HolidayCalendar::singleton();
        $directory = WorkerDirectory::singleton();
        $role = WorkerDirectory::groupRoles()[$group];

        $days = [];
        $weekdays = DaysOfWeek::shortOptions();
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $holiday = $holidays->titleFor($d);
            $days[] = [
                'ymd' => $d->format('Y-m-d'),
                'd' => (int) $d->format('j'),
                'wd' => $weekdays[(int) $d->format('N')],
                'weekend' => (int) $d->format('N') >= 6,
                'holiday' => $holiday,
                'today' => $d->format('Y-m-d') === $todayYmd,
            ];
        }

        $types = [];
        $typeIds = [];
        foreach (ShiftType::get()->sort('Sort') as $type) {
            if (!$type->appliesToRole($role)) {
                continue;
            }
            $typeIds[] = (int) $type->ID;
            if ($type->IsActive) {
                $types[] = [
                    'id' => (int) $type->ID,
                    'code' => (string) $type->Code,
                    'title' => (string) $type->Title,
                    'time' => $type->getTimeRange(),
                    'colour' => (string) $type->Colour,
                    'text' => $type->TextColour(),
                    'mismatch' => $type->resolverMismatch(),
                ];
            }
        }
        $colours = [];
        foreach (ShiftType::get() as $type) {
            $colours[(int) $type->ID] = [(string) $type->Colour, $type->TextColour()];
        }

        // workers + zone filter
        $workers = [];
        foreach ($directory->workers($group, $from, $to) as $member) {
            if ($zoneId && !$directory->coversZone($member, $zoneId)) {
                continue;
            }
            $workers[(int) $member->ID] = $member;
        }

        $cells = [];
        $minutes = [];
        $assignments = ShiftAssignment::get()->filter([
            'Date:GreaterThanOrEqual' => $from->format('Y-m-d'),
            'Date:LessThanOrEqual' => $to->format('Y-m-d'),
        ])->sort(['StartsAt' => 'ASC']);
        $open = [];
        foreach ($assignments as $a) {
            [$bg, $fg] = $colours[(int) $a->ShiftTypeID] ?? ['#64748b', '#ffffff'];
            $item = [
                'id' => (int) $a->ID,
                'type' => (int) $a->ShiftTypeID,
                'code' => (string) $a->ShiftCode,
                'time' => $a->getTimeRange(),
                'colour' => $bg,
                'text' => $fg,
                'changed' => (bool) $a->ChangedAfterPublish,
                'override' => (string) $a->OverrideReason,
                'note' => (string) $a->Note,
            ];
            if ($a->isOpen()) {
                if (in_array((int) $a->ShiftTypeID, $typeIds, true)) {
                    $item['reason'] = (string) $a->OpenReason;
                    $item['original'] = $a->OriginalMemberID ? $a->OriginalMember()->getName() : '';
                    $open[(string) $a->Date][] = $item;
                }
                continue;
            }
            if (!isset($workers[(int) $a->MemberID])) {
                continue;
            }
            $item['sub'] = $a->isSubstitution() ? $a->OriginalMember()->getName() : '';
            $cells[(int) $a->MemberID][(string) $a->Date]['items'][] = $item;
            $minutes[(int) $a->MemberID] = ($minutes[(int) $a->MemberID] ?? 0) + $a->toPlannedShift()->workMinutes();
        }

        if ($workers) {
            $absences = Absence::get()->filter([
                'MemberID' => array_keys($workers),
                'Status' => [Absence::STATUS_APPROVED, Absence::STATUS_PENDING],
                'DateFrom:LessThanOrEqual' => $to->format('Y-m-d'),
                'DateTo:GreaterThanOrEqual' => $from->format('Y-m-d'),
            ]);
            foreach ($absences as $absence) {
                $type = $absence->Type();
                $info = [
                    'id' => (int) $absence->ID,
                    'code' => (string) $type->Code,
                    'colour' => (string) $type->Colour,
                    'text' => $type->TextColour(),
                    'title' => $type->Title . ' · ' . $absence->getRangeLabel() . ($absence->isPending() ? ' (' . $absence->getStatusLabel() . ')' : ''),
                    'pending' => $absence->isPending(),
                    'wish' => (bool) $type->IsPreference,
                ];
                $start = max($from, $absence->from());
                $end = min($to, $absence->to());
                for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                    $ymd = $d->format('Y-m-d');
                    // a real absence wins over a wish on the same day
                    $current = $cells[(int) $absence->MemberID][$ymd]['absence'] ?? null;
                    if (!$current || ($current['wish'] && !$info['wish'])) {
                        $cells[(int) $absence->MemberID][$ymd]['absence'] = $info;
                    }
                }
            }
        }

        $rows = [];
        foreach ($workers as $id => $member) {
            $rowCells = [];
            foreach ($days as $day) {
                $cell = $cells[$id][$day['ymd']] ?? [];
                $date = $clock->parseDate($day['ymd']);
                $rowCells[$day['ymd']] = [
                    'items' => $cell['items'] ?? [],
                    'absence' => $cell['absence'] ?? null,
                    'employed' => $member->isEmployedOn($date),
                ];
            }
            $rows[] = [
                'id' => $id,
                'name' => trim($member->Surname . ' ' . $member->FirstName),
                'qual' => $member->RosterQualificationID ? (string) $member->RosterQualification()->Code : '',
                'hours' => round(($minutes[$id] ?? 0) / 60, 1),
                'weekly' => $member->contractWeeklyHours(),
                'contractEnds' => $member->contractEndsSoon() ? date('d.m.Y.', strtotime((string) $member->EmploymentEnd)) : null,
                'cells' => $rowCells,
            ];
        }

        $shortfalls = [];
        foreach (StaffingService::singleton()->shortfalls($from, $to) as $ymd => $list) {
            foreach ($list as $entry) {
                if (in_array((int) $entry['requirement']->ShiftTypeID, $typeIds, true)) {
                    $shortfalls[$ymd][] = $entry['label'];
                }
            }
        }

        $zones = [['id' => 0, 'title' => _t(__CLASS__ . '.ALL_ZONES', 'Sve zone')]];
        foreach (FacilityZone::get() as $zone) {
            $zones[] = ['id' => (int) $zone->ID, 'title' => $zone->getBreadcrumb()];
        }
        usort($zones, static fn (array $a, array $b): int => $a['id'] === 0 ? -1 : ($b['id'] === 0 ? 1 : strnatcasecmp($a['title'], $b['title'])));

        $prev = $from->modify('-1 month');
        $next = $from->modify('+1 month');
        $months = ['', 'januar', 'februar', 'mart', 'april', 'maj', 'juni', 'juli', 'august', 'septembar', 'oktobar', 'novembar', 'decembar'];

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => _t(__CLASS__ . '.MONTH_' . $month, ucfirst($months[$month])) . ' ' . $year,
                'status' => (string) $period->Status,
                'statusLabel' => $period->getStatusLabel(),
                'prev' => $prev->format('Y-m'),
                'next' => $next->format('Y-m'),
                'unlockReason' => (string) $period->UnlockReason,
            ],
            'groups' => array_map(
                static fn (string $key, string $label): array => ['id' => $key, 'title' => $label],
                array_keys(WorkerDirectory::groupOptions()),
                array_values(WorkerDirectory::groupOptions())
            ),
            'group' => $group,
            'zones' => $zones,
            'zone' => $zoneId,
            'days' => $days,
            'types' => $types,
            'patterns' => array_map(static fn (ShiftPattern $p): array => [
                'id' => (int) $p->ID,
                'title' => (string) $p->Title,
                'pattern' => (string) $p->Pattern,
            ], ShiftPattern::get()->sort('Sort')->toArray()),
            'rows' => $rows,
            'open' => $open,
            'shortfalls' => $shortfalls,
            'links' => [
                'pdf' => RosterReportController::planPdfLink($year, $month, $group, $zoneId),
                'report' => RosterReportController::hoursLink($year, $month, $group),
            ],
            'today' => $todayYmd,
        ];
    }

    /* ============================================================ plumbing */

    /**
     * @param callable(HTTPRequest):PlanResult $operation
     */
    private function mutate(HTTPRequest $request, callable $operation): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->json(['saved' => false, 'blocks' => ['POST required'], 'warnings' => []], 405);
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return $this->json(['saved' => false, 'blocks' => [_t(__CLASS__ . '.TOKEN', 'Sesija je istekla. Osvježite stranicu.')], 'warnings' => []], 400);
        }

        try {
            $result = $operation($request);
        } catch (ValidationException $e) {
            $messages = array_map(static fn (array $m): string => (string) $m['message'], $e->getResult()->getMessages());
            $result = PlanResult::error(implode(' ', $messages) ?: $e->getMessage());
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'CareHome roster board: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $result = PlanResult::error(_t(__CLASS__ . '.FAILED', 'Greška pri snimanju. Pokušajte ponovo.'));
        }

        return $this->json($result->toArray());
    }

    /** @return array{0:int,1:int} */
    private function month(HTTPRequest $request): array
    {
        $value = (string) ($request->requestVar('m') ?? '');
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $today = Clock::singleton()->today();

        return [(int) $today->format('Y'), (int) $today->format('n')];
    }

    private function group(HTTPRequest $request): string
    {
        $group = (string) $request->requestVar('g');

        return array_key_exists($group, WorkerDirectory::groupRoles()) ? $group : WorkerDirectory::GROUP_CARE;
    }

    private function reason(HTTPRequest $request): ?string
    {
        $reason = trim((string) $request->postVar('reason'));

        return $reason === '' ? null : mb_substr($reason, 0, 1000);
    }

    private function member(int $id): ?Member
    {
        /** @var Member|null $member */
        $member = $id > 0 ? Member::get()->byID($id) : null;

        return $member;
    }

    /**
     * @param array<int|string,mixed> $ids
     * @return list<Member>
     */
    private function members(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        return $ids ? Member::get()->filter('ID', $ids)->sort(['RosterSort' => 'ASC', 'Surname' => 'ASC'])->toArray() : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('Cache-Control', 'no-store');

        return $response;
    }
}
