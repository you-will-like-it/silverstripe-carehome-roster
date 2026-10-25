<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use DateTimeInterface;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Model\Facility\FacilityZone;
use YouWillLikeIT\CareHome\Model\Task\DaysOfWeek;

/**
 * Minimum staffing per shift type, optionally per qualification and zone and weekdays.
 * Shortfalls are shown as warnings in the planning board, they never block.
 *
 * @property int $MinCount
 * @property string|null $Weekdays JSON (DaysOfWeek), empty = every day
 * @property string $Note
 * @property int $ShiftTypeID
 * @property int $QualificationID
 * @property int $ZoneID
 * @method ShiftType ShiftType()
 * @method Qualification Qualification()
 * @method FacilityZone Zone()
 */
class StaffingRequirement extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_StaffingRequirement';

    private static array $db = [
        'MinCount' => 'Int',
        'Weekdays' => 'Varchar(40)',
        'Note' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'ShiftType' => ShiftType::class,
        'Qualification' => Qualification::class,
        'Zone' => FacilityZone::class,
    ];

    private static array $defaults = [
        'MinCount' => 1,
    ];

    private static array $summary_fields = ['ShiftType.Code', 'QualificationLabel', 'ZoneLabel', 'WeekdayLabel', 'MinCount'];

    private static array $casting = [
        'QualificationLabel' => 'Varchar',
        'ZoneLabel' => 'Varchar',
        'WeekdayLabel' => 'Varchar',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'ShiftType' => _t(__CLASS__ . '.SHIFT', 'Smjena'),
            'ShiftType.Code' => _t(__CLASS__ . '.SHIFT', 'Smjena'),
            'Qualification' => _t(__CLASS__ . '.QUALIFICATION', 'Kvalifikacija'),
            'QualificationLabel' => _t(__CLASS__ . '.QUALIFICATION', 'Kvalifikacija'),
            'Zone' => _t(__CLASS__ . '.ZONE', 'Zona'),
            'ZoneLabel' => _t(__CLASS__ . '.ZONE', 'Zona'),
            'Weekdays' => _t(__CLASS__ . '.WEEKDAYS', 'Dani'),
            'WeekdayLabel' => _t(__CLASS__ . '.WEEKDAYS', 'Dani'),
            'MinCount' => _t(__CLASS__ . '.MIN', 'Minimalno'),
            'Note' => _t(__CLASS__ . '.NOTE', 'Napomena'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Minimalna popunjenost');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Minimalna popunjenost');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ShiftTypeID', 'QualificationID', 'ZoneID', 'Weekdays']);

        $zones = [];
        foreach (FacilityZone::get() as $zone) {
            $zones[(int) $zone->ID] = $zone->getBreadcrumb();
        }
        asort($zones, SORT_NATURAL | SORT_FLAG_CASE);

        $fields->addFieldsToTab('Root.Main', [
            DropdownField::create('ShiftTypeID', $this->fieldLabel('ShiftType'), ShiftType::optionsForRole()),
            DropdownField::create('QualificationID', $this->fieldLabel('Qualification'), Qualification::options())
                ->setEmptyString(_t(__CLASS__ . '.ANY_QUALIFICATION', '— bilo koja —')),
            DropdownField::create('ZoneID', $this->fieldLabel('Zone'), $zones)
                ->setEmptyString(_t(__CLASS__ . '.ALL_ZONES', '— cijeli dom —'))
                ->setDescription(_t(
                    __CLASS__ . '.ZONE_DESC',
                    'Broji se radnik kojem je dodijeljena ova zona ili zona iznad nje.'
                )),
            CheckboxSetField::create('Weekdays', $this->fieldLabel('Weekdays'), DaysOfWeek::options())
                ->setDescription(_t(__CLASS__ . '.WEEKDAYS_DESC', 'Ništa označeno = svaki dan.')),
        ], 'MinCount');

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->ShiftTypeID) {
            $result->addFieldError('ShiftTypeID', _t(__CLASS__ . '.SHIFT_REQUIRED', 'Smjena je obavezna.'));
        }
        if ((int) $this->MinCount < 1 || (int) $this->MinCount > 50) {
            $result->addFieldError('MinCount', _t(__CLASS__ . '.MIN_RANGE', 'Minimalno 1–50.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->Weekdays = DaysOfWeek::encode(DaysOfWeek::parse($this->Weekdays));
    }

    public function appliesOn(DateTimeInterface $date): bool
    {
        $days = DaysOfWeek::parse($this->Weekdays);

        return $days === [] || DaysOfWeek::matches($days, $date);
    }

    public function getQualificationLabel(): string
    {
        return $this->QualificationID
            ? (string) $this->Qualification()->Title
            : _t(__CLASS__ . '.ANY_QUALIFICATION', '— bilo koja —');
    }

    public function getZoneLabel(): string
    {
        return $this->ZoneID
            ? $this->Zone()->getBreadcrumb()
            : _t(__CLASS__ . '.ALL_ZONES', '— cijeli dom —');
    }

    public function getWeekdayLabel(): string
    {
        $days = DaysOfWeek::parse($this->Weekdays);

        return $days === [] ? DaysOfWeek::label([1, 2, 3, 4, 5, 6, 7]) : DaysOfWeek::label($days);
    }

    public function getTitle(): string
    {
        return $this->ShiftType()->Code . ' · ' . $this->getQualificationLabel() . ' · min ' . (int) $this->MinCount;
    }
}
