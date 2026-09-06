<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification.
 *
 * The QR code is rendered as SVG rather than PNG: it needs no image extension
 * in the container, scales cleanly, and inlines into the dashboard without a
 * second request.
 */
class TwoFactorService
{
    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * Begin enrolment. The secret is stored immediately but unconfirmed, so a
     * user who abandons halfway is not locked out of a half-enrolled state.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $url = $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->renderQr($url),
        ];
    }

    /**
     * Confirm enrolment with a code from the authenticator, returning the
     * one-time recovery codes.
     *
     * @return list<string>|null  Null when the code did not verify.
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        if (! $this->verify($user, $code, allowUnconfirmed: true)) {
            return null;
        }

        $recovery = collect(range(1, 8))
            ->map(fn (): string => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recovery,
        ])->save();

        return $recovery;
    }

    /**
     * Verify a TOTP code, or spend a recovery code.
     */
    public function verify(User $user, string $code, bool $allowUnconfirmed = false): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        if (! $allowUnconfirmed && ! $user->hasTwoFactorEnabled()) {
            return false;
        }

        // A one-step window on either side, for clock drift between the
        // operator's phone and the server.
        if ($this->google2fa->verifyKey($user->two_factor_secret, $code, 1)) {
            return true;
        }

        return $this->spendRecoveryCode($user, $code);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    private function spendRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        $remaining = array_values(array_filter(
            $codes,
            fn (string $candidate): bool => ! hash_equals($candidate, $code),
        ));

        if (count($remaining) === count($codes)) {
            return false;
        }

        // Recovery codes are single use; spending one removes it.
        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    private function renderQr(string $url): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($url);
    }
}
