<?php

namespace App\Config;

/**
 * The date formats offered to companies.
 *
 * Presentation only. A format is a display choice, not a parsing rule, so it is
 * validated against this list and always rendered through Carbon rather than
 * being used in date arithmetic.
 */
final class DateFormats
{
    public const DEFAULT = 'Y-m-d';

    /**
     * @var array<int, string>
     */
    public const ALL = [
        'Y-m-d',
        'd/m/Y',
        'd-m-Y',
        'm/d/Y',
        'd.m.Y',
        'Y/m/d',
        'F j, Y',
        'j F Y',
    ];

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return self::ALL;
    }
}
