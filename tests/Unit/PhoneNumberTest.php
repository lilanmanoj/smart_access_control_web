<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phone normalisation is where the hardware constraints bite hardest: device
 * storage caps a number at 14 characters, the entry screen requires 10+
 * digits, and the OTP lookup matches on exactly this column.
 */
class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function normalisationCases(): array
    {
        return [
            'national with trunk prefix' => ['0771234567', '+94771234567'],
            'already E.164' => ['+94771234567', '+94771234567'],
            'international 00 prefix' => ['0094771234567', '+94771234567'],
            'bare country code' => ['94771234567', '+94771234567'],
            'spaces and dashes' => ['077 123-4567', '+94771234567'],
            'parentheses' => ['(077) 1234567', '+94771234567'],
            // The keypad has no plus key, so people improvise.
            'country code and trunk prefix' => ['940771234567', '+94771234567'],
            'plus, country code and trunk prefix' => ['+940771234567', '+94771234567'],
            '00, country code and trunk prefix' => ['00940771234567', '+94771234567'],
            'star for plus' => ['*94771234567', '+94771234567'],
            'hash for plus' => ['#94771234567', '+94771234567'],
            'hash as the enter key' => ['0771234567#', '+94771234567'],
            'star before a national number' => ['*0771234567', '+94771234567'],
        ];
    }

    #[Test]
    #[DataProvider('normalisationCases')]
    public function it_normalises_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, (string) PhoneNumber::parse($input));
    }

    /**
     * Only the ends are trimmed. A symbol in the middle is a typo, and reading
     * past it could match somebody else's number.
     */
    #[Test]
    public function a_symbol_inside_the_number_is_not_guessed_past(): void
    {
        $this->assertNull(PhoneNumber::tryParse('077*1234567'));
    }

    /** Another country's numbers are left alone, whatever digit follows the code. */
    #[Test]
    public function the_trunk_prefix_is_only_dropped_after_the_home_country_code(): void
    {
        $this->assertSame('+390612345678', (string) PhoneNumber::parse('+390612345678'));
    }

    #[Test]
    public function a_country_without_a_trunk_prefix_can_turn_it_off(): void
    {
        config(['access.phone.trunk_prefix' => '']);

        $this->assertSame('+940771234567', (string) PhoneNumber::parse('940771234567'));
    }

    #[Test]
    public function unparseable_input_is_masked_for_the_log(): void
    {
        $this->assertSame('077*1**4567', PhoneNumber::maskInput('077*1234567'));
    }

    /**
     * E.164 allows 15 digits. The panel's field holds 14 characters including
     * the plus, so the usable range is narrower than the standard's.
     */
    #[Test]
    public function it_rejects_a_number_the_device_cannot_store(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 14 characters/');

        PhoneNumber::parse('+123456789012345');
    }

    #[Test]
    public function it_rejects_a_number_shorter_than_the_entry_screen_requires(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::parse('+9477123');
    }

    #[Test]
    public function it_rejects_nonsense(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::parse('not a phone number');
    }

    #[Test]
    public function try_parse_returns_null_instead_of_throwing(): void
    {
        // The device path logs a denial rather than surfacing an exception.
        $this->assertNull(PhoneNumber::tryParse('nope'));
        $this->assertNotNull(PhoneNumber::tryParse('0771234567'));
    }

    #[Test]
    public function masking_keeps_enough_to_recognise_and_not_enough_to_dial(): void
    {
        $masked = PhoneNumber::parse('+94771234567')->masked();

        $this->assertStringStartsWith('+9477', $masked);
        $this->assertStringEndsWith('4567', $masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertSame(strlen('+94771234567'), strlen($masked));
    }
}
