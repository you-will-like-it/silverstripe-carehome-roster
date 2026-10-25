<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareHome\Roster\Domain\PatternExpander;

/**
 * Rotation used to fill the plan, e.g. 12-24-12-48 = "D12, N12, -, -".
 *
 * @property string $Title
 * @property string $Pattern
 * @property string $Description
 * @property int $Sort
 */
class ShiftPattern extends DataObject
{
    use PlannerPermissionsTrait;

    private static string $table_name = 'CareHomeRoster_ShiftPattern';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Pattern' => 'Varchar(255)',
        'Description' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static string $default_sort = '"Sort" ASC, "Title" ASC';

    private static array $summary_fields = ['Title', 'Pattern', 'CycleLength'];

    private static array $casting = ['CycleLength' => 'Int'];

    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            'Title' => _t(__CLASS__ . '.TITLE', 'Naziv'),
            'Pattern' => _t(__CLASS__ . '.PATTERN', 'Obrazac'),
            'Description' => _t(__CLASS__ . '.DESCRIPTION', 'Opis'),
            'Sort' => _t(__CLASS__ . '.SORT', 'Redoslijed'),
            'CycleLength' => _t(__CLASS__ . '.LENGTH', 'Dužina ciklusa (dana)'),
        ]);
    }

    public function singular_name(): string
    {
        return _t(__CLASS__ . '.SINGULARNAME', 'Obrazac rotacije');
    }

    public function plural_name(): string
    {
        return _t(__CLASS__ . '.PLURALNAME', 'Obrasci rotacije');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $codes = implode(', ', ShiftType::get()->filter('IsActive', true)->column('Code'));
        $fields->dataFieldByName('Pattern')?->setDescription(_t(
            __CLASS__ . '.PATTERN_DESC',
            'Oznake smjena po danima, odvojene zarezom; „-" je slobodan dan. Npr. 12-24-12-48: D12, N12, -, -. Dostupne oznake: {codes}',
            ['codes' => $codes]
        ));

        return $fields;
    }

    /** @return list<string|null> */
    public function steps(): array
    {
        return PatternExpander::parse((string) $this->Pattern);
    }

    public function getCycleLength(): int
    {
        return count($this->steps());
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', _t(CatalogValidation::class . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }

        $steps = $this->steps();
        if ($steps === [] || count($steps) > 56) {
            $result->addFieldError('Pattern', _t(__CLASS__ . '.LENGTH_RANGE', 'Obrazac mora imati 1–56 dana.'));

            return $result;
        }
        if (array_filter($steps) === []) {
            $result->addFieldError('Pattern', _t(__CLASS__ . '.EMPTY', 'Obrazac mora sadržavati barem jednu smjenu.'));
        }

        $known = array_flip(ShiftType::get()->column('Code'));
        $unknown = array_unique(array_filter($steps, static fn (?string $c): bool => $c !== null && !isset($known[$c])));
        if ($unknown) {
            $result->addFieldError('Pattern', _t(
                __CLASS__ . '.UNKNOWN',
                'Nepoznate oznake smjena: {codes}',
                ['codes' => implode(', ', $unknown)]
            ));
        }

        return $result;
    }
}
