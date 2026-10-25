<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Worker qualification (medicinska sestra, njegovatelj, kuhar …). Used for minimum staffing.
 *
 * @property string $Title
 * @property string $Code
 * @property int $Sort
 * @property bool $IsActive
 */
class Qualification extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_Qualification';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Code' => 'Varchar(10)',
        'Sort' => 'Int',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $defaults = [
        'IsActive' => true,
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = ['Code', 'Title', 'IsActive.Nice'];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Code' => _t(__CLASS__ . '.CODE', 'Oznaka'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'IsActive' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
            'IsActive.Nice' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Kvalifikacija');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Kvalifikacije');
    }

    /** @return array<int,string> */
    public static function options(bool $activeOnly = true): array
    {
        $list = self::get();
        if ($activeOnly) {
            $list = $list->filter('IsActive', true);
        }

        return $list->map('ID', 'Title')->toArray();
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        CatalogValidation::requireTitleAndCode($this, $result, 10);

        return $result;
    }

    protected function isInUse(): bool
    {
        return $this->isInDB() && (
            Member::get()->filter('RosterQualificationID', (int) $this->ID)->exists()
            || StaffingRequirement::get()->filter('QualificationID', (int) $this->ID)->exists()
        );
    }
}
