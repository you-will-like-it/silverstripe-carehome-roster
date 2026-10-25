<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * Absence type (godišnji odmor, bolovanje, slobodan dan po želji …).
 *
 * - IsPreference: a wish only. Never blocks planning (warning), never opens shifts, no approval.
 * - RequiresApproval: false = takes effect immediately (e.g. sick leave reported by the worker).
 * - DeductsAnnualLeave: counted against the annual leave balance (workdays without holidays).
 * - CreditsHours: paid absence; reduces the monthly target hours in the hours report.
 *
 * @property string $Title
 * @property string $Code
 * @property string $Colour
 * @property bool $IsPreference
 * @property bool $RequiresApproval
 * @property bool $AllowSelfRequest
 * @property bool $DeductsAnnualLeave
 * @property bool $CreditsHours
 * @property bool $IsSickLeave
 * @property int $Sort
 * @property bool $IsActive
 */
class AbsenceType extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_AbsenceType';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Code' => 'Varchar(6)',
        'Colour' => 'Varchar(7)',
        'IsPreference' => 'Boolean(0)',
        'RequiresApproval' => 'Boolean(1)',
        'AllowSelfRequest' => 'Boolean(1)',
        'DeductsAnnualLeave' => 'Boolean(0)',
        'CreditsHours' => 'Boolean(1)',
        'IsSickLeave' => 'Boolean(0)',
        'Sort' => 'Int',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $defaults = [
        'Colour' => '#94a3b8',
        'RequiresApproval' => true,
        'AllowSelfRequest' => true,
        'CreditsHours' => true,
        'IsActive' => true,
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = [
        'Code',
        'Title',
        'RequiresApproval.Nice',
        'AllowSelfRequest.Nice',
        'DeductsAnnualLeave.Nice',
        'IsActive.Nice',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Code' => _t(__CLASS__ . '.CODE', 'Oznaka'),
            'Colour' => _t(__CLASS__ . '.COLOUR', 'Boja'),
            'IsPreference' => _t(__CLASS__ . '.PREFERENCE', 'Samo želja (ne blokira planiranje)'),
            'RequiresApproval' => _t(__CLASS__ . '.APPROVAL', 'Potrebno odobrenje'),
            'RequiresApproval.Nice' => _t(__CLASS__ . '.APPROVAL', 'Potrebno odobrenje'),
            'AllowSelfRequest' => _t(__CLASS__ . '.SELF', 'Radnik može sam upisati'),
            'AllowSelfRequest.Nice' => _t(__CLASS__ . '.SELF', 'Radnik može sam upisati'),
            'DeductsAnnualLeave' => _t(__CLASS__ . '.DEDUCTS', 'Troši godišnji odmor'),
            'DeductsAnnualLeave.Nice' => _t(__CLASS__ . '.DEDUCTS', 'Troši godišnji odmor'),
            'CreditsHours' => _t(__CLASS__ . '.CREDITS', 'Plaćeno (umanjuje normu sati)'),
            'IsSickLeave' => _t(__CLASS__ . '.SICK', 'Bolovanje (bez dijagnoze)'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'IsActive' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
            'IsActive.Nice' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Vrsta odsustva');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Vrste odsustva');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('Colour', TextField::create('Colour', $this->fieldLabel('Colour'))->setAttribute('type', 'color'));
        $fields->dataFieldByName('IsSickLeave')?->setDescription(_t(
            __CLASS__ . '.SICK_DESC',
            'Evidentira se samo period. Dijagnoza se ne unosi niti čuva.'
        ));
        $fields->dataFieldByName('RequiresApproval')?->setDescription(_t(
            __CLASS__ . '.APPROVAL_DESC',
            'Bez odobrenja odsustvo važi odmah, a smjene u tom periodu postaju otvorene za zamjenu.'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        CatalogValidation::requireTitleAndCode($this, $result, 6);
        CatalogValidation::requireColour($this, $result);

        if ($this->IsPreference && ($this->DeductsAnnualLeave || $this->IsSickLeave)) {
            $result->addFieldError('IsPreference', _t(
                __CLASS__ . '.PREFERENCE_CONFLICT',
                'Želja ne može trošiti godišnji odmor niti biti bolovanje.'
            ));
        }

        return $result;
    }

    protected function isInUse(): bool
    {
        return $this->isInDB() && Absence::get()->filter('TypeID', (int) $this->ID)->exists();
    }

    public function TextColour(): string
    {
        return ShiftType::contrastColour((string) $this->Colour);
    }

    /** @return array<int,string> */
    public static function options(bool $selfOnly = false): array
    {
        $list = self::get()->filter('IsActive', true);
        if ($selfOnly) {
            $list = $list->filter('AllowSelfRequest', true);
        }

        return $list->map('ID', 'Title')->toArray();
    }
}
