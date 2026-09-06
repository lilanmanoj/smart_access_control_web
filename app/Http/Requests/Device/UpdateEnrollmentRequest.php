<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

class UpdateEnrollmentRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            // Length is capped at what the panel itself can store; the value
            // is normalised to E.164 server-side.
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'fingerprint_slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
