<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Contract type with default weekly hours and annual leave (overridable per worker).
 *
 * @property string $Title
 * @property float $WeeklyHours
 * @property int $AnnualLeaveDays
 * @property int $Sort
 * @property bool $IsActive
 */
class ContractType extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_ContractType';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'WeeklyHours' => 'Decimal(5,2)',
        'AnnualLeaveDays' => 'Int',
        'Sort' => 'Int',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $defaults = [
        'WeeklyHours' => 40,
        'AnnualLeaveDays' => 20,
        'IsActive' => true,
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = ['Title', 'WeeklyHours', 'AnnualLeaveDays', 'IsActive.Nice'];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'WeeklyHours' => _t(__CLASS__ . '.WEEKLY_HOURS', 'Sedmično sati'),
            'AnnualLeaveDays' => _t(__CLASS__ . '.LEAVE', 'Godišnji odmor (dana)'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'IsActive' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
            'IsActive.Nice' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Vrsta ugovora');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Vrste ugovora');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('AnnualLeaveDays')?->setDescription(_t(
            __CLASS__ . '.LEAVE_DESC',
            'Podrazumijevano pravo na godišnji odmor u radnim danima; može se promijeniti po radniku.'
        ));

        return $fields;
    }

    /** @return array<int,string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::get()->filter('IsActive', true) as $type) {
            $options[(int) $type->ID] = $type->Title . ' (' . (float) $type->WeeklyHours . ' h)';
        }

        return $options;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(CatalogValidation::class . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }
        $hours = (float) $this->WeeklyHours;
        if ($hours <= 0 || $hours > 80) {
            $result->addFieldError('WeeklyHours', _t(__CLASS__ . '.HOURS_RANGE', 'Sedmični sati moraju biti između 0 i 80.'));
        }
        if ((int) $this->AnnualLeaveDays < 0 || (int) $this->AnnualLeaveDays > 60) {
            $result->addFieldError('AnnualLeaveDays', _t(__CLASS__ . '.LEAVE_RANGE', 'Broj dana mora biti između 0 i 60.'));
        }

        return $result;
    }

    protected function isInUse(): bool
    {
        return $this->isInDB() && Member::get()->filter('RosterContractTypeID', (int) $this->ID)->exists();
    }
}
