<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Control\Portal;

use DateTimeImmutable;
use RuntimeException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Control\Portal\AbstractPortalController;
use YouWillLikeIT\CareHome\Control\Portal\KitchenController;
use YouWillLikeIT\CareHome\Control\Portal\PortalController;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Model\Task\DaysOfWeek;
use YouWillLikeIT\CareHome\Roster\Control\RosterAssets;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\RosterNotification;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftSwapRequest;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\AbsenceService;
use YouWillLikeIT\CareHome\Roster\Service\AssignmentValidator;
use YouWillLikeIT\CareHome\Roster\Service\HolidayCalendar;
use YouWillLikeIT\CareHome\Roster\Service\LeaveBalanceService;
use YouWillLikeIT\CareHome\Roster\Service\RosterNotifier;
use YouWillLikeIT\CareHome\Roster\Service\RosterQuery;
use YouWillLikeIT\CareHome\Roster\Service\SwapService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Security\PortalAuthenticator;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * /raspored – the worker's own schedule (PIN login shared with /portal and /kuhinja):
 * month view, notifications, leave balance, absence requests / sick leave, shift swaps.
 * Only published months are visible.
 */
class RosterPortalController extends AbstractPortalController
{
    private static string $url_segment = 'raspored';

    private static array $allowed_actions = [
        'index',
        'login',
        'logout',
        'absence',
        'absenceCancel',
        'swap',
        'swapAction',
        'read',
    ];

    private static array $url_handlers = [
        'login' => 'login',
        'logout' => 'logout',
        'odsustvo/$ID!/povuci' => 'absenceCancel',
        'odsustvo' => 'absence',
        'zamjena/$ID!/$Op!' => 'swapAction',
        'zamjena' => 'swap',
        'procitano' => 'read',
    ];

    /** Notifications shown on the home page. */
    private static int $notification_limit = 8;

    protected function permissionCode(): string
    {
        return RosterPermissions::SELF;
    }

