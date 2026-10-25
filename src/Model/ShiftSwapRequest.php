<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
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
use YouWillLikeIT\CareHome\Roster\Service\SwapService;
use YouWillLikeIT\CareHome\Service\AuditContext;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Worker-initiated takeover or exchange of a shift:
 * requester proposes a colleague → colleague accepts (or declines) → planner approves.
 *
 * With CounterAssignment set it is an exchange: the requester takes the colleague's shift in
 * return. Approval re-runs all planning checks at that moment (SwapService).
 *
 * @property string $Status
 * @property string|null $RequestNote
 * @property string|null $ColleagueNote
 * @property string|null $DecisionNote
 * @property string|null $ColleagueRespondedAt
 * @property string|null $DecidedAt
 * @property int $AssignmentID
 * @property int $CounterAssignmentID
 * @property int $RequestedByID
 * @property int $ColleagueID
 * @property int $DecidedByID
 * @method ShiftAssignment Assignment()
 * @method ShiftAssignment CounterAssignment()
 * @method Member RequestedBy()
 * @method Member Colleague()
 * @method Member DecidedBy()
 */
class ShiftSwapRequest extends DataObject
{
    public const string STATUS_AWAITING_COLLEAGUE = 'AwaitingColleague';
    public const string STATUS_AWAITING_APPROVAL = 'AwaitingApproval';
    public const string STATUS_APPROVED = 'Approved';
    public const string STATUS_REJECTED = 'Rejected';
    public const string STATUS_DECLINED = 'Declined';
    public const string STATUS_CANCELLED = 'Cancelled';

    private static string $table_name = 'CareHomeRoster_ShiftSwapRequest';

    private static array $db = [
        'Status' => "Enum('AwaitingColleague,AwaitingApproval,Approved,Rejected,Declined,Cancelled','AwaitingColleague')",
        'RequestNote' => 'Text',
        'ColleagueNote' => 'Text',
        'DecisionNote' => 'Text',
        'ColleagueRespondedAt' => 'Datetime',
        'DecidedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Assignment' => ShiftAssignment::class,
        'CounterAssignment' => ShiftAssignment::class,
        'RequestedBy' => Member::class,
        'Colleague' => Member::class,
        'DecidedBy' => Member::class,
    ];

    private static array $indexes = [
        'Status' => true,
    ];

    private static string $default_sort = '"Created" DESC';

    private static array $summary_fields = [
        'Created.Nice',
        'RequestedBy.Name',
        'Colleague.Name',
        'Summary',
        'StatusLabel',
    ];

    private static array $casting = [
        'Summary' => 'Varchar',
        'StatusLabel' => 'Varchar',
    ];

    private bool $statusChanged = false;

