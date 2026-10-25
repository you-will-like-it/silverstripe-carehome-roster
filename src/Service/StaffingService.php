<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Roster\Model\ShiftAssignment;
use YouWillLikeIT\CareHome\Roster\Model\StaffingRequirement;

/**
 * Minimum staffing check per day: planned workers per shift type matching qualification and
 * zone of each requirement. Warnings only.
 */
class StaffingService
{
    use Injectable;

    public function __construct(private readonly WorkerDirectory $directory)
    {
    }

    /**
     * @return array<string,list<array{requirement:StaffingRequirement,have:int,need:int,label:string}>>
     *         Y-m-d => shortfalls
     */
    public function shortfalls(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $requirements = StaffingRequirement::get()->filter('ShiftType.IsActive', true)->toArray();
        if (!$requirements) {
            return [];
        }

        /** @var array<string,array<int,list<Member>>> $planned Y-m-d => typeID => members */
        $planned = [];
        $members = [];
        $assignments = ShiftAssignment::get()->filter([
            'Status' => ShiftAssignment::STATUS_PLANNED,
            'Date:GreaterThanOrEqual' => $from->format('Y-m-d'),
            'Date:LessThanOrEqual' => $to->format('Y-m-d'),
        ]);
        foreach ($assignments as $assignment) {
            $memberId = (int) $assignment->MemberID;
            $members[$memberId] ??= $assignment->Member();
            $planned[(string) $assignment->Date][(int) $assignment->ShiftTypeID][] = $members[$memberId];
        }

        $result = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $ymd = $day->format('Y-m-d');
            foreach ($requirements as $requirement) {
                if (!$requirement->appliesOn($day)) {
                    continue;
                }
                $have = 0;
                foreach ($planned[$ymd][(int) $requirement->ShiftTypeID] ?? [] as $member) {
                    if ($requirement->QualificationID && (int) $member->RosterQualificationID !== (int) $requirement->QualificationID) {
                        continue;
                    }
                    if (!$this->directory->coversZone($member, (int) $requirement->ZoneID)) {
                        continue;
                    }
                    $have++;
                }
                if ($have < (int) $requirement->MinCount) {
                    $result[$ymd][] = [
                        'requirement' => $requirement,
                        'have' => $have,
                        'need' => (int) $requirement->MinCount,
                        'label' => _t(__CLASS__ . '.SHORTFALL', '{shift}: {have}/{need} {qualification}{zone}', [
                            'shift' => $requirement->ShiftType()->Code,
                            'have' => $have,
                            'need' => (int) $requirement->MinCount,
                            'qualification' => $requirement->QualificationID ? $requirement->Qualification()->Code : '',
                            'zone' => $requirement->ZoneID ? ' · ' . $requirement->Zone()->Title : '',
                        ]),
                    ];
                }
            }
        }

        return $result;
    }
}
