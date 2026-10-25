<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Control\Portal\AbstractPortalController;
use YouWillLikeIT\CareHome\Control\Portal\PortalController;
use YouWillLikeIT\CareHome\Roster\Control\Portal\RosterPortalController;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\RosterNotifier;
use YouWillLikeIT\CareHome\Roster\Service\RosterQuery;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\ShiftResolver;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Hooks into the base module's PIN front-ends (/portal, /kuhinja):
 * - "Moj raspored" link with unread count (updatePortalNavLinks)
 * - "Danas u smjeni" block on the /portal home (updatePortalSlot, slot "home-top")
 * - handover receiver list narrowed to the workers of the next shift (updateHandoverReceivers)
 *
 * The hooks are provided by youwilllikeit/silverstripe-carehome ^1.0.
 *
 * @extends Extension<AbstractPortalController>
 */
class PortalIntegrationExtension extends Extension
{
    protected function updatePortalNavLinks(ArrayList $links): void
    {
        $worker = $this->getOwner()->currentWorker();
        if (!$worker || !RosterPermissions::check(RosterPermissions::SELF, $worker)) {
            return;
        }
        $unread = RosterNotifier::singleton()->unreadCount($worker);
        $links->push(ArrayData::create([
            'Title' => _t(__CLASS__ . '.MY_ROSTER', 'Moj raspored'),
            'Link' => RosterPortalController::homeLink(),
            'Badge' => $unread > 0 ? $unread : null,
        ]));
    }

    /**
     * @param list<string> $parts HTML fragments
     */
    protected function updatePortalSlot(string $slot, array &$parts): void
    {
        $owner = $this->getOwner();
        $worker = $owner->currentWorker();
        if ($slot !== 'home-top' || !$owner instanceof PortalController || !$worker) {
            return;
        }

        $zoneIds = ZoneScope::singleton()->zoneIdsFor($worker);
        $now = Clock::singleton()->now();
        $rows = ArrayList::create();
        foreach (RosterQuery::singleton()->onShiftAt($now, $zoneIds) as $assignment) {
            $rows->push(ArrayData::create([
                'Name' => $assignment->Member()->getName(),
                'Code' => $assignment->ShiftCode,
                'Time' => $assignment->getTimeRange(),
                'Colour' => $assignment->ShiftType()->Colour,
                'Text' => $assignment->ShiftType()->TextColour(),
                'IsMe' => (int) $assignment->MemberID === (int) $worker->ID,
            ]));
        }

        $parts[] = (string) ArrayData::create([
            'OnShift' => $rows,
            'RosterLink' => RosterPortalController::homeLink(),
        ])->renderWith('YouWillLikeIT\\CareHome\\Roster\\Portal\\OnShiftBlock');
    }

    /**
     * Only workers whose shift starts around the end of the current shift — falls back to the
     * unfiltered list when the plan has nobody (unplanned cover must stay possible).
     *
     * @param DataList<Member> $members
     */
    protected function updateHandoverReceivers(DataList &$members, Member $worker): void
    {
        $current = ShiftResolver::singleton()->current();
        if (!$current) {
            return;
        }
        $window = ShiftResolver::singleton()->window($current['date'], $current['code']);
        if (!$window) {
            return;
        }
        $ids = RosterQuery::singleton()->nextShiftMemberIds($window[1], ZoneScope::singleton()->zoneIdsFor($worker));
        $ids = array_values(array_diff($ids, [(int) $worker->ID]));
        if ($ids) {
            $members = $members->filter('ID', $ids);
        }
    }
}
