<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\ShiftResolver;

/**
 * Plannable shift type ("J 06–14", "D12 07–19", "Kuhinja 06–14").
 *
 * ResolverCode links it to a care shift of the base module (ShiftResolver, used for handover);
 * seeded from that configuration. Assignments snapshot start/end/break, so later changes here
 * never alter past plans or hour reports.
 *
 * @property string $Title
 * @property string $Code
 * @property string $StartTime
 * @property string $EndTime
 * @property int $BreakMinutes
 * @property string $Colour
 * @property bool $AppliesToCare
 * @property bool $AppliesToKitchen
 * @property bool $AppliesToAdmin
 * @property string|null $ResolverCode
 * @property int $Sort
 * @property bool $IsActive
 */
class ShiftType extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_ShiftType';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Code' => 'Varchar(10)',
        'StartTime' => 'Time',
        'EndTime' => 'Time',
        'BreakMinutes' => 'Int',
        'Colour' => 'Varchar(7)',
        'AppliesToCare' => 'Boolean(1)',
        'AppliesToKitchen' => 'Boolean(0)',
        'AppliesToAdmin' => 'Boolean(0)',
        'ResolverCode' => 'Varchar(30)',
        'Sort' => 'Int',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $defaults = [
        'Colour' => '#0e7490',
        'AppliesToCare' => true,
        'IsActive' => true,
    ];

    private static string $default_sort = '"Sort" ASC, "StartTime" ASC';

    private static array $summary_fields = ['Code', 'Title', 'TimeRange', 'RoleList', 'IsActive.Nice'];

    private static array $casting = [
        'TimeRange' => 'Varchar',
        'RoleList' => 'Varchar',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Code' => _t(__CLASS__ . '.CODE', 'Oznaka'),
            'StartTime' => _t(__CLASS__ . '.START', 'Početak'),
            'EndTime' => _t(__CLASS__ . '.END', 'Kraj'),
            'BreakMinutes' => _t(__CLASS__ . '.BREAK', 'Pauza (min, neplaćena)'),
            'Colour' => _t(__CLASS__ . '.COLOUR', 'Boja'),
            'AppliesToCare' => _t(__CLASS__ . '.CARE', 'Njegovatelji'),
            'AppliesToKitchen' => _t(__CLASS__ . '.KITCHEN', 'Kuhinja'),
            'AppliesToAdmin' => _t(__CLASS__ . '.ADMIN', 'Administratori'),
            'ResolverCode' => _t(__CLASS__ . '.RESOLVER', 'Smjena za primopredaju'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'IsActive' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
            'IsActive.Nice' => _t(__CLASS__ . '.ACTIVE', 'Aktivna'),
            'TimeRange' => _t(__CLASS__ . '.TIME', 'Vrijeme'),
            'RoleList' => _t(__CLASS__ . '.ROLES', 'Za uloge'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Tip smjene');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Tipovi smjena');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['AppliesToCare', 'AppliesToKitchen', 'AppliesToAdmin', 'ResolverCode', 'Colour']);

        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Colour', $this->fieldLabel('Colour'))->setAttribute('type', 'color'),
            FieldGroup::create(_t(__CLASS__ . '.APPLIES', 'Može se planirati za'), [
                CheckboxField::create('AppliesToCare', $this->fieldLabel('AppliesToCare')),
                CheckboxField::create('AppliesToKitchen', $this->fieldLabel('AppliesToKitchen')),
                CheckboxField::create('AppliesToAdmin', $this->fieldLabel('AppliesToAdmin')),
            ]),
            DropdownField::create('ResolverCode', $this->fieldLabel('ResolverCode'), ShiftResolver::singleton()->options())
                ->setEmptyString(_t(__CLASS__ . '.RESOLVER_NONE', '— nije njegovateljska smjena —'))
                ->setDescription(_t(
                    __CLASS__ . '.RESOLVER_DESC',
                    'Smjena iz konfiguracije osnovnog modula (primopredaja). Ko radi ovu smjenu nudi se kao primalac primopredaje.'
                )),
        ], 'Sort');

        if ($mismatch = $this->resolverMismatch()) {
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'ResolverMismatch',
                '<p class="message warning">' . htmlspecialchars($mismatch) . '</p>'
            ), 'Title');
        }

        $fields->dataFieldByName('BreakMinutes')?->setDescription(_t(
            __CLASS__ . '.BREAK_DESC',
            'Odbija se od plaćenih sati. Promjena važi samo za nove dodjele.'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        CatalogValidation::requireTitleAndCode($this, $result, 10);
        CatalogValidation::requireColour($this, $result);

        if (!$this->StartTime || !$this->EndTime) {
            $result->addError(_t(__CLASS__ . '.TIMES_REQUIRED', 'Početak i kraj su obavezni.'));

            return $result;
        }
        $duration = $this->durationMinutes();
        if ($duration <= 0) {
            $result->addFieldError('EndTime', _t(__CLASS__ . '.DURATION', 'Kraj mora biti različit od početka.'));
        } elseif ((int) $this->BreakMinutes < 0 || (int) $this->BreakMinutes >= $duration) {
            $result->addFieldError('BreakMinutes', _t(__CLASS__ . '.BREAK_RANGE', 'Pauza mora biti kraća od smjene.'));
        }
        if (!$this->AppliesToCare && !$this->AppliesToKitchen && !$this->AppliesToAdmin) {
            $result->addError(_t(__CLASS__ . '.NO_ROLE', 'Odaberite barem jednu ulogu.'));
        }
        if ($this->ResolverCode && !array_key_exists((string) $this->ResolverCode, ShiftResolver::singleton()->options())) {
            $result->addFieldError('ResolverCode', _t(__CLASS__ . '.RESOLVER_UNKNOWN', 'Nepoznata smjena.'));
        }

        return $result;
    }

    protected function isInUse(): bool
    {
        return $this->isInDB() && ShiftAssignment::get()->filter('ShiftTypeID', (int) $this->ID)->exists();
    }

    /* ------------------------------------------------------------------ */

    /** Nominal duration in minutes (end ≤ start = runs past midnight). */
    public function durationMinutes(): int
    {
        $start = self::minutes((string) $this->StartTime);
        $end = self::minutes((string) $this->EndTime);
        if ($start === null || $end === null || $start === $end) {
            return 0;
        }

        return $end > $start ? $end - $start : 1440 - $start + $end;
    }

    /**
     * Absolute window for a calendar date (real clock, DST-aware).
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public function windowFor(DateTimeImmutable $date): array
    {
        $clock = Clock::singleton();
        $ymd = $date->format('Y-m-d');
        $start = $clock->combine($ymd, substr((string) $this->StartTime, 0, 5));
        $end = $clock->combine($ymd, substr((string) $this->EndTime, 0, 5));
        if ($end <= $start) {
            $end = $clock->combine($date->modify('+1 day')->format('Y-m-d'), substr((string) $this->EndTime, 0, 5));
        }

        return [$start, $end];
    }

    public function appliesToRole(string $role): bool
    {
        return match ($role) {
            MemberCareExtension::ROLE_CAREWORKER => (bool) $this->AppliesToCare,
            MemberCareExtension::ROLE_KITCHEN => (bool) $this->AppliesToKitchen,
            MemberCareExtension::ROLE_ADMIN => (bool) $this->AppliesToAdmin,
            default => false,
        };
    }

    public function getTimeRange(): string
    {
        return substr((string) $this->StartTime, 0, 5) . '–' . substr((string) $this->EndTime, 0, 5);
    }

    public function getRoleList(): string
    {
        $roles = MemberCareExtension::roleOptions();
        $list = [];
        foreach ([
            MemberCareExtension::ROLE_CAREWORKER => $this->AppliesToCare,
            MemberCareExtension::ROLE_KITCHEN => $this->AppliesToKitchen,
            MemberCareExtension::ROLE_ADMIN => $this->AppliesToAdmin,
        ] as $role => $flag) {
            if ($flag) {
                $list[] = $roles[$role];
            }
        }

        return implode(', ', $list);
    }

    public function getFullTitle(): string
    {
        return $this->Code . ' · ' . $this->Title . ' (' . $this->getTimeRange() . ')';
    }

    /** Readable foreground for the colour chip. */
    public function TextColour(): string
    {
        return self::contrastColour((string) $this->Colour);
    }

    public static function contrastColour(string $hex): string
    {
        if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return '#ffffff';
        }
        $luminance = (0.299 * hexdec($m[1]) + 0.587 * hexdec($m[2]) + 0.114 * hexdec($m[3])) / 255;

        return $luminance > 0.6 ? '#0f172a' : '#ffffff';
    }

    /**
     * Times differ from the linked ShiftResolver shift → planners see a warning.
     */
    public function resolverMismatch(): ?string
    {
        if (!$this->ResolverCode) {
            return null;
        }
        $config = (array) (ShiftResolver::config()->get('shifts')[$this->ResolverCode] ?? []);
        if (empty($config['start']) || empty($config['end'])) {
            return null;
        }
        if (substr((string) $config['start'], 0, 5) === substr((string) $this->StartTime, 0, 5)
            && substr((string) $config['end'], 0, 5) === substr((string) $this->EndTime, 0, 5)
        ) {
            return null;
        }

        return _t(__CLASS__ . '.RESOLVER_MISMATCH', 'Vrijeme se razlikuje od smjene „{code}" u konfiguraciji ({start}–{end}).', [
            'code' => $this->ResolverCode,
            'start' => $config['start'],
            'end' => $config['end'],
        ]);
    }

    /** @return array<int,string> active types for a role (ID => "Code · Title (06:00–14:00)") */
    public static function optionsForRole(?string $role = null): array
    {
        $options = [];
        foreach (self::get()->filter('IsActive', true) as $type) {
            if ($role === null || $type->appliesToRole($role)) {
                $options[(int) $type->ID] = $type->getFullTitle();
            }
        }

        return $options;
    }

    private static function minutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
