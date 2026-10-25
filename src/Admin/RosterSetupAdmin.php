<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\ORM\DataList;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\ContractType;
use YouWillLikeIT\CareHome\Roster\Model\PublicHoliday;
use YouWillLikeIT\CareHome\Roster\Model\Qualification;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Model\ShiftPattern;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Roster\Model\StaffingRequirement;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;

/**
 * Roster catalogs and rules.
 */
class RosterSetupAdmin extends ModelAdmin
{
    private static $url_segment = 'carehome-roster-setup';

    private static $menu_title = 'Raspored – postavke';

    private static $menu_icon_class = 'font-icon-cog';

    private static $menu_priority = 8;

    private static $required_permission_codes = [RosterPermissions::PLAN];

    private static $managed_models = [
        'shifts' => [
            'dataClass' => ShiftType::class,
            'title' => 'Tipovi smjena',
        ],
        'patterns' => [
            'dataClass' => ShiftPattern::class,
            'title' => 'Obrasci rotacije',
        ],
        'staffing' => [
            'dataClass' => StaffingRequirement::class,
            'title' => 'Minimalna popunjenost',
        ],
        'absencetypes' => [
            'dataClass' => AbsenceType::class,
            'title' => 'Vrste odsustva',
        ],
        'qualifications' => [
            'dataClass' => Qualification::class,
            'title' => 'Kvalifikacije',
        ],
        'contracts' => [
            'dataClass' => ContractType::class,
            'title' => 'Vrste ugovora',
        ],
        'holidays' => [
            'dataClass' => PublicHoliday::class,
            'title' => 'Praznici',
        ],
        'settings' => [
            'dataClass' => RosterSettings::class,
            'title' => 'Pravila i dodaci',
        ],
    ];

    public function getList(): DataList
    {
        if ($this->modelClass === RosterSettings::class) {
            // guarantees the single record exists
            RosterSettings::current();
        }

        return parent::getList();
    }
}
