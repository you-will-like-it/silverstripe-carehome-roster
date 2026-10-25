<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Control\Admin\PlanBoardController;
use YouWillLikeIT\CareHome\Roster\Control\Admin\RosterReportController;
use YouWillLikeIT\CareHome\Roster\Model\Absence;
use YouWillLikeIT\CareHome\Roster\Model\LeaveEntitlement;
use YouWillLikeIT\CareHome\Roster\Model\RosterPeriod;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\ShiftSwapRequest;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\LeaveBalanceService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Daily roster work: absences and requests, swaps, open shifts, monthly plans, leave balances.
 * The plan itself is edited on the planning board (/raspored-plan).
 */
class RosterAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-roster';

    private static $menu_title = 'Raspored';

    private static $menu_icon_class = 'font-icon-calendar';

    private static $menu_priority = 9;

    private static $required_permission_codes = [RosterPermissions::PLAN];

    private static $managed_models = [
        'absences' => [
            'dataClass' => Absence::class,
            'title' => 'Odsustva i zahtjevi',
        ],
        'swaps' => [
            'dataClass' => ShiftSwapRequest::class,
            'title' => 'Zamjene smjena',
        ],
        'open' => [
            'dataClass' => ShiftAssignment::class,
            'title' => 'Otvorene smjene',
        ],
        'periods' => [
            'dataClass' => RosterPeriod::class,
            'title' => 'Mjesečni rasporedi',
        ],
        'leave' => [
            'dataClass' => LeaveEntitlement::class,
            'title' => 'Godišnji odmor – saldo',
        ],
    ];

    public function getList(): DataList
    {
        $list = parent::getList();

        return match ($this->modelClass) {
            // pending first, then newest
            Absence::class => $list->orderBy('CASE WHEN "CareHomeRoster_Absence"."Status" = \'Pending\' THEN 0 ELSE 1 END, "DateFrom" DESC'),
            ShiftSwapRequest::class => $list->orderBy('CASE WHEN "CareHomeRoster_ShiftSwapRequest"."Status" IN (\'AwaitingApproval\', \'AwaitingColleague\') THEN 0 ELSE 1 END, "Created" DESC'),
            ShiftAssignment::class => $list->filter([
                'Status' => ShiftAssignment::STATUS_OPEN,
                'Date:GreaterThanOrEqual' => Clock::singleton()->today()->modify('-7 days')->format('Y-m-d'),
            ])->sort(['Date' => 'ASC', 'StartsAt' => 'ASC']),
            LeaveEntitlement::class => $this->leaveList($list),
            default => $list,
        };
    }

    public function getEditForm($id = null, $fields = null): Form
    {
        $form = parent::getEditForm($id, $fields);

        $today = Clock::singleton()->today();
        $links = sprintf(
            '<p class="carehome-roster-links"><a class="btn btn-primary" href="%s">%s</a> <a class="btn btn-outline-secondary" href="%s">%s</a></p>',
            htmlspecialchars(PlanBoardController::monthLink((int) $today->format('Y'), (int) $today->format('n'))),
            htmlspecialchars(_t(__CLASS__ . '.BOARD', 'Otvori planer smjena')),
            htmlspecialchars(RosterReportController::hoursLink((int) $today->format('Y'), (int) $today->format('n'))),
            htmlspecialchars(_t(__CLASS__ . '.REPORT', 'Izvještaj o satima'))
        );

        $warnings = [];
        foreach (WorkerDirectory::singleton()->workers() as $member) {
            /** @var Member $member */
            if ($member->contractEndsSoon($today)) {
                $warnings[] = _t(__CLASS__ . '.CONTRACT_ENDS', 'Ugovor ističe {date}: {name}', [
                    'date' => date('d.m.Y.', strtotime((string) $member->EmploymentEnd)),
                    'name' => $member->getName(),
                ]);
            }
        }
        if ($warnings) {
            $links .= '<div class="message warning">' . implode('<br>', array_map('htmlspecialchars', $warnings)) . '</div>';
        }

        $form->Fields()->unshift(LiteralField::create('RosterLinks', $links));

        return $form;
    }

    /**
     * Make sure the current year's entitlement exists for every active worker, so the
     * balance list is complete without manual data entry.
     */
    private function leaveList(DataList $list): DataList
    {
        $today = Clock::singleton()->today();
        $year = (int) $today->format('Y');
        $service = LeaveBalanceService::singleton();
        foreach (WorkerDirectory::singleton()->workers(null, $today, $today) as $member) {
            $service->entitlementFor($member, $year);
        }

        return $list->sort(['Year' => 'DESC', 'Member.Surname' => 'ASC']);
    }
}
