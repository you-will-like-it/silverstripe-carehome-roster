<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Roster\Service\AbsenceService;
use YouWillLikeIT\CareHome\Roster\Service\WorkerDirectory;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Absence or absence request of one worker over a date range (inclusive).
 *
 * Created by the worker in /raspored (Pending, or Approved directly when the type needs no
 * approval) or by a planner in the CMS (Approved by default). Becoming Approved opens all
 * planned shifts of the worker in the range (AbsenceService), so the planner can assign a
 * substitute. Rejecting/cancelling later does not re-assign those shifts automatically.
 *
 * @property string $DateFrom
 * @property string $DateTo
 * @property string $Status
 * @property string $Source
 * @property string|null $WorkerNote
 * @property string|null $DecisionNote
 * @property string|null $DecidedAt
 * @property int $LeaveDays snapshot of deducted leave days when approved
 * @property int $MemberID
 * @property int $TypeID
 * @property int $DecidedByID
 * @property int $CreatedByID
 * @method Member Member()
 * @method AbsenceType Type()
 * @method Member DecidedBy()
 * @method Member CreatedBy()
 */
class Absence extends DataObject
{
    public const string STATUS_PENDING = 'Pending';
    public const string STATUS_APPROVED = 'Approved';
    public const string STATUS_REJECTED = 'Rejected';
    public const string STATUS_CANCELLED = 'Cancelled';

    public const string SOURCE_PORTAL = 'Portal';
    public const string SOURCE_CMS = 'Cms';

    private static string $table_name = 'CareHomeRoster_Absence';

    private static array $db = [
        'DateFrom' => 'Date',
        'DateTo' => 'Date',
        'Status' => "Enum('Pending,Approved,Rejected,Cancelled','Pending')",
        'Source' => "Enum('Portal,Cms','Cms')",
        'WorkerNote' => 'Text',
        'DecisionNote' => 'Text',
        'DecidedAt' => 'Datetime',
        'LeaveDays' => 'Int',
    ];

    private static array $has_one = [
        'Member' => Member::class,
        'Type' => AbsenceType::class,
        'DecidedBy' => Member::class,
        'CreatedBy' => Member::class,
    ];

    private static array $indexes = [
        'MemberRange' => ['type' => 'index', 'columns' => ['MemberID', 'DateFrom', 'DateTo']],
        'StatusFrom' => ['type' => 'index', 'columns' => ['Status', 'DateFrom']],
    ];

    private static array $defaults = [
        'Status' => self::STATUS_APPROVED,
        'Source' => self::SOURCE_CMS,
    ];

    private static string $default_sort = '"DateFrom" DESC, "ID" DESC';

    private static array $summary_fields = [
        'Member.Name',
        'Type.Code',
        'RangeLabel',
        'StatusLabel',
        'SourceLabel',
        'LeaveDays',
    ];

    private static array $searchable_fields = [
        'Member.Surname',
        'Status',
        'TypeID',
    ];

    private static array $casting = [
        'RangeLabel' => 'Varchar',
        'StatusLabel' => 'Varchar',
        'SourceLabel' => 'Varchar',
    ];

    /** Previous status captured in onBeforeWrite for onAfterWrite side effects. */
    private ?string $statusBeforeWrite = null;

    private bool $statusChanged = false;

    private bool $effectChanged = false;

