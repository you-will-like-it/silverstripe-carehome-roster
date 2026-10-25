<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Model\Task\DaysOfWeek;
use YouWillLikeIT\CareHome\Roster\Admin\RosterAdmin;
use YouWillLikeIT\CareHome\Roster\Admin\RosterSetupAdmin;
use YouWillLikeIT\CareHome\Roster\Domain\RuleConfig;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\RosterSeeder;
use YouWillLikeIT\CareHome\Security\CareHomePermissions;

/**
 * Single settings record: working-time rules, night window, allowance percentages, leave rules.
 * Rule values are seeded from a preset (default FBiH) and stay editable in the CMS.
 *
 * @property string $Jurisdiction
 * @property float $MinDailyRestHours
 * @property float $MinWeeklyRestHours
 * @property float $MaxShiftHours
 * @property float $MaxWeeklyHours
 * @property int $MaxConsecutiveDays
 * @property string $NightStart
 * @property string $NightEnd
 * @property float $NightAllowancePct
 * @property float $SundayAllowancePct
 * @property float $HolidayAllowancePct
 * @property float $OvertimeAllowancePct
 * @property string $LeaveWorkdays JSON (DaysOfWeek)
 * @property string $CarryOverDeadline "MM-DD"
 * @property int $ContractWarningDays
 * @property bool $EmailNotifications
 */
