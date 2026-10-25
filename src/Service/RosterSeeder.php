<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use YouWillLikeIT\CareHome\Roster\Model\AbsenceType;
use YouWillLikeIT\CareHome\Roster\Model\ContractType;
use YouWillLikeIT\CareHome\Roster\Model\PublicHoliday;
use YouWillLikeIT\CareHome\Roster\Model\Qualification;
use YouWillLikeIT\CareHome\Roster\Model\ShiftPattern;
use YouWillLikeIT\CareHome\Roster\Model\ShiftType;
use YouWillLikeIT\CareHome\Service\ShiftResolver;

/**
 * Seeds catalogs on db:build. Each catalog is seeded only while its table is empty,
 * so edits and deletions by the facility are never overwritten.
 *
 * Seed values are for a BiH (FBiH) installation and editable in the CMS.
 */
class RosterSeeder
{
    use Injectable;
    use Configurable;

    /** Set false in app/_config to skip seeding entirely. */
    private static bool $enabled = true;

    /** FBiH state holidays (recurring). Stored with a leap year so 29.02. would also be valid. */
    private static array $holidays = [
        '01-01' => 'Nova godina',
        '01-02' => 'Nova godina (drugi dan)',
        '03-01' => 'Dan nezavisnosti BiH',
        '05-01' => 'Praznik rada',
        '05-02' => 'Praznik rada (drugi dan)',
        '11-25' => 'Dan državnosti BiH',
    ];

    public function seed(): void
    {
        if (!static::config()->get('enabled')) {
            return;
        }
        $this->seedQualifications();
        $this->seedContractTypes();
        $this->seedShiftTypes();
        $this->seedPatterns();
        $this->seedAbsenceTypes();
        $this->seedHolidays();
    }

    private function seedQualifications(): void
    {
        if (Qualification::get()->exists()) {
            return;
        }
        $rows = [
            ['MS', _t(__CLASS__ . '.Q_NURSE', 'Medicinska sestra / tehničar')],
            ['NJ', _t(__CLASS__ . '.Q_CARER', 'Njegovatelj/ica')],
            ['FT', _t(__CLASS__ . '.Q_PHYSIO', 'Fizioterapeut')],
            ['KU', _t(__CLASS__ . '.Q_COOK', 'Kuhar/ica')],
            ['PR', _t(__CLASS__ . '.Q_HELPER', 'Pomoćni radnik')],
        ];
        foreach ($rows as $i => [$code, $title]) {
            Qualification::create(['Code' => $code, 'Title' => $title, 'Sort' => ($i + 1) * 10])->write();
        }
        DB::alteration_message('CareHome roster: qualifications seeded', 'created');
    }

    private function seedContractTypes(): void
    {
        if (ContractType::get()->exists()) {
            return;
        }
        ContractType::create([
            'Title' => _t(__CLASS__ . '.C_FULL', 'Puno radno vrijeme'),
            'WeeklyHours' => 40,
            'AnnualLeaveDays' => 20,
            'Sort' => 10,
        ])->write();
        ContractType::create([
            'Title' => _t(__CLASS__ . '.C_HALF', 'Nepuno radno vrijeme (50 %)'),
            'WeeklyHours' => 20,
            'AnnualLeaveDays' => 20,
            'Sort' => 20,
        ])->write();
        DB::alteration_message('CareHome roster: contract types seeded', 'created');
    }

    /**
     * Care shifts mirror the base module's ShiftResolver configuration (linked via ResolverCode),
     * plus 12-hour day/night duties for 12-24-12-48 and two kitchen shifts.
     * Breaks are seeded as 0 (FBiH: the daily break counts as working time).
     */
    private function seedShiftTypes(): void
    {
        if (ShiftType::get()->exists()) {
            return;
        }

        $sort = 10;
        $used = [];
        $resolverShifts = (array) ShiftResolver::config()->get('shifts');
        foreach ($resolverShifts as $resolverCode => $shift) {
            if (!is_array($shift) || empty($shift['start']) || empty($shift['end'])) {
                continue;
            }
            $label = (string) ($shift['label'] ?? $resolverCode);
            $code = $this->uniqueCode(mb_strtoupper(mb_substr($label, 0, 1)) ?: 'S', $used);
            ShiftType::create([
                'Title' => $label,
                'Code' => $code,
                'StartTime' => $shift['start'],
                'EndTime' => $shift['end'],
                'BreakMinutes' => 0,
                'Colour' => $this->colourFor($shift['start']),
                'AppliesToCare' => true,
                'ResolverCode' => (string) $resolverCode,
                'Sort' => $sort,
            ])->write();
            $sort += 10;
        }

        $extra = [
            ['D12', _t(__CLASS__ . '.S_DAY12', 'Dnevno dežurstvo 12 h'), '07:00', '19:00', '#f59e0b', true, false],
            ['N12', _t(__CLASS__ . '.S_NIGHT12', 'Noćno dežurstvo 12 h'), '19:00', '07:00', '#1e3a8a', true, false],
            ['K1', _t(__CLASS__ . '.S_KITCHEN1', 'Kuhinja – prva'), '06:00', '14:00', '#16a34a', false, true],
            ['K2', _t(__CLASS__ . '.S_KITCHEN2', 'Kuhinja – druga'), '12:00', '20:00', '#65a30d', false, true],
        ];
        foreach ($extra as [$code, $title, $start, $end, $colour, $care, $kitchen]) {
            if (isset($used[$code])) {
                continue;
            }
            $used[$code] = true;
            ShiftType::create([
                'Title' => $title,
                'Code' => $code,
                'StartTime' => $start,
                'EndTime' => $end,
                'BreakMinutes' => 0,
                'Colour' => $colour,
                'AppliesToCare' => $care,
                'AppliesToKitchen' => $kitchen,
                'ResolverCode' => $care ? $this->matchingResolverCode($start, $end, $resolverShifts) : null,
                'Sort' => $sort,
            ])->write();
            $sort += 10;
        }
        DB::alteration_message('CareHome roster: shift types seeded', 'created');
    }

