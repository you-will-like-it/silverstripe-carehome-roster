<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Security;

use SilverStripe\Security\Member;
use SilverStripe\Security\PermissionProvider;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Permission codes of the roster add-on. Granted to the CareHome groups in
 * RosterSettings::requireDefaultRecords().
 */
final class RosterPermissions implements PermissionProvider
{
    /** Plan shifts, decide absences/swaps, manage catalogs and settings */
    public const string PLAN = 'CAREHOME_ROSTER_PLAN';

    /** Hours report, plan PDF/CSV */
    public const string REPORTS = 'CAREHOME_ROSTER_REPORTS';

    /** Own schedule, leave requests and swaps in /raspored */
    public const string SELF = 'CAREHOME_ROSTER_SELF';

    public function providePermissions(): array
    {
        $category = _t(CareHomePermissions::class . '.CATEGORY', 'Dom za njegu');

        return [
            self::PLAN => [
                'name' => _t(__CLASS__ . '.PLAN', 'Raspored smjena: planiranje i odobravanje'),
                'category' => $category,
            ],
            self::REPORTS => [
                'name' => _t(__CLASS__ . '.REPORTS', 'Raspored smjena: izvještaji o satima'),
                'category' => $category,
            ],
            self::SELF => [
                'name' => _t(__CLASS__ . '.SELF', 'Raspored smjena: vlastiti raspored i zahtjevi'),
                'category' => $category,
            ],
        ];
    }

    public static function check(string $code, ?Member $member = null): bool
    {
        return CareHomePermissions::check($code, $member);
    }

    public static function isSuperAdmin(?Member $member = null): bool
    {
        return CareHomePermissions::isSuperAdmin($member);
    }
}