class RosterSettings extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_Settings';

    /**
     * Rule presets. Values are starting points — verify against the applicable law and the
     * collective agreement of the facility. Add own presets in app/_config.
     */
    private static array $presets = [
        'FBiH' => [
            'label' => 'BiH – Federacija BiH (Zakon o radu FBiH)',
            'MinDailyRestHours' => 12,
            'MinWeeklyRestHours' => 24,
            'MaxShiftHours' => 12,
            'MaxWeeklyHours' => 50,
            'MaxConsecutiveDays' => 6,
            'NightStart' => '22:00',
            'NightEnd' => '06:00',
            'CarryOverDeadline' => '06-30',
        ],
        'DE' => [
            'label' => 'Deutschland (ArbZG)',
            'MinDailyRestHours' => 11,
            'MinWeeklyRestHours' => 24,
            'MaxShiftHours' => 10,
            'MaxWeeklyHours' => 48,
            'MaxConsecutiveDays' => 6,
            'NightStart' => '23:00',
            'NightEnd' => '06:00',
            'CarryOverDeadline' => '03-31',
        ],
    ];

    private static string $default_preset = 'FBiH';

    private static array $db = [
        'Jurisdiction' => 'Varchar(30)',
        'MinDailyRestHours' => 'Decimal(4,1)',
        'MinWeeklyRestHours' => 'Decimal(4,1)',
        'MaxShiftHours' => 'Decimal(4,1)',
        'MaxWeeklyHours' => 'Decimal(5,1)',
        'MaxConsecutiveDays' => 'Int',
        'NightStart' => 'Time',
        'NightEnd' => 'Time',
        'NightAllowancePct' => 'Decimal(5,2)',
        'SundayAllowancePct' => 'Decimal(5,2)',
        'HolidayAllowancePct' => 'Decimal(5,2)',
        'OvertimeAllowancePct' => 'Decimal(5,2)',
        'LeaveWorkdays' => 'Varchar(40)',
        'CarryOverDeadline' => 'Varchar(5)',
        'ContractWarningDays' => 'Int',
        'EmailNotifications' => 'Boolean(1)',
    ];

    private static array $defaults = [
        'ContractWarningDays' => 30,
        'EmailNotifications' => true,
    ];

    private static ?self $cached = null;

    public static function current(): self
    {
        if (self::$cached && self::$cached->isInDB()) {
            return self::$cached;
        }
        $settings = self::get()->sort('ID')->first();
        if (!$settings) {
            $settings = self::create();
            $settings->applyPreset((string) static::config()->get('default_preset'));
            $settings->write();
        }

        return self::$cached = $settings;
    }

    public static function resetCache(): void
    {
        self::$cached = null;
    }

    public function applyPreset(string $key): void
    {
        $preset = (array) (static::config()->get('presets')[$key] ?? []);
        if ($preset === []) {
            return;
        }
        $this->Jurisdiction = $key;
        foreach ($preset as $field => $value) {
            if ($field !== 'label' && array_key_exists($field, (array) static::config()->get('db'))) {
                $this->setField($field, $value);
            }
        }
        if (!$this->LeaveWorkdays) {
            $this->LeaveWorkdays = DaysOfWeek::encode([1, 2, 3, 4, 5]);
        }
    }

    public function toRuleConfig(): RuleConfig
    {
        return new RuleConfig(
            (float) $this->MinDailyRestHours,
            (float) $this->MinWeeklyRestHours,
            (float) $this->MaxShiftHours,
            (float) $this->MaxWeeklyHours,
            (int) $this->MaxConsecutiveDays,
        );
    }

    /** @return list<int> */
    public function leaveWorkdays(): array
    {
        $days = DaysOfWeek::parse($this->LeaveWorkdays);

        return $days ?: [1, 2, 3, 4, 5];
    }

    public function nightStart(): string
    {
        return substr((string) ($this->NightStart ?: '22:00'), 0, 5);
    }

    public function nightEnd(): string
    {
        return substr((string) ($this->NightEnd ?: '06:00'), 0, 5);
    }

    /** "MM-DD" or null when carried-over leave never expires. */
    public function carryOverDeadline(): ?string
    {
        $value = trim((string) $this->CarryOverDeadline);

        return preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value) ? $value : null;
    }

    /* ------------------------------------------------------------- CMS */

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Jurisdiction' => _t(__CLASS__ . '.JURISDICTION', 'Pravni okvir'),
            'MinDailyRestHours' => _t(__CLASS__ . '.DAILY_REST', 'Min. dnevni odmor (h)'),
            'MinWeeklyRestHours' => _t(__CLASS__ . '.WEEKLY_REST', 'Min. sedmični odmor (h, neprekidno)'),
            'MaxShiftHours' => _t(__CLASS__ . '.MAX_SHIFT', 'Max. trajanje smjene (h)'),
            'MaxWeeklyHours' => _t(__CLASS__ . '.MAX_WEEK', 'Max. sati sedmično (sa prekovremenim)'),
            'MaxConsecutiveDays' => _t(__CLASS__ . '.MAX_DAYS', 'Max. uzastopnih radnih dana'),
            'NightStart' => _t(__CLASS__ . '.NIGHT_START', 'Noćni rad od'),
            'NightEnd' => _t(__CLASS__ . '.NIGHT_END', 'Noćni rad do'),
            'NightAllowancePct' => _t(__CLASS__ . '.NIGHT_PCT', 'Dodatak za noćni rad (%)'),
            'SundayAllowancePct' => _t(__CLASS__ . '.SUNDAY_PCT', 'Dodatak za rad nedjeljom (%)'),
            'HolidayAllowancePct' => _t(__CLASS__ . '.HOLIDAY_PCT', 'Dodatak za rad na praznik (%)'),
            'OvertimeAllowancePct' => _t(__CLASS__ . '.OVERTIME_PCT', 'Dodatak za prekovremeni rad (%)'),
            'LeaveWorkdays' => _t(__CLASS__ . '.LEAVE_DAYS', 'Dani koji se računaju u godišnji odmor'),
            'CarryOverDeadline' => _t(__CLASS__ . '.CARRY', 'Preneseni GO važi do (MM-DD)'),
            'ContractWarningDays' => _t(__CLASS__ . '.CONTRACT_WARN', 'Upozorenje pred istek ugovora (dana)'),
            'EmailNotifications' => _t(__CLASS__ . '.EMAIL', 'Obavještenja i e-mailom'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Postavke rasporeda');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Postavke rasporeda');
    }

    public function getTitle(): string
    {
        return $this->singular_name();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Jurisdiction', 'LeaveWorkdays']);

        $presets = [];
        foreach ((array) static::config()->get('presets') as $key => $preset) {
            $presets[$key] = (string) ($preset['label'] ?? $key);
        }

        $fields->addFieldsToTab('Root.Main', [
            LiteralField::create('Hint', '<p class="message notice">' . htmlspecialchars(_t(
                __CLASS__ . '.HINT',
                'Pravila daju upozorenja pri planiranju. Planer ih može svjesno prekoračiti uz obavezan razlog, koji se upisuje u audit log. Vrijednosti uskladite sa zakonom i kolektivnim ugovorom.'
            )) . '</p>'),
            DropdownField::create('Jurisdiction', $this->fieldLabel('Jurisdiction'), $presets)
                ->setDescription(_t(__CLASS__ . '.JURISDICTION_DESC', 'Informativno. Za učitavanje vrijednosti označite polje ispod.')),
            DropdownField::create('ApplyPreset', _t(__CLASS__ . '.APPLY_PRESET', 'Učitaj vrijednosti iz okvira'), $presets)
                ->setEmptyString(_t(__CLASS__ . '.APPLY_NONE', '— ne mijenjaj —'))
                ->setDescription(_t(__CLASS__ . '.APPLY_DESC', 'Prepisuje pravila i noćni period pri snimanju.')),
            HeaderField::create('RulesHeader', _t(__CLASS__ . '.RULES', 'Pravila radnog vremena (0 = ne provjerava se)')),
        ], 'MinDailyRestHours');

        $fields->addFieldToTab('Root.Main', HeaderField::create('NightHeader', _t(__CLASS__ . '.NIGHT', 'Noćni rad i dodaci')), 'NightStart');
        $fields->addFieldToTab('Root.Main', HeaderField::create('LeaveHeader', _t(__CLASS__ . '.LEAVE', 'Godišnji odmor')), 'CarryOverDeadline');
        $fields->addFieldToTab(
            'Root.Main',
            CheckboxSetField::create('LeaveWorkdays', $this->fieldLabel('LeaveWorkdays'), DaysOfWeek::options()),
            'CarryOverDeadline'
        );
        $fields->dataFieldByName('CarryOverDeadline')?->setDescription(_t(
            __CLASS__ . '.CARRY_DESC',
            'Neiskorišteni dani iz prethodne godine troše se prvi i ističu nakon ovog datuma. Prazno = ne ističu.'
        ));
        $fields->dataFieldByName('NightAllowancePct')?->setDescription(_t(
            __CLASS__ . '.PCT_DESC',
            'Iznos prema kolektivnom ugovoru. Izvještaj prikazuje sate po kategoriji i ove procente.'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        foreach (['MinDailyRestHours' => 24, 'MinWeeklyRestHours' => 72, 'MaxShiftHours' => 24, 'MaxWeeklyHours' => 168] as $field => $max) {
            $value = (float) $this->getField($field);
            if ($value < 0 || $value > $max) {
                $result->addFieldError($field, _t(__CLASS__ . '.RANGE', 'Vrijednost mora biti između 0 i {max}.', ['max' => $max]));
            }
        }
        if ((int) $this->MaxConsecutiveDays < 0 || (int) $this->MaxConsecutiveDays > 31) {
            $result->addFieldError('MaxConsecutiveDays', _t(__CLASS__ . '.RANGE', 'Vrijednost mora biti između 0 i {max}.', ['max' => 31]));
        }
        foreach (['NightAllowancePct', 'SundayAllowancePct', 'HolidayAllowancePct', 'OvertimeAllowancePct'] as $field) {
            if ((float) $this->getField($field) < 0 || (float) $this->getField($field) > 500) {
                $result->addFieldError($field, _t(__CLASS__ . '.RANGE', 'Vrijednost mora biti između 0 i {max}.', ['max' => 500]));
            }
        }
        if (trim((string) $this->CarryOverDeadline) !== '' && $this->carryOverDeadline() === null) {
            $result->addFieldError('CarryOverDeadline', _t(__CLASS__ . '.CARRY_FORMAT', 'Format MM-DD, npr. 06-30.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $preset = (string) $this->getField('ApplyPreset');
        if ($preset !== '') {
            $this->applyPreset($preset);
        }
        $this->setField('ApplyPreset', null);
        $this->LeaveWorkdays = DaysOfWeek::encode(DaysOfWeek::parse($this->LeaveWorkdays) ?: [1, 2, 3, 4, 5]);
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        self::$cached = null;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member) && !self::get()->exists();
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * Permissions for the CareHome groups + catalog seed (idempotent, empty tables only).
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        $grants = [
            CareHomePermissions::GROUP_ADMINS => [
                RosterPermissions::PLAN,
                RosterPermissions::REPORTS,
                RosterPermissions::SELF,
                'CMS_ACCESS_' . RosterAdmin::class,
                'CMS_ACCESS_' . RosterSetupAdmin::class,
            ],
            CareHomePermissions::GROUP_WORKERS => [RosterPermissions::SELF],
            CareHomePermissions::GROUP_KITCHEN => [RosterPermissions::SELF],
        ];
        $titles = [
            CareHomePermissions::GROUP_ADMINS => _t(MemberCareExtension::class . '.GROUP_ADMINS', 'Dom – Administratori'),
            CareHomePermissions::GROUP_WORKERS => _t(MemberCareExtension::class . '.GROUP_WORKERS', 'Dom – Njegovatelji'),
            CareHomePermissions::GROUP_KITCHEN => _t(MemberCareExtension::class . '.GROUP_KITCHEN', 'Dom – Kuhinja'),
        ];
        foreach ($grants as $code => $permissions) {
            $group = Group::get()->filter('Code', $code)->first();
            if (!$group) {
                // same code/title as the base module, which adds its own permissions to it
                $group = Group::create();
                $group->Code = $code;
                $group->Title = $titles[$code];
                $group->write();
            }
            foreach ($permissions as $permission) {
                if (!Permission::get()->filter(['GroupID' => (int) $group->ID, 'Code' => $permission])->exists()) {
                    Permission::grant((int) $group->ID, $permission);
                }
            }
        }

        self::current();
        RosterSeeder::singleton()->seed();
    }
}
