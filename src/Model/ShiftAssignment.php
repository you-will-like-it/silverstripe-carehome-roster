<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Domain\PlannedShift;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * One shift on one date, either assigned to a worker (Planned) or uncovered (Open).
 *
 * Start/end/break/code are snapshots of the shift type at planning time.
 * OriginalMember keeps the first planned worker when the shift was taken over (sick leave,
 * swap), so "who was planned" and "who actually worked" both stay visible; who actually
 * served a resident is recorded per task in DailyTask.CompletedBy of the base module.
 *
 * All writes go through PlanningService / AbsenceService / SwapService (validation, period
 * lock, notifications).
 *
 * @property string $Date
 * @property string $StartsAt
 * @property string $EndsAt
 * @property int $BreakMinutes
 * @property string $ShiftCode
 * @property string $ShiftTitle
 * @property string $Status
 * @property bool $ChangedAfterPublish
 * @property string|null $OverrideReason
 * @property string|null $OpenReason
 * @property string|null $Note
 * @property int $MemberID
 * @property int $OriginalMemberID
 * @property int $ShiftTypeID
 * @property int $PeriodID
 * @property int $AbsenceID
 * @method Member Member()
 * @method Member OriginalMember()
 * @method ShiftType ShiftType()
 * @method RosterPeriod Period()
 * @method Absence Absence()
 */
class ShiftAssignment extends DataObject
{
    public const string STATUS_PLANNED = 'Planned';
    public const string STATUS_OPEN = 'Open';

    private static string $table_name = 'CareHomeRoster_ShiftAssignment';

    private static array $db = [
        'Date' => 'Date',
        'StartsAt' => 'Datetime',
        'EndsAt' => 'Datetime',
        'BreakMinutes' => 'Int',
        'ShiftCode' => 'Varchar(10)',
        'ShiftTitle' => 'Varchar(100)',
        'Status' => "Enum('Planned,Open','Planned')",
        'ChangedAfterPublish' => 'Boolean(0)',
        'OverrideReason' => 'Text',
        'OpenReason' => 'Varchar(255)',
        'Note' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'OriginalMember' => Member::class,
        'ShiftType' => ShiftType::class,
        'Period' => RosterPeriod::class,
        'Absence' => Absence::class,
    ];

    private static array $indexes = [
        'MemberDate' => ['type' => 'index', 'columns' => ['MemberID', 'Date']],
        'MemberStart' => ['type' => 'index', 'columns' => ['MemberID', 'StartsAt']],
        'DateShift' => ['type' => 'index', 'columns' => ['Date', 'ShiftTypeID']],
        'StatusDate' => ['type' => 'index', 'columns' => ['Status', 'Date']],
    ];

    private static string $default_sort = '"Date" ASC, "StartsAt" ASC';

    private static array $summary_fields = [
        'Date.Nice',
        'ShiftCode',
        'TimeRange',
        'WorkerName',
        'StatusLabel',
        'OpenReason',
    ];

    private static array $casting = [
        'TimeRange' => 'Varchar',
        'WorkerName' => 'Varchar',
        'StatusLabel' => 'Varchar',
    ];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Date' => _t(__CLASS__ . '.DATE', 'Datum'),
            'Date.Nice' => _t(__CLASS__ . '.DATE', 'Datum'),
            'ShiftCode' => _t(__CLASS__ . '.SHIFT', 'Smjena'),
            'TimeRange' => _t(__CLASS__ . '.TIME', 'Vrijeme'),
            'Member' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'WorkerName' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'OriginalMember' => _t(__CLASS__ . '.ORIGINAL', 'Prvobitno planiran'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'OpenReason' => _t(__CLASS__ . '.OPEN_REASON', 'Razlog'),
            'OverrideReason' => _t(__CLASS__ . '.OVERRIDE', 'Razlog odstupanja od pravila'),
            'Note' => _t(__CLASS__ . '.NOTE', 'Napomena'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Dodjela smjene');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Dodjele smjena');
    }

    public function getCMSFields(): FieldList
    {
        return parent::getCMSFields()->makeReadonly();
    }

