<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Service\Clock;
use YouWillLikeIT\CareHome\Service\ZoneScope;

/**
 * Who can be planned, grouped by role, and which zones a worker covers.
 */
class WorkerDirectory
{
    use Injectable;

    public const string GROUP_CARE = 'care';
    public const string GROUP_KITCHEN = 'kitchen';
    public const string GROUP_ADMIN = 'admin';

    /** @var array<int,list<int>|null> */
    private array $zoneCache = [];

    /** @return array<string,string> group => role */
    public static function groupRoles(): array
    {
        return [
            self::GROUP_CARE => MemberCareExtension::ROLE_CAREWORKER,
            self::GROUP_KITCHEN => MemberCareExtension::ROLE_KITCHEN,
            self::GROUP_ADMIN => MemberCareExtension::ROLE_ADMIN,
        ];
    }

    /** @return array<string,string> group => label */
    public static function groupOptions(): array
    {
        $roles = MemberCareExtension::roleOptions();
        $options = [];
        foreach (self::groupRoles() as $group => $role) {
            $options[$group] = $roles[$role];
        }

        return $options;
    }

    public static function groupForRole(string $role): ?string
    {
        $flipped = array_flip(self::groupRoles());

        return $flipped[$role] ?? null;
    }

    /**
     * Plannable workers (role set, not excluded), optionally restricted to a role group and to
     * those employed at some point in [from, to].
     *
     * @return list<Member>
     */
    public function workers(?string $group = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $roles = $group !== null ? [self::groupRoles()[$group] ?? '__none__'] : array_values(self::groupRoles());

        /** @var DataList<Member> $list */
        $list = Member::get()
            ->filter(['WorkerRole' => $roles, 'RosterExcluded' => false])
            ->sort(['RosterSort' => 'ASC', 'Surname' => 'ASC', 'FirstName' => 'ASC']);

        $result = [];
        foreach ($list as $member) {
            if ($from && $to && !$member->isEmployedBetween($from, $to)) {
                continue;
            }
            $result[] = $member;
        }

        return $result;
    }

    /**
     * @return array<int,string> ID => "Surname FirstName (role)"
     */
    public function options(bool $includeFormer = false): array
    {
        $roles = MemberCareExtension::roleOptions();
        $today = Clock::singleton()->today();
        $options = [];
        foreach ($this->workers() as $member) {
            if (!$includeFormer && $member->EmploymentEnd && (string) $member->EmploymentEnd < $today->modify('-1 year')->format('Y-m-d')) {
                continue;
            }
            $options[(int) $member->ID] = trim($member->Surname . ' ' . $member->FirstName) . ' (' . ($roles[$member->WorkerRole] ?? '') . ')';
        }

        return $options;
    }

    /**
     * Zones covered by a worker (assigned zones + descendants); null = all (CareHome admin).
     *
     * @return list<int>|null
     */
    public function coveredZoneIds(Member $member): ?array
    {
        $id = (int) $member->ID;
        if (!array_key_exists($id, $this->zoneCache)) {
            $this->zoneCache[$id] = ZoneScope::singleton()->zoneIdsFor($member);
        }

        return $this->zoneCache[$id];
    }

    /**
     * Does the worker cover the zone? (zone 0 = whole facility → everyone)
     */
    public function coversZone(Member $member, int $zoneId): bool
    {
        if ($zoneId === 0) {
            return true;
        }
        $ids = $this->coveredZoneIds($member);

        return $ids === null || in_array($zoneId, $ids, true);
    }
}
