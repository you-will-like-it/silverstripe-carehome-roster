<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use DateTimeImmutable;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Control\Admin\PlanBoardController;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * One month of the plan. Draft → Published → Locked.
 *
 * - Draft: invisible to workers.
 * - Published: workers see it; every change is flagged ChangedAfterPublish and notified.
 * - Locked: month is closed (hours reported); no changes. Only a superadmin unlocks, with reason.
 *
 * Status changes happen only through PlanningService (publish/lock/unlock).
 *
 * @property int $Year
 * @property int $Month
 * @property string $Status
 * @property string|null $PublishedAt
 * @property string|null $LockedAt
 * @property string|null $UnlockReason
 * @property int $PublishedByID
 * @property int $LockedByID
 * @method Member PublishedBy()
 * @method Member LockedBy()
 * @method HasManyList<ShiftAssignment> Assignments()
 */
class RosterPeriod extends DataObject
{
    public const string STATUS_DRAFT = 'Draft';
    public const string STATUS_PUBLISHED = 'Published';
    public const string STATUS_LOCKED = 'Locked';

    private static string $table_name = 'CareHomeRoster_Period';

    private static array $db = [
        'Year' => 'Int',
        'Month' => 'Int',
        'Status' => "Enum('Draft,Published,Locked','Draft')",
        'PublishedAt' => 'Datetime',
        'LockedAt' => 'Datetime',
        'UnlockReason' => 'Text',
    ];

    private static array $has_one = [
        'PublishedBy' => Member::class,
        'LockedBy' => Member::class,
    ];

    private static array $has_many = [
        'Assignments' => ShiftAssignment::class . '.Period',
    ];

    private static array $indexes = [
        'YearMonth' => ['type' => 'unique', 'columns' => ['Year', 'Month']],
    ];

    private static string $default_sort = '"Year" DESC, "Month" DESC';

    private static array $summary_fields = ['Label', 'StatusLabel', 'PublishedAt.Nice', 'LockedAt.Nice'];

    private static array $casting = [
        'Label' => 'Varchar',
        'StatusLabel' => 'Varchar',
    ];

    /** @return array<string,string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => _t(__CLASS__ . '.DRAFT', 'Nacrt'),
            self::STATUS_PUBLISHED => _t(__CLASS__ . '.PUBLISHED', 'Objavljen'),
            self::STATUS_LOCKED => _t(__CLASS__ . '.LOCKED', 'Zaključan'),
        ];
    }

    public static function forDate(DateTimeImmutable $date, bool $create = true): ?self
    {
        return self::forMonth((int) $date->format('Y'), (int) $date->format('n'), $create);
    }

    public static function forMonth(int $year, int $month, bool $create = true): ?self
    {
        /** @var self|null $period */
        $period = self::get()->filter(['Year' => $year, 'Month' => $month])->first();
        if (!$period && $create) {
            $period = self::create(['Year' => $year, 'Month' => $month, 'Status' => self::STATUS_DRAFT]);
            $period->write();
        }

        return $period;
    }

    public function firstDay(): DateTimeImmutable
    {
        return Clock::singleton()->combine(sprintf('%04d-%02d-01', $this->Year, $this->Month), '00:00');
    }

    public function lastDay(): DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month');
    }

    public function isDraft(): bool
    {
        return $this->Status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->Status === self::STATUS_PUBLISHED;
    }

    public function isLocked(): bool
    {
        return $this->Status === self::STATUS_LOCKED;
    }

    public function getLabel(): string
    {
        return sprintf('%02d/%04d', $this->Month, $this->Year);
    }

    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Raspored {label}', ['label' => $this->getLabel()]);
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->Status] ?? (string) $this->Status;
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Label' => _t(__CLASS__ . '.MONTH', 'Mjesec'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'PublishedAt' => _t(__CLASS__ . '.PUBLISHED_AT', 'Objavljen'),
            'PublishedAt.Nice' => _t(__CLASS__ . '.PUBLISHED_AT', 'Objavljen'),
            'LockedAt' => _t(__CLASS__ . '.LOCKED_AT', 'Zaključan'),
            'LockedAt.Nice' => _t(__CLASS__ . '.LOCKED_AT', 'Zaključan'),
            'UnlockReason' => _t(__CLASS__ . '.UNLOCK_REASON', 'Razlog zadnjeg otključavanja'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Mjesečni raspored');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Mjesečni rasporedi');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Assignments']);
        $fields = $fields->makeReadonly();
        $fields->addFieldToTab('Root.Main', LiteralField::create('BoardLink', sprintf(
            '<p><a class="btn btn-primary" href="%s">%s</a></p>',
            htmlspecialchars(PlanBoardController::monthLink((int) $this->Year, (int) $this->Month)),
            htmlspecialchars(_t(__CLASS__ . '.OPEN_BOARD', 'Otvori planer'))
        )), 'Year');

        return $fields;
    }

    public function canView($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member)
            || RosterPermissions::check(RosterPermissions::REPORTS, $member);
    }

    /** Edited only through the planning board / PlanningService. */
    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