    /** Fill snapshot fields from the shift type for the given date. */
    public function applyShiftType(ShiftType $type, DateTimeImmutable $date): void
    {
        [$start, $end] = $type->windowFor($date);
        $clock = Clock::singleton();
        $this->ShiftTypeID = (int) $type->ID;
        $this->Date = $date->format('Y-m-d');
        $this->StartsAt = $clock->toDbDatetime($start);
        $this->EndsAt = $clock->toDbDatetime($end);
        $this->BreakMinutes = (int) $type->BreakMinutes;
        $this->ShiftCode = (string) $type->Code;
        $this->ShiftTitle = (string) $type->Title;
    }

    public function startsAt(): DateTimeImmutable
    {
        return Clock::singleton()->combine(substr((string) $this->StartsAt, 0, 10), substr((string) $this->StartsAt, 11, 8));
    }

    public function endsAt(): DateTimeImmutable
    {
        return Clock::singleton()->combine(substr((string) $this->EndsAt, 0, 10), substr((string) $this->EndsAt, 11, 8));
    }

    public function dateObject(): DateTimeImmutable
    {
        return Clock::singleton()->combine((string) $this->Date, '00:00');
    }

    public function toPlannedShift(): PlannedShift
    {
        return new PlannedShift((int) $this->ID, $this->startsAt(), $this->endsAt(), (int) $this->BreakMinutes, (string) $this->ShiftCode);
    }

    public function isOpen(): bool
    {
        return $this->Status === self::STATUS_OPEN;
    }

    public function isSubstitution(): bool
    {
        return !$this->isOpen()
            && $this->OriginalMemberID
            && (int) $this->OriginalMemberID !== (int) $this->MemberID;
    }

    public function getTimeRange(): string
    {
        return substr((string) $this->StartsAt, 11, 5) . '–' . substr((string) $this->EndsAt, 11, 5);
    }

    public function getWorkerName(): string
    {
        if ($this->isOpen()) {
            return _t(__CLASS__ . '.OPEN', 'nepopunjeno');
        }
        $name = (string) $this->Member()->getName();
        if ($this->isSubstitution()) {
            $name .= ' ' . _t(__CLASS__ . '.SUBSTITUTE_FOR', '(zamjena za {name})', ['name' => $this->OriginalMember()->getName()]);
        }

        return $name;
    }

    public function getStatusLabel(): string
    {
        return $this->isOpen()
            ? _t(__CLASS__ . '.STATUS_OPEN', 'Otvorena')
            : _t(__CLASS__ . '.STATUS_PLANNED', 'Planirana');
    }

    public function getTitle(): string
    {
        return sprintf('%s %s %s · %s', date('d.m.Y.', strtotime((string) $this->Date)), $this->ShiftCode, $this->getTimeRange(), $this->getWorkerName());
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->Date || !$this->StartsAt || !$this->EndsAt || !$this->ShiftTypeID) {
            $result->addError(_t(__CLASS__ . '.INCOMPLETE', 'Datum i smjena su obavezni.'));
        } elseif ($this->EndsAt <= $this->StartsAt) {
            $result->addError(_t(__CLASS__ . '.TIMES', 'Kraj smjene mora biti nakon početka.'));
        }
        if ($this->Status === self::STATUS_PLANNED && !$this->MemberID) {
            $result->addError(_t(__CLASS__ . '.MEMBER_REQUIRED', 'Planirana smjena mora imati radnika.'));
        }
        if ($this->Status === self::STATUS_OPEN && $this->MemberID) {
            $result->addError(_t(__CLASS__ . '.OPEN_NO_MEMBER', 'Otvorena smjena nema radnika.'));
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->Date) {
            $period = RosterPeriod::forDate($this->dateObject());
            $this->PeriodID = (int) $period?->ID;
        }
    }

    public function canView($member = null): bool
    {
        if (RosterPermissions::check(RosterPermissions::PLAN, $member) || RosterPermissions::check(RosterPermissions::REPORTS, $member)) {
            return true;
        }

        return $member && (int) $member->ID === (int) $this->MemberID;
    }

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
