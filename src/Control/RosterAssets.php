<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareHome\Roster\Control;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Plain CSS/JS of the add-on (no build step). Alpine.js comes from the base module's bundle.
 */
class RosterAssets
{
    use Injectable;

    private const string MODULE = 'youwilllikeit/silverstripe-carehome-roster';

    public function url(string $file): string
    {
        // the resource URL generator appends a cache-busting ?m=<mtime>
        return (string) ModuleResourceLoader::resourceURL(self::MODULE . ':client/' . $file);
    }

    public function stylesheet(string $file): DBField
    {
        return DBField::create_field('HTMLFragment', '<link rel="stylesheet" href="' . htmlspecialchars($this->url($file)) . '">');
    }

    /** Must be emitted before the base bundle so the components register on alpine:init. */
    public function script(string $file): DBField
    {
        return DBField::create_field('HTMLFragment', '<script src="' . htmlspecialchars($this->url($file)) . '"></script>');
    }
}
