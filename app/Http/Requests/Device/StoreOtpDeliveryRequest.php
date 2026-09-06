<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

/**
 * Validates the legacy /otp-deliveries payload without acting on it.
 *
 * `otp` is accepted so shipped firmware does not get a 422, and then ignored:
 * re-sending a code supplied by the caller would let anyone holding a device
 * credential post arbitrary text to a member's e-mail address.
 */
class StoreOtpDeliveryRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            'phone' => ['sometimes', 'string', 'max:20'],
            'otp' => ['sometimes', 'string', 'max:12'],
        ]);
    }
}
