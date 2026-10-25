<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Throwable;
use YouWillLikeIT\CareHome\Roster\Model\RosterNotification;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * In-app notifications (/raspored) plus optional e-mail when the member has an address
 * and RosterSettings.EmailNotifications is on. Mail failures are logged, never thrown:
 * the in-app notification always exists.
 */
class RosterNotifier
{
    use Injectable;
    use Configurable;

    /** Sender; null = Email.admin_email */
    private static ?string $from_address = null;

    /** Messages collected during a request and e-mailed once per member (see flush()). */
    private array $outbox = [];

    public function notify(
        Member|int $member,
        string $subject,
        string $message,
        ?string $link = null,
        ?string $dedupKey = null,
    ): ?RosterNotification {
        $memberId = $member instanceof Member ? (int) $member->ID : $member;
        if ($memberId <= 0) {
            return null;
        }
        if ($dedupKey !== null && RosterNotification::get()->filter(['MemberID' => $memberId, 'DedupKey' => $dedupKey])->exists()) {
            return null;
        }

        $notification = RosterNotification::create([
            'MemberID' => $memberId,
            'Subject' => mb_substr($subject, 0, 255),
            'Message' => $message,
            'Link' => $link !== null ? mb_substr($link, 0, 255) : null,
            'DedupKey' => $dedupKey,
        ]);
        $notification->write();

        $this->outbox[$memberId][] = (int) $notification->ID;

        return $notification;
    }

    /**
     * @param list<int> $memberIds
     */
    public function notifyMany(array $memberIds, string $subject, string $message, ?string $link = null): void
    {
        foreach (array_unique(array_filter(array_map('intval', $memberIds))) as $id) {
            $this->notify($id, $subject, $message, $link);
        }
    }

    public function notifyPlanners(string $subject, string $message, ?string $link = null, ?string $dedupKey = null): void
    {
        foreach (Permission::get_members_by_permission(RosterPermissions::PLAN) as $planner) {
            $this->notify($planner, $subject, $message, $link, $dedupKey !== null ? $dedupKey . ':' . $planner->ID : null);
        }
    }

    /**
     * Sends the collected notifications: one e-mail per member listing all messages.
     * Called at the end of every service operation that notifies.
     */
    public function flush(): void
    {
        $outbox = $this->outbox;
        $this->outbox = [];
        if (!$outbox || !RosterSettings::current()->EmailNotifications) {
            return;
        }

        foreach ($outbox as $memberId => $ids) {
            /** @var Member|null $member */
            $member = Member::get()->byID($memberId);
            $address = $member ? trim((string) $member->Email) : '';
            if (!$member || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $items = RosterNotification::get()->filter('ID', $ids)->sort('ID');
            if (!$items->exists()) {
                continue;
            }

            try {
                $first = $items->first();
                $subject = $items->count() === 1
                    ? (string) $first->Subject
                    : _t(__CLASS__ . '.DIGEST', 'Raspored smjena: {count} obavještenja', ['count' => $items->count()]);

                $rows = [];
                foreach ($items as $item) {
                    $rows[] = ArrayData::create([
                        'Subject' => $item->Subject,
                        'Message' => $item->Message,
                        'Link' => $item->Link ? Director::absoluteURL((string) $item->Link) : null,
                    ]);
                }

                $email = Email::create();
                if ($from = static::config()->get('from_address')) {
                    $email->setFrom($from);
                }
                $email->setTo($address)
                    ->setSubject($subject)
                    ->setHTMLTemplate('YouWillLikeIT\\CareHome\\Roster\\Email\\Notification')
                    ->setData([
                        'Member' => $member,
                        'Items' => ArrayList::create($rows),
                    ]);
                $email->send();

                $now = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
                foreach ($items as $item) {
                    $item->EmailedAt = $now;
                    $item->write();
                }
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->warning(
                    'CareHome roster e-mail failed: ' . $e->getMessage(),
                    ['exception' => $e, 'memberID' => $memberId]
                );
            }
        }
    }

    public function unreadCount(Member $member): int
    {
        return RosterNotification::get()->filter(['MemberID' => (int) $member->ID, 'ReadAt' => null])->count();
    }

    public function markAllRead(Member $member): void
    {
        $now = Clock::singleton()->toDbDatetime(Clock::singleton()->now());
        foreach (RosterNotification::get()->filter(['MemberID' => (int) $member->ID, 'ReadAt' => null]) as $notification) {
            $notification->ReadAt = $now;
            $notification->write();
        }
    }
}