    protected function auditChannel(): string
    {
        return AuditContext::CHANNEL_PORTAL;
    }

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'), $action);
    }

    public static function homeLink(): string
    {
        return Controller::join_links(Director::baseURL(), static::config()->get('url_segment'));
    }

    public static function monthLink(int $year, int $month): string
    {
        return self::homeLink() . '?m=' . sprintf('%04d-%02d', $year, $month);
    }

    public function RosterStyles(): DBField
    {
        return RosterAssets::singleton()->stylesheet('roster-portal.css');
    }

    /* ================================================================ auth */

    public function index(HTTPRequest $request): DBHTMLText
    {
        $worker = $this->currentWorker();
        if (!$worker) {
            return $this->renderPage('YouWillLikeIT\\CareHome\\Portal\\Login', [
                'Title' => _t(__CLASS__ . '.LOGIN_TITLE', 'Moj raspored – prijava'),
                'LoginAction' => $this->Link('login'),
            ]);
        }

        [$year, $month] = $this->month($request);
        $period = RosterPeriod::forMonth($year, $month, false);
        $visible = $period !== null && !$period->isDraft();
        $today = Clock::singleton()->today();
        $balance = LeaveBalanceService::singleton()->balance($worker, (int) $today->format('Y'));
        $first = Clock::singleton()->combine(sprintf('%04d-%02d-01', $year, $month), '00:00');

        return $this->renderPage('YouWillLikeIT\\CareHome\\Roster\\Portal\\Home', [
            'Title' => _t(__CLASS__ . '.TITLE', 'Moj raspored'),
            'BackLink' => $this->backLink($worker),
            'MonthLabel' => sprintf('%02d/%04d', $month, $year),
            'PrevLink' => self::monthLink((int) $first->modify('-1 month')->format('Y'), (int) $first->modify('-1 month')->format('n')),
            'NextLink' => self::monthLink((int) $first->modify('+1 month')->format('Y'), (int) $first->modify('+1 month')->format('n')),
            'MonthVisible' => $visible,
            'Days' => $visible ? $this->monthDays($worker, $first) : ArrayList::create(),
            'Upcoming' => $this->upcoming($worker),
            'Notifications' => RosterNotification::get()->filter('MemberID', (int) $worker->ID)->limit((int) static::config()->get('notification_limit')),
            'UnreadCount' => RosterNotifier::singleton()->unreadCount($worker),
            'ReadAction' => $this->Link('procitano'),
            'LeaveYear' => $today->format('Y'),
            'LeaveRemaining' => $balance->remaining(),
            'LeaveUsed' => $balance->used(),
            'LeavePending' => $balance->pending,
            'LeaveCarry' => $balance->carryRemaining,
            'Absences' => $this->myAbsences($worker),
            'AbsenceTypes' => $this->options(AbsenceType::options(true), null),
            'AbsenceAction' => $this->Link('odsustvo'),
            'MinDate' => $today->modify('-' . (int) AbsenceService::config()->get('sick_backdate_days') . ' days')->format('Y-m-d'),
            'Today' => $today->format('Y-m-d'),
            'IncomingSwaps' => $this->swapRows(ShiftSwapRequest::get()->filter([
                'ColleagueID' => (int) $worker->ID,
                'Status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
            ]), true),
            'MySwaps' => $this->swapRows(ShiftSwapRequest::get()->filter('RequestedByID', (int) $worker->ID)->limit(10), false),
        ]);
    }

    public function login(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());
        $result = PortalAuthenticator::singleton()->attempt($request, (string) $request->postVar('pin'), $this->permissionCode());
        if ($result['error']) {
            $this->flash('error', $result['error']);
        }

        return $this->redirect($this->Link());
    }

    public function logout(HTTPRequest $request): HTTPResponse
    {
        $this->assertPostWithToken($request, $this->Link());
        PortalAuthenticator::singleton()->logout($request, $this->currentWorker());

        return $this->redirect($this->Link());
    }

    /* ============================================================ absences */

    public function absence(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $back = $this->Link() . '#odsustva';
        $this->assertPostWithToken($request, $back);

        $clock = Clock::singleton();
        $type = AbsenceType::get()->byID((int) $request->postVar('type'));
        $from = $clock->parseDate((string) $request->postVar('from'));
        $to = $clock->parseDate((string) $request->postVar('to')) ?? $from;
        if (!$type || !$from || !$to) {
            $this->flash('error', _t(__CLASS__ . '.ABSENCE_INPUT', 'Odaberite vrstu i period.'));

            return $this->redirect($back);
        }

        try {
            $absence = AbsenceService::singleton()->requestByWorker($worker, $type, $from, $to, (string) $request->postVar('note'));
            $this->flash('success', $absence->isApproved()
                ? _t(__CLASS__ . '.ABSENCE_RECORDED', 'Evidentirano: {type}, {range}. Administrator je obaviješten.', ['type' => $type->Title, 'range' => $absence->getRangeLabel()])
                : _t(__CLASS__ . '.ABSENCE_SENT', 'Zahtjev je poslan: {type}, {range}.', ['type' => $type->Title, 'range' => $absence->getRangeLabel()]));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            $this->flash('error', $this->validationMessage($e));
        }

        return $this->redirect($back);
    }

    public function absenceCancel(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $back = $this->Link() . '#odsustva';
        $this->assertPostWithToken($request, $back);

        $absence = Absence::get()->byID((int) $request->param('ID'));
        try {
            if (!$absence) {
                throw new RuntimeException(_t(__CLASS__ . '.NOT_FOUND', 'Zapis nije pronađen.'));
            }
            AbsenceService::singleton()->cancelByWorker($absence, $worker);
            $this->flash('success', _t(__CLASS__ . '.ABSENCE_WITHDRAWN', 'Zahtjev je povučen.'));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            $this->flash('error', $this->validationMessage($e));
        }

        return $this->redirect($back);
    }

    /* =============================================================== swaps */

    /**
     * GET  zamjena?assignment=ID[&colleague=ID]  choose colleague (and optional exchange shift)
     * POST zamjena                               create the request
     */
    public function swap(HTTPRequest $request): HTTPResponse|DBHTMLText
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }

        if ($request->isPOST()) {
            return $this->createSwap($request, $worker);
        }

        /** @var ShiftAssignment|null $assignment */
        $assignment = ShiftAssignment::get()->byID((int) $request->getVar('assignment'));
        if (!$assignment || (int) $assignment->MemberID !== (int) $worker->ID || $assignment->isOpen()) {
            $this->flash('error', _t(__CLASS__ . '.SWAP_NOT_OWN', 'Smjena nije pronađena.'));

            return $this->redirect($this->Link());
        }

        $colleagueId = (int) $request->getVar('colleague');
        $validator = AssignmentValidator::singleton();
        $group = WorkerDirectory::groupForRole((string) $worker->WorkerRole);
        $date = $assignment->dateObject();

        $colleagues = ArrayList::create();
        $selected = null;
        foreach (WorkerDirectory::singleton()->workers($group, $date, $date) as $member) {
            if ((int) $member->ID === (int) $worker->ID) {
                continue;
            }
            $report = $validator->checkAssignmentFor($member, $assignment, [(int) $assignment->ID]);
            if ($report->isBlocked()) {
                continue;
            }
            $isSelected = (int) $member->ID === $colleagueId;
            if ($isSelected) {
                $selected = $member;
            }
            $colleagues->push(ArrayData::create([
                'ID' => (int) $member->ID,
                'Name' => $member->getName(),
                'Warning' => $report->hasWarnings(),
                'Selected' => $isSelected,
            ]));
        }

        $counterOptions = ArrayList::create();
        if ($selected) {
            foreach (RosterQuery::singleton()->upcomingFor($selected, 20) as $counter) {
                if ($counter->startsAt() <= Clock::singleton()->now()) {
                    continue;
                }
                $counterOptions->push(ArrayData::create([
                    'ID' => (int) $counter->ID,
                    'Label' => $this->assignmentLabel($counter),
                ]));
            }
        }

        return $this->renderPage('YouWillLikeIT\\CareHome\\Roster\\Portal\\Swap', [
            'Title' => _t(__CLASS__ . '.SWAP_TITLE', 'Zamjena smjene'),
            'AssignmentLabel' => $this->assignmentLabel($assignment),
            'AssignmentID' => (int) $assignment->ID,
            'Colleagues' => $colleagues,
            'Colleague' => $selected,
            'CounterOptions' => $counterOptions,
            'ChooseAction' => $this->Link('zamjena'),
            'SubmitAction' => $this->Link('zamjena'),
            'BackLink' => $this->Link(),
        ]);
    }

    private function createSwap(HTTPRequest $request, Member $worker): HTTPResponse
    {
        $assignmentId = (int) $request->postVar('assignment');
        $back = $this->Link('zamjena') . '?assignment=' . $assignmentId;
        $this->assertPostWithToken($request, $back);

        $assignment = ShiftAssignment::get()->byID($assignmentId);
        $colleague = Member::get()->byID((int) $request->postVar('colleague'));
        $counterId = (int) $request->postVar('counter');
        $counter = $counterId ? ShiftAssignment::get()->byID($counterId) : null;

        try {
            if (!$assignment || !$colleague || ($counterId && !$counter)) {
                throw new RuntimeException(_t(__CLASS__ . '.SWAP_INPUT', 'Odaberite kolegu.'));
            }
            SwapService::singleton()->createByWorker($worker, $assignment, $colleague, $counter, (string) $request->postVar('note'));
            $this->flash('success', _t(__CLASS__ . '.SWAP_SENT', 'Zahtjev je poslan kolegi {name}.', ['name' => $colleague->getName()]));

            return $this->redirect($this->Link() . '#zamjene');
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            $this->flash('error', $this->validationMessage($e));
        }

        return $this->redirect($back);
    }

    /**
     * zamjena/{id}/prihvati | odbij | povuci
     */
    public function swapAction(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $back = $this->Link() . '#zamjene';
        $this->assertPostWithToken($request, $back);

        /** @var ShiftSwapRequest|null $swap */
        $swap = ShiftSwapRequest::get()->byID((int) $request->param('ID'));
        $service = SwapService::singleton();
        try {
            if (!$swap) {
                throw new RuntimeException(_t(__CLASS__ . '.NOT_FOUND', 'Zapis nije pronađen.'));
            }
            $note = (string) $request->postVar('note');
            match ((string) $request->param('Op')) {
                'prihvati' => $service->respond($swap, $worker, true, $note),
                'odbij' => $service->respond($swap, $worker, false, $note),
                'povuci' => $service->cancelByRequester($swap, $worker),
                default => throw new RuntimeException(_t(__CLASS__ . '.NOT_FOUND', 'Zapis nije pronađen.')),
            };
            $this->flash('success', _t(__CLASS__ . '.SAVED', 'Spremljeno.'));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (ValidationException $e) {
            $this->flash('error', $this->validationMessage($e));
        }

        return $this->redirect($back);
    }

    public function read(HTTPRequest $request): HTTPResponse
    {
        $worker = $this->requireWorker();
        if ($worker instanceof HTTPResponse) {
            return $worker;
        }
        $this->assertPostWithToken($request, $this->Link());
        RosterNotifier::singleton()->markAllRead($worker);

        return $this->redirect($this->Link() . '#obavjestenja');
    }

    /* ============================================================ view data */

    private function monthDays(Member $worker, DateTimeImmutable $first): ArrayList
    {
        $last = $first->modify('last day of this month');
        $todayYmd = Clock::singleton()->today()->format('Y-m-d');
        $weekdays = DaysOfWeek::shortOptions();
        $holidays = HolidayCalendar::singleton();

        $shifts = [];
        foreach (ShiftAssignment::get()->filter([
            'MemberID' => (int) $worker->ID,
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'Date:GreaterThanOrEqual' => $first->format('Y-m-d'),
            'Date:LessThanOrEqual' => $last->format('Y-m-d'),
        ])->sort('StartsAt') as $assignment) {
            $shifts[(string) $assignment->Date][] = $assignment;
        }
        $absences = [];
        foreach (Absence::get()->filter([
            'MemberID' => (int) $worker->ID,
            'Status' => [Absence::STATUS_APPROVED, Absence::STATUS_PENDING],
            'DateFrom:LessThanOrEqual' => $last->format('Y-m-d'),
            'DateTo:GreaterThanOrEqual' => $first->format('Y-m-d'),
        ]) as $absence) {
            for ($d = max($first, $absence->from()); $d <= min($last, $absence->to()); $d = $d->modify('+1 day')) {
                $absences[$d->format('Y-m-d')] ??= $absence;
            }
        }

        $days = ArrayList::create();
        for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $items = ArrayList::create();
            foreach ($shifts[$ymd] ?? [] as $assignment) {
                $type = $assignment->ShiftType();
                $items->push(ArrayData::create([
                    'Code' => $assignment->ShiftCode,
                    'Title' => $assignment->ShiftTitle,
                    'Time' => $assignment->getTimeRange(),
                    'Colour' => $type->Colour,
                    'Text' => $type->TextColour(),
                    'Changed' => (bool) $assignment->ChangedAfterPublish,
                    'SubstituteFor' => $assignment->isSubstitution() ? $assignment->OriginalMember()->getName() : null,
                    'SwapLink' => $assignment->startsAt() > Clock::singleton()->now()
                        ? $this->Link('zamjena') . '?assignment=' . $assignment->ID
                        : null,
                ]));
            }
            $absence = $absences[$ymd] ?? null;
            $days->push(ArrayData::create([
                'Date' => DBField::create_field('Date', $ymd),
                'Day' => $d->format('d.m.'),
                'Weekday' => $weekdays[(int) $d->format('N')],
                'IsWeekend' => (int) $d->format('N') >= 6,
                'IsToday' => $ymd === $todayYmd,
                'Holiday' => $holidays->titleFor($d),
                'Shifts' => $items,
                'Absence' => $absence ? ArrayData::create([
                    'Code' => $absence->Type()->Code,
                    'Title' => $absence->Type()->Title,
                    'Colour' => $absence->Type()->Colour,
                    'Text' => $absence->Type()->TextColour(),
                    'Pending' => $absence->isPending(),
                ]) : null,
            ]));
        }

        return $days;
    }

    private function upcoming(Member $worker): ArrayList
    {
        $list = ArrayList::create();
        foreach (RosterQuery::singleton()->upcomingFor($worker, 5) as $assignment) {
            $type = $assignment->ShiftType();
            $list->push(ArrayData::create([
                'Label' => $this->assignmentLabel($assignment),
                'Code' => $assignment->ShiftCode,
                'Colour' => $type->Colour,
                'Text' => $type->TextColour(),
                'Changed' => (bool) $assignment->ChangedAfterPublish,
                'SwapLink' => $assignment->startsAt() > Clock::singleton()->now()
                    ? $this->Link('zamjena') . '?assignment=' . $assignment->ID
                    : null,
            ]));
        }

        return $list;
    }

    private function myAbsences(Member $worker): ArrayList
    {
        $list = ArrayList::create();
        $since = Clock::singleton()->today()->modify('-60 days')->format('Y-m-d');
        foreach (Absence::get()->filter(['MemberID' => (int) $worker->ID, 'DateTo:GreaterThanOrEqual' => $since])->sort('DateFrom', 'DESC')->limit(15) as $absence) {
            $list->push(ArrayData::create([
                'Type' => $absence->Type()->Title,
                'Range' => $absence->getRangeLabel(),
                'Status' => $absence->getStatusLabel(),
                'StatusCode' => $absence->Status,
                'DecisionNote' => $absence->DecisionNote,
                'LeaveDays' => (int) $absence->LeaveDays,
                'CancelAction' => $absence->isPending() ? $this->Link('odsustvo/' . $absence->ID . '/povuci') : null,
            ]));
        }

        return $list;
    }

    /**
     * @param DataList<ShiftSwapRequest> $requests
     */
    private function swapRows(DataList $requests, bool $incoming): ArrayList
    {
        $list = ArrayList::create();
        foreach ($requests as $swap) {
            $list->push(ArrayData::create([
                'Summary' => $this->swapSummary($swap),
                'From' => $swap->RequestedBy()->getName(),
                'To' => $swap->Colleague()->getName(),
                'Note' => $swap->RequestNote,
                'Status' => $swap->getStatusLabel(),
                'DecisionNote' => $swap->DecisionNote,
                'AcceptAction' => $incoming ? $this->Link('zamjena/' . $swap->ID . '/prihvati') : null,
                'DeclineAction' => $incoming ? $this->Link('zamjena/' . $swap->ID . '/odbij') : null,
                'CancelAction' => !$incoming && !$swap->isFinal() ? $this->Link('zamjena/' . $swap->ID . '/povuci') : null,
            ]));
        }

        return $list;
    }

    private function swapSummary(ShiftSwapRequest $swap): string
    {
        $text = $swap->Assignment()->exists() ? $this->assignmentLabel($swap->Assignment()) : '';
        if ($swap->CounterAssignmentID && $swap->CounterAssignment()->exists()) {
            $text .= ' ⇄ ' . $this->assignmentLabel($swap->CounterAssignment());
        }

        return $text;
    }

    private function assignmentLabel(ShiftAssignment $assignment): string
    {
        $date = $assignment->dateObject();

        return sprintf(
            '%s %s · %s %s',
            DaysOfWeek::shortOptions()[(int) $date->format('N')],
            $date->format('d.m.'),
            $assignment->ShiftCode,
            $assignment->getTimeRange()
        );
    }

    private function backLink(Member $worker): ?string
    {
        return match ((string) $worker->WorkerRole) {
            MemberCareExtension::ROLE_KITCHEN => KitchenController::singleton()->Link(),
            MemberCareExtension::ROLE_CAREWORKER, MemberCareExtension::ROLE_ADMIN => PortalController::singleton()->Link(),
            default => null,
        };
    }

    /** @return array{0:int,1:int} */
    private function month(HTTPRequest $request): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $request->getVar('m'), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        $today = Clock::singleton()->today();

        return [(int) $today->format('Y'), (int) $today->format('n')];
    }

    /**
     * @param array<int|string,string> $options
     */
    private function options(array $options, int|string|null $selected): ArrayList
    {
        $list = ArrayList::create();
        foreach ($options as $value => $label) {
            $list->push(ArrayData::create([
                'Value' => $value,
                'Label' => $label,
                'Selected' => $selected !== null && (string) $value === (string) $selected,
            ]));
        }

        return $list;
    }

    private function validationMessage(ValidationException $e): string
    {
        $messages = array_map(static fn (array $m): string => (string) $m['message'], $e->getResult()->getMessages());

        return implode(' ', $messages) ?: $e->getMessage();
    }
}