    /** @return array<string,string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => _t(__CLASS__ . '.PENDING', 'Na čekanju'),
            self::STATUS_APPROVED => _t(__CLASS__ . '.APPROVED', 'Odobreno'),
            self::STATUS_REJECTED => _t(__CLASS__ . '.REJECTED', 'Odbijeno'),
            self::STATUS_CANCELLED => _t(__CLASS__ . '.CANCELLED', 'Otkazano'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Member' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'Member.Name' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'Member.Surname' => _t(__CLASS__ . '.SURNAME', 'Prezime'),
            'Type' => _t(__CLASS__ . '.TYPE', 'Vrsta'),
            'TypeID' => _t(__CLASS__ . '.TYPE', 'Vrsta'),
            'Type.Code' => _t(__CLASS__ . '.TYPE', 'Vrsta'),
            'DateFrom' => _t(__CLASS__ . '.FROM', 'Od'),
            'DateTo' => _t(__CLASS__ . '.TO', 'Do (uključivo)'),
            'RangeLabel' => _t(__CLASS__ . '.RANGE', 'Period'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'Source' => _t(__CLASS__ . '.SOURCE', 'Unio'),
            'SourceLabel' => _t(__CLASS__ . '.SOURCE', 'Unio'),
            'WorkerNote' => _t(__CLASS__ . '.WORKER_NOTE', 'Napomena radnika'),
            'DecisionNote' => _t(__CLASS__ . '.DECISION_NOTE', 'Napomena uz odluku'),
            'DecidedBy' => _t(__CLASS__ . '.DECIDED_BY', 'Odlučio'),
            'DecidedAt' => _t(__CLASS__ . '.DECIDED_AT', 'Odlučeno'),
            'LeaveDays' => _t(__CLASS__ . '.LEAVE_DAYS', 'Dana GO'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Odsustvo');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Odsustva');
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create();
        $fields->push(TabSet::create('Root', Tab::create('Main')));

        $workers = WorkerDirectory::singleton()->options();
        if ($this->MemberID && !isset($workers[(int) $this->MemberID])) {
            $workers[(int) $this->MemberID] = (string) $this->Member()->getName();
        }
        $types = AbsenceType::options();
        if ($this->TypeID && !isset($types[(int) $this->TypeID])) {
            $types[(int) $this->TypeID] = (string) $this->Type()->Title;
        }

        $fields->addFieldsToTab('Root.Main', [
            DropdownField::create('MemberID', $this->fieldLabel('Member'), $workers)
                ->setEmptyString(_t(__CLASS__ . '.CHOOSE', '— odaberite —')),
            DropdownField::create('TypeID', $this->fieldLabel('Type'), $types)
                ->setEmptyString(_t(__CLASS__ . '.CHOOSE', '— odaberite —')),
            DateField::create('DateFrom', $this->fieldLabel('DateFrom')),
            DateField::create('DateTo', $this->fieldLabel('DateTo')),
            DropdownField::create('Status', $this->fieldLabel('Status'), self::statusOptions())
                ->setDescription(_t(
                    __CLASS__ . '.STATUS_DESC',
                    'Odobravanjem se planirane smjene radnika u periodu otvaraju za zamjenu.'
                )),
            TextareaField::create('WorkerNote', $this->fieldLabel('WorkerNote'))->setRows(2),
            TextareaField::create('DecisionNote', $this->fieldLabel('DecisionNote'))->setRows(2),
        ]);

        if ($this->isInDB()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('SourceRO', $this->fieldLabel('Source'), $this->getSourceLabel()),
                ReadonlyField::create('DecidedRO', $this->fieldLabel('DecidedBy'), trim(
                    ($this->DecidedByID ? $this->DecidedBy()->getName() : '') . ' '
                    . ($this->DecidedAt ? $this->dbObject('DecidedAt')->Nice() : '')
                )),
            ]);
        }

        if ($info = AbsenceService::singleton()->describeImpact($this)) {
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'Impact',
                '<div class="message notice">' . $info . '</div>'
            ), 'MemberID');
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        AbsenceService::singleton()->validate($this, $result);

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        $isNew = !$this->isInDB();
        $this->statusChanged = $isNew || $this->isChanged('Status', DataObject::CHANGE_VALUE);
        $changed = $this->getChangedFields(['Status'], DataObject::CHANGE_VALUE);
        $this->statusBeforeWrite = $isNew ? null : ($changed['Status']['before'] ?? $this->Status);
        $this->effectChanged = $this->statusChanged
            || $this->isChanged('DateFrom', DataObject::CHANGE_VALUE)
            || $this->isChanged('DateTo', DataObject::CHANGE_VALUE)
            || $this->isChanged('TypeID', DataObject::CHANGE_VALUE);

        if ($isNew && !$this->CreatedByID && ($member = Security::getCurrentUser())) {
            $this->CreatedByID = (int) $member->ID;
        }

        if ($this->statusChanged && in_array($this->Status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            $this->DecidedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
            $actor = AuditContext::singleton()->getActor();
            $this->DecidedByID = $actor ? (int) $actor->ID : 0;
        }

        $this->LeaveDays = AbsenceService::singleton()->leaveDays($this);
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        if (!$this->effectChanged) {
            return;
        }
        $before = $this->statusBeforeWrite;
        $statusChanged = $this->statusChanged;
        $this->effectChanged = false;
        $this->statusChanged = false;
        AbsenceService::singleton()->afterWrite($this, $before, $statusChanged);
    }

    /* ------------------------------------------------------------ helpers */

    public function from(): ?DateTimeImmutable
    {
        return Clock::singleton()->parseDate($this->DateFrom ? (string) $this->DateFrom : null);
    }

    public function to(): ?DateTimeImmutable
    {
        return Clock::singleton()->parseDate($this->DateTo ? (string) $this->DateTo : null);
    }

    public function covers(DateTimeImmutable $date): bool
    {
        $ymd = $date->format('Y-m-d');

        return (string) $this->DateFrom <= $ymd && $ymd <= (string) $this->DateTo;
    }

    public function isApproved(): bool
    {
        return $this->Status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->Status === self::STATUS_PENDING;
    }

    /** Blocking = approved and not a mere wish. */
    public function isBlocking(): bool
    {
        return $this->isApproved() && !$this->Type()->IsPreference;
    }

    public function getRangeLabel(): string
    {
        if (!$this->DateFrom) {
            return '';
        }
        $from = date('d.m.Y.', strtotime((string) $this->DateFrom));
        $to = date('d.m.Y.', strtotime((string) $this->DateTo));

        return $from === $to ? $from : $from . ' – ' . $to;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->Status] ?? (string) $this->Status;
    }

    public function getSourceLabel(): string
    {
        return $this->Source === self::SOURCE_PORTAL
            ? _t(__CLASS__ . '.SOURCE_PORTAL', 'Radnik (portal)')
            : _t(__CLASS__ . '.SOURCE_CMS', 'Administrator (CMS)');
    }

    public function getTitle(): string
    {
        return trim($this->Member()->getName() . ' · ' . $this->Type()->Title . ' · ' . $this->getRangeLabel());
    }

    public function canView($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        if (RosterPermissions::check(RosterPermissions::PLAN, $member)) {
            return true;
        }

        return $member && (int) $member->ID === (int) $this->MemberID;
    }

    public function canEdit($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member)
            && !AbsenceService::singleton()->touchesLockedPeriod($this);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member);
    }

    /** Never deleted (traceability); cancel instead. Pending/rejected drafts by planners only. */
    public function canDelete($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member)
            && in_array($this->Status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)
            && !AbsenceService::singleton()->touchesLockedPeriod($this);
    }
}
