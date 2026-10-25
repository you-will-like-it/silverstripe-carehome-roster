<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Extension;

use DateTimeImmutable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareHome\Extension\MemberCareExtension;
use YouWillLikeIT\CareHome\Roster\Model\ContractType;
use YouWillLikeIT\CareHome\Roster\Model\Qualification;
use YouWillLikeIT\CareHome\Roster\Model\RosterSettings;
use YouWillLikeIT\CareHome\Roster\Service\LeaveBalanceService;
use YouWillLikeIT\CareHome\Service\Clock;

/**
 * Employment data on the worker: qualification, contract, employment period.
 * The role (njegovatelj / kuhinja / administrator) and zones come from the base module.
 *
 * @property string|null $EmploymentStart
 * @property string|null $EmploymentEnd
 * @property bool $IsFixedTerm
 * @property float $WeeklyHoursOverride 0 = contract default
 * @property int $AnnualLeaveOverride 0 = contract default
 * @property int $RosterSort
 * @property bool $RosterExcluded
 * @property int $RosterQualificationID
 * @property int $RosterContractTypeID
 * @method Qualification RosterQualification()
 * @method ContractType RosterContractType()
 * @extends Extension<Member>
 */
class MemberRosterExtension extends Extension
{
    private static array $db = [
        'EmploymentStart' => 'Date',
        'EmploymentEnd' => 'Date',
        'IsFixedTerm' => 'Boolean(0)',
        'WeeklyHoursOverride' => 'Decimal(5,2)',
        'AnnualLeaveOverride' => 'Int',
        'RosterSort' => 'Int',
        'RosterExcluded' => 'Boolean(0)',
    ];

    private static array $has_one = [
        'RosterQualification' => Qualification::class,
        'RosterContractType' => ContractType::class,
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName([
            'EmploymentStart', 'EmploymentEnd', 'IsFixedTerm', 'WeeklyHoursOverride', 'AnnualLeaveOverride',
            'RosterSort', 'RosterExcluded', 'RosterQualificationID', 'RosterContractTypeID',
        ]);

        $owner = $this->getOwner();
        $tab = 'Root.' . _t(__CLASS__ . '.TAB', 'Zaposlenje');

        $qualifications = Qualification::options();
        if ($owner->RosterQualificationID && !isset($qualifications[(int) $owner->RosterQualificationID])) {
            $qualifications[(int) $owner->RosterQualificationID] = (string) $owner->RosterQualification()->Title;
        }
        $contracts = ContractType::options();
        if ($owner->RosterContractTypeID && !isset($contracts[(int) $owner->RosterContractTypeID])) {
            $contracts[(int) $owner->RosterContractTypeID] = (string) $owner->RosterContractType()->Title;
        }

        $fields->addFieldsToTab($tab, [
            DropdownField::create('RosterQualificationID', _t(__CLASS__ . '.QUALIFICATION', 'Kvalifikacija'), $qualifications)
                ->setEmptyString(_t(__CLASS__ . '.NONE', '— nije postavljeno —')),
            DropdownField::create('RosterContractTypeID', _t(__CLASS__ . '.CONTRACT', 'Vrsta ugovora'), $contracts)
                ->setEmptyString(_t(__CLASS__ . '.NONE', '— nije postavljeno —')),
            DateField::create('EmploymentStart', _t(__CLASS__ . '.START', 'Datum zaposlenja')),
            DateField::create('EmploymentEnd', _t(__CLASS__ . '.END', 'Kraj zaposlenja / ugovora'))
                ->setDescription(_t(__CLASS__ . '.END_DESC', 'Nakon ovog datuma radnik se ne može planirati.')),
            CheckboxField::create('IsFixedTerm', _t(__CLASS__ . '.FIXED_TERM', 'Ugovor na određeno vrijeme'))
                ->setDescription(_t(__CLASS__ . '.FIXED_TERM_DESC', 'Administratori dobijaju upozorenje prije isteka.')),
            NumericField::create('WeeklyHoursOverride', _t(__CLASS__ . '.HOURS', 'Sedmično sati (odstupanje)'))
                ->setScale(2)
                ->setDescription(_t(__CLASS__ . '.HOURS_DESC', '0 = prema vrsti ugovora.')),
            NumericField::create('AnnualLeaveOverride', _t(__CLASS__ . '.LEAVE', 'Godišnji odmor dana (odstupanje)'))
                ->setDescription(_t(__CLASS__ . '.LEAVE_DESC', '0 = prema vrsti ugovora. Važi za nove godine; postojeće pravo se mijenja u „Godišnji odmor – saldo".')),
            NumericField::create('RosterSort', _t(__CLASS__ . '.SORT', 'Redoslijed u planeru')),
            CheckboxField::create('RosterExcluded', _t(__CLASS__ . '.EXCLUDED', 'Ne planira se u smjene')),
        ]);

        if ($owner->isInDB() && $this->isRosterCandidate()) {
            $year = (int) Clock::singleton()->today()->format('Y');
            $balance = LeaveBalanceService::singleton()->balance($owner, $year);
            $fields->addFieldToTab($tab, LiteralField::create('LeaveBalance', sprintf(
                '<p class="message notice">%s</p>',
                htmlspecialchars(_t(__CLASS__ . '.BALANCE', 'Godišnji odmor {year}: preostalo {remaining} dana (iskorišteno {used}, na čekanju {pending}).', [
                    'year' => $year,
                    'remaining' => $balance->remaining(),
                    'used' => $balance->used(),
                    'pending' => $balance->pending,
                ]))
            )));
        }
    }

