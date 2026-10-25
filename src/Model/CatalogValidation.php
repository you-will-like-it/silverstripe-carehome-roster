<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;

/**
 * Shared validation for catalog records with Title + unique short Code.
 */
final class CatalogValidation
{
    public static function requireTitleAndCode(DataObject $record, ValidationResult $result, int $maxCodeLength): void
    {
        if (trim((string) $record->getField('Title')) === '') {
            $result->addFieldError('Title', _t(__CLASS__ . '.TITLE_REQUIRED', 'Naziv je obavezan.'));
        }

        $code = trim((string) $record->getField('Code'));
        $record->setField('Code', $code);
        if ($code === '') {
            $result->addFieldError('Code', _t(__CLASS__ . '.CODE_REQUIRED', 'Oznaka je obavezna.'));

            return;
        }
        if (!preg_match('/^[\p{L}\p{N}_\-\/]{1,' . $maxCodeLength . '}$/u', $code)) {
            $result->addFieldError('Code', _t(
                __CLASS__ . '.CODE_FORMAT',
                'Oznaka: do {max} slova/cifara, bez razmaka.',
                ['max' => $maxCodeLength]
            ));

            return;
        }

        $duplicate = $record::get()
            ->filter('Code', $code)
            ->exclude('ID', (int) $record->ID)
            ->exists();
        if ($duplicate) {
            $result->addFieldError('Code', _t(__CLASS__ . '.CODE_TAKEN', 'Oznaka je već zauzeta.'));
        }
    }

    public static function requireColour(DataObject $record, ValidationResult $result): void
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $record->getField('Colour'))) {
            $result->addFieldError('Colour', _t(__CLASS__ . '.COLOUR', 'Boja u formatu #RRGGBB.'));
        }
    }
}