    /** @return array<string,string> */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_AWAITING_COLLEAGUE => _t(__CLASS__ . '.AWAITING_COLLEAGUE', 'Čeka kolegu'),
            self::STATUS_AWAITING_APPROVAL => _t(__CLASS__ . '.AWAITING_APPROVAL', 'Čeka odobrenje'),
            self::STATUS_APPROVED => _t(__CLASS__ . '.APPROVED', 'Odobreno'),
            self::STATUS_REJECTED => _t(__CLASS__ . '.REJECTED', 'Odbijeno'),
            self::STATUS_DECLINED => _t(__CLASS__ . '.DECLINED', 'Kolega odbio'),
            self::STATUS_CANCELLED => _t(__CLASS__ . '.CANCELLED', 'Otkazano'),
        ];
    }

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Created' => _t(__CLASS__ . '.CREATED', 'Zatraženo'),
            'Created.Nice' => _t(__CLASS__ . '.CREATED', 'Zatraženo'),
            'RequestedBy' => _t(__CLASS__ . '.REQUESTED_BY', 'Traži'),
            'RequestedBy.Name' => _t(__CLASS__ . '.REQUESTED_BY', 'Traži'),
            'Colleague' => _t(__CLASS__ . '.COLLEAGUE', 'Preuzima'),
            'Colleague.Name' => _t(__CLASS__ . '.COLLEAGUE', 'Preuzima'),
            'Summary' => _t(__CLASS__ . '.SUMMARY', 'Smjena'),
            'Status' => _t(__CLASS__ . '.STATUS', 'Status'),
            'StatusLabel' => _t(__CLASS__ . '.STATUS', 'Status'),
            'RequestNote' => _t(__CLASS__ . '.REQUEST_NOTE', 'Napomena radnika'),
            'ColleagueNote' => _t(__CLASS__ . '.COLLEAGUE_NOTE', 'Napomena kolege'),
            'DecisionNote' => _t(__CLASS__ . '.DECISION_NOTE', 'Napomena uz odluku'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Zamjena smjene');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Zamjene smjena');
    }

    public function getSummary(): string
    {
        $text = $this->Assignment()->exists() ? $this->Assignment()->getTitle() : '';
        if ($this->CounterAssignmentID && $this->CounterAssignment()->exists()) {
            $text .= ' ⇄ ' . $this->CounterAssignment()->getTitle();
        }

        return $text;
    }

    public function getStatusLabel(): string
    {
        return self::statusOptions()[$this->Status] ?? (string) $this->Status;
    }

    public function getTitle(): string
    {
        return $this->getSummary();
    }

    public function isFinal(): bool
    {
        return in_array($this->Status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_DECLINED, self::STATUS_CANCELLED], true);
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create(TabSet::create('Root', Tab::create('Main')));

        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('SummaryRO', $this->fieldLabel('Summary'), $this->getSummary()),
            ReadonlyField::create('RequestedByRO', $this->fieldLabel('RequestedBy'), $this->RequestedBy()->getName()),
            ReadonlyField::create('ColleagueRO', $this->fieldLabel('Colleague'), $this->Colleague()->getName()),
            ReadonlyField::create('RequestNoteRO', $this->fieldLabel('RequestNote'), $this->RequestNote),
            ReadonlyField::create('ColleagueNoteRO', $this->fieldLabel('ColleagueNote'), $this->ColleagueNote),
        ]);

        if ($this->isFinal()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('StatusRO', $this->fieldLabel('Status'), $this->getStatusLabel()),
                ReadonlyField::create('DecisionNoteRO', $this->fieldLabel('DecisionNote'), $this->DecisionNote),
            ]);

            return $fields;
        }

        $preview = SwapService::singleton()->preview($this);
        if ($preview['messages']) {
            $fields->addFieldToTab('Root.Main', LiteralField::create('Checks', sprintf(
                '<div class="message %s"><strong>%s</strong><ul><li>%s</li></ul></div>',
                $preview['blocked'] ? 'bad' : 'warning',
                htmlspecialchars($preview['blocked']
                    ? _t(__CLASS__ . '.BLOCKED', 'Zamjena se ne može odobriti:')
                    : _t(__CLASS__ . '.WARNINGS', 'Upozorenja (odobrenje traži napomenu kao razlog):')),
                implode('</li><li>', array_map('htmlspecialchars', $preview['messages']))
            )), 'SummaryRO');
        }

        $options = [
            $this->Status => $this->getStatusLabel(),
            self::STATUS_APPROVED => self::statusOptions()[self::STATUS_APPROVED],
            self::STATUS_REJECTED => self::statusOptions()[self::STATUS_REJECTED],
        ];
        $fields->addFieldsToTab('Root.Main', [
            DropdownField::create('Status', $this->fieldLabel('Status'), $options)->setDescription(
                $this->Status === self::STATUS_AWAITING_COLLEAGUE
                    ? _t(__CLASS__ . '.STATUS_EARLY', 'Kolega još nije potvrdio; odobrenjem planer preuzima tu odluku.')
                    : ''
            ),
            TextareaField::create('DecisionNote', $this->fieldLabel('DecisionNote'))->setRows(2),
        ]);

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        SwapService::singleton()->validate($this, $result);

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->statusChanged = $this->isInDB() && $this->isChanged('Status', DataObject::CHANGE_VALUE);
        if ($this->statusChanged && in_array($this->Status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            $this->DecidedAt = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
            $actor = AuditContext::singleton()->getActor();
            $this->DecidedByID = $actor ? (int) $actor->ID : 0;
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        if ($this->statusChanged) {
            $this->statusChanged = false;
            SwapService::singleton()->afterStatusChange($this);
        }
    }

    public function canView($member = null): bool
    {
        $member ??= Security::getCurrentUser();
        if (RosterPermissions::check(RosterPermissions::PLAN, $member)) {
            return true;
        }

        return $member && in_array((int) $member->ID, [(int) $this->RequestedByID, (int) $this->ColleagueID], true);
    }

    public function canEdit($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member) && !$this->isFinal();
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