    protected function updateValidate(ValidationResult $result): void
    {
        $owner = $this->getOwner();
        if ($owner->EmploymentStart && $owner->EmploymentEnd && $owner->EmploymentEnd < $owner->EmploymentStart) {
            $result->addFieldError('EmploymentEnd', _t(__CLASS__ . '.END_BEFORE_START', 'Kraj zaposlenja je prije početka.'));
        }
        if ((float) $owner->WeeklyHoursOverride < 0 || (float) $owner->WeeklyHoursOverride > 80) {
            $result->addFieldError('WeeklyHoursOverride', _t(__CLASS__ . '.HOURS_RANGE', 'Dozvoljeno 0–80.'));
        }
        if ((int) $owner->AnnualLeaveOverride < 0 || (int) $owner->AnnualLeaveOverride > 60) {
            $result->addFieldError('AnnualLeaveOverride', _t(__CLASS__ . '.LEAVE_RANGE', 'Dozvoljeno 0–60.'));
        }
        if ($owner->IsFixedTerm && !$owner->EmploymentEnd) {
            $result->addFieldError('EmploymentEnd', _t(__CLASS__ . '.FIXED_TERM_END', 'Za ugovor na određeno unesite datum isteka.'));
        }
    }

    /* ------------------------------------------------------------ API */

    /** Has a worker role and is not excluded (ignores employment dates). */
    public function isRosterCandidate(): bool
    {
        $owner = $this->getOwner();

        return in_array((string) $owner->WorkerRole, [
            MemberCareExtension::ROLE_CAREWORKER,
            MemberCareExtension::ROLE_KITCHEN,
            MemberCareExtension::ROLE_ADMIN,
        ], true) && !$owner->RosterExcluded;
    }

    public function isEmployedOn(DateTimeImmutable $date): bool
    {
        $owner = $this->getOwner();
        $ymd = $date->format('Y-m-d');

        return (!$owner->EmploymentStart || (string) $owner->EmploymentStart <= $ymd)
            && (!$owner->EmploymentEnd || (string) $owner->EmploymentEnd >= $ymd);
    }

    public function isEmployedBetween(DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $owner = $this->getOwner();

        return (!$owner->EmploymentStart || (string) $owner->EmploymentStart <= $to->format('Y-m-d'))
            && (!$owner->EmploymentEnd || (string) $owner->EmploymentEnd >= $from->format('Y-m-d'));
    }

    public function contractWeeklyHours(): float
    {
        $owner = $this->getOwner();
        if ((float) $owner->WeeklyHoursOverride > 0) {
            return (float) $owner->WeeklyHoursOverride;
        }

        return $owner->RosterContractTypeID ? (float) $owner->RosterContractType()->WeeklyHours : 0.0;
    }

    public function contractLeaveDays(): int
    {
        $owner = $this->getOwner();
        if ((int) $owner->AnnualLeaveOverride > 0) {
            return (int) $owner->AnnualLeaveOverride;
        }

        return $owner->RosterContractTypeID ? (int) $owner->RosterContractType()->AnnualLeaveDays : 0;
    }

    /** Fixed-term contract ending within the configured warning window (or already ended). */
    public function contractEndsSoon(?DateTimeImmutable $today = null): bool
    {
        $owner = $this->getOwner();
        if (!$owner->IsFixedTerm || !$owner->EmploymentEnd) {
            return false;
        }
        $today ??= Clock::singleton()->today();
        $limit = $today->modify('+' . max(0, (int) RosterSettings::current()->ContractWarningDays) . ' days');

        return (string) $owner->EmploymentEnd <= $limit->format('Y-m-d')
            && (string) $owner->EmploymentEnd >= $today->format('Y-m-d');
    }
}
