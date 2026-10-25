<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;

/**
 * In-app notification for one worker (shown in /raspored, optionally also e-mailed).
 *
 * @property string $Subject
 * @property string $Message
 * @property string|null $Link
 * @property string|null $ReadAt
 * @property string|null $EmailedAt
 * @property string|null $DedupKey prevents repeated reminders (e.g. contract expiry)
 * @property int $MemberID
 * @method Member Member()
 */
class RosterNotification extends DataObject
{
    private static string $table_name = 'CareHomeRoster_Notification';

    private static array $db = [
        'Subject' => 'Varchar(255)',
        'Message' => 'Text',
        'Link' => 'Varchar(255)',
        'ReadAt' => 'Datetime',
        'EmailedAt' => 'Datetime',
        'DedupKey' => 'Varchar(100)',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'MemberRead' => ['type' => 'index', 'columns' => ['MemberID', 'ReadAt']],
        'Dedup' => ['type' => 'index', 'columns' => ['MemberID', 'DedupKey']],
    ];

    private static string $default_sort = '"Created" DESC';

    private static array $summary_fields = ['Created.Nice', 'Member.Name', 'Subject', 'ReadAt.Nice'];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Created.Nice' => _t(__CLASS__ . '.CREATED', 'Vrijeme'),
            'Member.Name' => _t(__CLASS__ . '.MEMBER', 'Radnik'),
            'Subject' => _t(__CLASS__ . '.SUBJECT', 'Naslov'),
            'Message' => _t(__CLASS__ . '.MESSAGE', 'Poruka'),
            'ReadAt.Nice' => _t(__CLASS__ . '.READ', 'Pročitano'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Obavještenje');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Obavještenja');
    }

    public function getTitle(): string
    {
        return (string) $this->Subject;
    }

    public function canView($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member)
            || ($member && (int) $member->ID === (int) $this->MemberID);
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
