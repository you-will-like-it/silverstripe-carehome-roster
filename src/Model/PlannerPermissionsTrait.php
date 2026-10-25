<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use YouWillLikeIT\CareHome\Roster\Security\RosterPermissions;

/**
 * Catalog records: readable and editable by planners only.
 */
trait PlannerPermissionsTrait
{
    public function canView($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member);
    }

    public function canEdit($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member);
    }

    public function canDelete($member = null): bool
    {
        return RosterPermissions::check(RosterPermissions::PLAN, $member) && !$this->isInUse();
    }

    /** Referenced records are deactivated instead of deleted. */
    protected function isInUse(): bool
    {
        return false;
    }
}
