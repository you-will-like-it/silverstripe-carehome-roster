<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Service\LeaveBalanceService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;

/**
 * Annual leave entitlement of one worker for one year. Created automatically with the
 * contract default (and the unused rest of the previous year as carry-over); editable for
 * pro-rata years, seniority days, collective agreement extras.
 *
 * @property int $Year
 * @property int $Days
 * @property int $CarriedOver
 * @property string|null $Note
 * @property int $MemberID
 * @method Member Member()
 */
class LeaveEntitlement extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_LeaveEntitlement';

    private static array $db = [
        'Year' => 'Int',
        'Days' => 'Int',
        'CarriedOver' => 'Int',
        'Note' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'MemberYear' => ['type' => 'unique', 'columns' => ['MemberID', 'Year']],
    ];

    private static string $default_sort = '"Year" DESC';

    private static array $summary_fields = [
        'Member.Name',
        'Year',
        'Days',
        'CarriedOver',
        'UsedDays',
        'PendingDays',
        'RemainingDays',
    ];

    private static array $searchable_fields = [
        'Member.Surname',
        'Year',
    ];

    private static array $casting = [
        'UsedDays' => 'Int',
        'PendingDays' => 'Int',
        'RemainingDays' => 'Int',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Member' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'Member.Name' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'Member.Surname' => _t(__CLASS__ . '.SURNAME', 'Prezime'),
            'Year' => _t(__CLASS__ . '.YEAR', 'Godina'),
            'Days' => _t(__CLASS__ . '.DAYS', 'Pravo (dana)'),
            'CarriedOver' => _t(__CLASS__ . '.CARRIED', 'Preneseno'),
            'UsedDays' => _t(__CLASS__ . '.USED', 'Iskorišteno'),
            'PendingDays' => _t(__CLASS__ . '.PENDING', 'Na čekanju'),
            'RemainingDays' => _t(__CLASS__ . '.REMAINING', 'Preostalo'),
            'Note' => _t(__CLASS__ . '.NOTE', 'Napomena'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Godišnji odmor – pravo');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Godišnji odmor – saldo');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('MemberID', DropdownField::create('MemberID', $this->fieldLabel('Member'), WorkerDirectory::singleton()->options(true))
            ->setEmptyString(_t(Absence::class . '.CHOOSE', '— odaberite —')));

        if ($this->isInDB()) {
            $balance = LeaveBalanceService::singleton()->balanceFor($this);
            $fields->addFieldToTab('Root.Main', LiteralField::create('Balance', sprintf(
                '<p class="message notice">%s</p>',
                htmlspecialchars(_t(__CLASS__ . '.BALANCE', 'Iskorišteno {used}, na čekanju {pending}, preostalo {remaining} (od toga prenesenih {carry}).', [
                    'used' => $balance->used(),
                    'pending' => $balance->pending,
                    'remaining' => $balance->remaining(),
                    'carry' => $balance->carryRemaining,
                ]))
            )), 'Year');
        }
        $fields->dataFieldByName('CarriedOver')?->setDescription(_t(
            __CLASS__ . '.CARRIED_DESC',
            'Neiskorišteni dani iz prethodne godine; ističu prema postavci „Preneseni GO važi do".'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->MemberID) {
            $result->addFieldError('MemberID', _t(__CLASS__ . '.MEMBER_REQUIRED', 'Radnik je obavezan.'));
        }
        if ((int) $this->Year < 2000 || (int) $this->Year > 2100) {
            $result->addFieldError('Year', _t(__CLASS__ . '.YEAR_RANGE', 'Neispravna godina.'));
        }
        if ((int) $this->Days < 0 || (int) $this->CarriedOver < 0) {
            $result->addError(_t(__CLASS__ . '.NEGATIVE', 'Broj dana ne može biti negativan.'));
        }
        if ($this->MemberID && self::get()->filter(['MemberID' => (int) $this->MemberID, 'Year' => (int) $this->Year])->exclude('ID', (int) $this->ID)->exists()) {
            $result->addFieldError('Year', _t(__CLASS__ . '.DUPLICATE', 'Za ovog radnika i godinu već postoji zapis.'));
        }

        return $result;
    }

    public function getUsedDays(): int
    {
        return LeaveBalanceService::singleton()->balanceFor($this)->used();
    }

    public function getPendingDays(): int
    {
        return LeaveBalanceService::singleton()->balanceFor($this)->pending;
    }

    public function getRemainingDays(): int
    {
        return LeaveBalanceService::singleton()->balanceFor($this)->remaining();
    }

    public function getTitle(): string
    {
        return $this->Member()->getName() . ' · ' . (int) $this->Year;
    }
}