    private function seedPatterns(): void
    {
        if (ShiftPattern::get()->exists()) {
            return;
        }
        $codes = array_flip(ShiftType::get()->column('Code'));

        if (isset($codes['D12'], $codes['N12'])) {
            ShiftPattern::create([
                'Title' => '12-24-12-48',
                'Pattern' => 'D12, N12, -, -',
                'Description' => _t(__CLASS__ . '.P_1224', 'Dan 12 h, odmor 24 h, noć 12 h, odmor 48 h (42 h sedmično)'),
                'Sort' => 10,
            ])->write();
        }

        // three-shift rotation from the first three resolver-linked types (J, P, N by default)
        $linked = ShiftType::get()->exclude('ResolverCode', [null, ''])->sort('Sort')->limit(3)->column('Code');
        if (count($linked) === 3) {
            [$a, $b, $c] = $linked;
            ShiftPattern::create([
                'Title' => _t(__CLASS__ . '.P_ROTATION', 'Rotacija 2-2-2 + 2 slobodna'),
                'Pattern' => "$a, $a, $b, $b, $c, $c, -, -",
                'Description' => _t(__CLASS__ . '.P_ROTATION_DESC', 'Po dva dana svake smjene, zatim dva slobodna dana'),
                'Sort' => 20,
            ])->write();
        }
    }

    private function seedAbsenceTypes(): void
    {
        if (AbsenceType::get()->exists()) {
            return;
        }
        // code, title, colour, preference, approval, self, deducts, credits, sick
        $rows = [
            ['GO', _t(__CLASS__ . '.A_LEAVE', 'Godišnji odmor'), '#0ea5e9', false, true, true, true, true, false],
            ['BO', _t(__CLASS__ . '.A_SICK', 'Bolovanje'), '#e11d48', false, false, true, false, true, true],
            ['PO', _t(__CLASS__ . '.A_PAID', 'Plaćeno odsustvo'), '#8b5cf6', false, true, true, false, true, false],
            ['NO', _t(__CLASS__ . '.A_UNPAID', 'Neplaćeno odsustvo'), '#64748b', false, true, true, false, false, false],
            ['VP', _t(__CLASS__ . '.A_RELIGIOUS', 'Vjerski praznik'), '#a855f7', false, true, true, false, true, false],
            ['ED', _t(__CLASS__ . '.A_TRAINING', 'Edukacija'), '#14b8a6', false, true, false, false, true, false],
            ['SŽ', _t(__CLASS__ . '.A_WISH', 'Slobodan dan po želji'), '#cbd5e1', true, false, true, false, false, false],
        ];
        foreach ($rows as $i => [$code, $title, $colour, $preference, $approval, $self, $deducts, $credits, $sick]) {
            AbsenceType::create([
                'Code' => $code,
                'Title' => $title,
                'Colour' => $colour,
                'IsPreference' => $preference,
                'RequiresApproval' => $approval,
                'AllowSelfRequest' => $self,
                'DeductsAnnualLeave' => $deducts,
                'CreditsHours' => $credits,
                'IsSickLeave' => $sick,
                'Sort' => ($i + 1) * 10,
            ])->write();
        }
        DB::alteration_message('CareHome roster: absence types seeded', 'created');
    }

    private function seedHolidays(): void
    {
        if (PublicHoliday::get()->exists()) {
            return;
        }
        foreach ((array) static::config()->get('holidays') as $md => $title) {
            PublicHoliday::create([
                'Title' => $title,
                'Date' => '2000-' . $md,
                'IsRecurring' => true,
            ])->write();
        }
        DB::alteration_message('CareHome roster: FBiH public holidays seeded', 'created');
    }

    /** @param array<string,bool> $used */
    private function uniqueCode(string $base, array &$used): string
    {
        $code = $base;
        $i = 2;
        while (isset($used[$code])) {
            $code = $base . $i++;
        }
        $used[$code] = true;

        return $code;
    }

    private function colourFor(string $start): string
    {
        $hour = (int) substr($start, 0, 2);

        return match (true) {
            $hour >= 4 && $hour < 11 => '#0891b2',
            $hour >= 11 && $hour < 18 => '#d97706',
            default => '#4338ca',
        };
    }

    /** @param array<string,mixed> $resolverShifts */
    private function matchingResolverCode(string $start, string $end, array $resolverShifts): ?string
    {
        foreach ($resolverShifts as $code => $shift) {
            if (is_array($shift) && ($shift['start'] ?? null) === $start && ($shift['end'] ?? null) === $end) {
                return (string) $code;
            }
        }

        return null;
    }
}
