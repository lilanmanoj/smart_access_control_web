<?php

declare(strict_types=1);

namespace App\Support;

use Random\Randomizer;

/**
 * Numeric secret generation.
 *
 * The panel's keypad is a 4x3 matrix — digits, star and hash. There is no way
 * to type a letter, so every code this system issues is digits only. This is a
 * hardware constraint, not a style choice: an alphanumeric code is physically
 * unenterable at the door.
 */
final class NumericCode
{
    /**
     * A cryptographically random code of exactly $length digits, leading zeros
     * included — "042915" is a perfectly good code and the device pads to six
     * characters anyway.
     */
    public static function generate(int $length = 6): string
    {
        $randomizer = new Randomizer;
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) $randomizer->getInt(0, 9);
        }

        return $digits;
    }

    /**
     * A set of distinct codes. Duplicates inside one set would silently reduce
     * the number of working backup codes.
     *
     * @return list<string>
     */
    public static function generateSet(int $count, int $length = 6): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $code = self::generate($length);

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function isValid(string $code, int $length = 6): bool
    {
        return (bool) preg_match('/^\d{'.$length.'}$/', $code);
    }
}
