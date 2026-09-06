<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NumericCode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The keypad is a 4x3 matrix — digits, star and hash. There is no way to type
 * a letter, so an alphanumeric code is physically unenterable at the door.
 */
class NumericCodeTest extends TestCase
{
    #[Test]
    public function it_generates_exactly_six_digits(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', NumericCode::generate());
        }
    }

    /**
     * Leading zeros are kept. "042915" is a perfectly good code, and dropping
     * the zero would produce a five-character string the panel rejects.
     */
    #[Test]
    public function it_keeps_leading_zeros(): void
    {
        $codes = array_map(fn (): string => NumericCode::generate(), range(1, 500));
        $withLeadingZero = array_filter($codes, fn (string $code): bool => str_starts_with($code, '0'));

        $this->assertNotEmpty($withLeadingZero);

        foreach ($withLeadingZero as $code) {
            $this->assertSame(6, strlen($code));
        }
    }

    #[Test]
    public function a_set_contains_no_duplicates(): void
    {
        // A duplicate inside one set would silently reduce the number of
        // working backup codes.
        for ($i = 0; $i < 50; $i++) {
            $set = NumericCode::generateSet(5);

            $this->assertCount(5, $set);
            $this->assertCount(5, array_unique($set));
        }
    }

    #[Test]
    public function it_validates_shape(): void
    {
        $this->assertTrue(NumericCode::isValid('042915'));
        $this->assertFalse(NumericCode::isValid('42915'));
        $this->assertFalse(NumericCode::isValid('0429151'));
        $this->assertFalse(NumericCode::isValid('04291A'));
    }
}
