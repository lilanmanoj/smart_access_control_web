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

        $cleaned = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if ($cleaned === '') {
            throw new InvalidArgumentException('A phone number is required.');
        }

        $normalised = match (true) {
            // 0094... — the international prefix written the old way.
            str_starts_with($cleaned, '00') => '+'.substr($cleaned, 2),

            str_starts_with($cleaned, '+') => $cleaned,

            // A national number: drop the trunk prefix, add the country code.
            str_starts_with($cleaned, '0') => $countryCode.substr($cleaned, 1),

            // Bare digits with no trunk prefix — assume the country code is
            // already there. 94771234567 becomes +94771234567.
            default => '+'.$cleaned,
        };

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
        $length = strlen($this->e164);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($this->e164, 0, 5).str_repeat('*', $length - 9).substr($this->e164, -4);
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
