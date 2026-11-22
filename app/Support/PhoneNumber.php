<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * E.164 normalisation, bounded by what the panel can actually store.
 *
 * Device storage caps a phone number at 14 characters and the entry screen
 * requires at least 10 digits, so the usable range is narrower than E.164's
 * own 15 digits. Normalising here — once, at the edge — is what makes the OTP
 * lookup work: the user types 0771234567 on the keypad and the member row
 * holds +94771234567.
 *
 * The keypad is digits, `*` and `#`, with no `+`. So everything a person might
 * reasonably type for the same number has to land on the same E.164 string:
 *
 *   0771234567       national, with the trunk prefix
 *   94771234567      country code, no plus
 *   940771234567     country code *and* the trunk prefix, which is how most
 *                    people write their number when told to "add the code"
 *   0094771234567    the international dialling prefix
 *   *94771234567     `*` or `#` where the plus would go; `#` as the enter key
 */
final class PhoneNumber
{
    private function __construct(
        public readonly string $e164,
    ) {}

    /**
     * Normalise a number, or fail with a message safe to show an operator.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input, ?string $defaultCountryCode = null): self
    {
        $countryCode = $defaultCountryCode ?? (string) config('access.phone.default_country_code', '+94');
        $trunk = (string) config('access.phone.trunk_prefix', '0');

        $cleaned = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        // The keypad has no plus key. A `*` or `#` typed where the plus would
        // go, and a `#` pressed as the enter key, carry no digits of the
        // number. Only the ends are trimmed: a symbol in the middle is a typo,
        // and guessing past it could match somebody else's number.
        $cleaned = preg_replace('/^[*#]+|#+$/', '', $cleaned) ?? '';

        if ($cleaned === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        $normalised = match (true) {
            // 0094... — the international prefix written the old way.
            str_starts_with($cleaned, '00') => '+'.substr($cleaned, 2),

            str_starts_with($cleaned, '+') => $cleaned,

            // A national number: drop the trunk prefix, add the country code.
            $trunk !== '' && str_starts_with($cleaned, $trunk) => $countryCode.substr($cleaned, strlen($trunk)),

            // Bare digits with no trunk prefix — assume the country code is
            // already there. 94771234567 becomes +94771234567.
            default => '+'.$cleaned,
        };

        // The country code followed by the trunk prefix: 94 0771234567. The
        // trunk prefix is never part of an international number, so it is
        // dropped. Only for the home country, whose trunk prefix is known —
        // some countries (Italy) keep a leading 0 after the country code.
        if ($trunk !== '' && str_starts_with($normalised, $countryCode.$trunk)) {
            $normalised = $countryCode.substr($normalised, strlen($countryCode.$trunk));
        }

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $normalised)) {
            throw new InvalidArgumentException('That is not a valid international phone number.');
        }

        $maxLength = (int) config('access.phone.max_length', 14);

        if (strlen($normalised) > $maxLength) {
            throw new InvalidArgumentException(
                "Phone numbers must be at most {$maxLength} characters in international format; ".
                'the panel cannot store anything longer.'
            );
        }

        $digitCount = strlen($normalised) - 1;
        $minDigits = (int) config('access.phone.min_digits', 10);

        if ($digitCount < $minDigits) {
            throw new InvalidArgumentException("Phone numbers must contain at least {$minDigits} digits.");
        }

        return new self($normalised);
    }

    /**
     * Normalise without throwing. Used on the device path, where a malformed
     * number is a denial to be logged rather than an exception to surface.
     */
    public static function tryParse(string $input, ?string $defaultCountryCode = null): ?self
    {
        try {
            return self::parse($input, $defaultCountryCode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Partially masked, for logs and denial notices: +9477***4567.
     */
    public function masked(): string
    {
        return self::mask($this->e164);
    }

    /**
     * Mask what was typed when it could not be parsed, so an operator can see
     * the shape of the mistake without the log holding the whole number.
     */
    public static function maskInput(string $input): string
    {
        return self::mask(substr(trim($input), 0, 20));
    }

    private static function mask(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 5).str_repeat('*', $length - 9).substr($value, -4);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
