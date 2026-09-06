<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

/**
 * The admin gate: does this matched fingerprint belong to an administrator?
 */
class StoreAuthenticationRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            // Named `enrollment_id` by the firmware, but it is the
            // device-local fingerprint slot, not a member or enrolment id.
            'enrollment_id' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
