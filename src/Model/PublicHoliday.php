<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Roster\Service\HolidayCalendar;

/**
 * Public holiday. Recurring = same day every year (Nova godina, Dan državnosti …).
 * Movable holidays (Bajram, Uskrs) are entered per year.
 *
 * Seed: FBiH state holidays (Zakon o praznicima FBiH). Religious holidays in FBiH are days off
 * per worker's faith, not general holidays — record them as absence type "Vjerski praznik"
 * unless the facility closes for everyone.
 *
 * @property string $Title
 * @property string $Date
 * @property bool $IsRecurring
 */
class PublicHoliday extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_PublicHoliday';

    private static array $db = [
        'Title' => 'Varchar(150)',
        'Date' => 'Date',
        'IsRecurring' => 'Boolean(0)',
    ];

    private static array $indexes = [
        'Date' => true,
    ];

    private static string $default_sort = '"IsRecurring" DESC, "Date" ASC';

    private static array $summary_fields = ['DateLabel', 'Title', 'IsRecurring.Nice'];

    private static array $casting = ['DateLabel' => 'Varchar'];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Date' => _t(__CLASS__ . '.DATE', 'Datum'),
            'DateLabel' => _t(__CLASS__ . '.DATE', 'Datum'),
            'IsRecurring' => _t(__CLASS__ . '.RECURRING', 'Svake godine'),
            'IsRecurring.Nice' => _t(__CLASS__ . '.RECURRING', 'Svake godine'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Praznik');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Praznici');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('IsRecurring')?->setDescription(_t(
            __CLASS__ . '.RECURRING_DESC',
            'Godina se zanemaruje; važi isti dan i mjesec svake godine.'
        ));

        return $fields;
    }

    public function getDateLabel(): string
    {
        if (!$this->Date) {
            return '';
        }
        $ts = strtotime((string) $this->Date);

        return $this->IsRecurring ? date('d.m.', $ts) : date('d.m.Y.', $ts);
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(CatalogValidation::class . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }
        if (!$this->Date) {
            $result->addFieldError('Date', _t(__CLASS__ . '.DATE_REQUIRED', 'Datum je obavezan.'));
        }

        return $result;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        HolidayCalendar::singleton()->reset();
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        HolidayCalendar::singleton()->reset();
    }
}
