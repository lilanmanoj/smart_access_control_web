<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

class StoreEnrollmentRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            // Device-local slot id, 0 .. capacity-1. Null is legitimate: the
            // user may have skipped the finger, or registration failed and the
            // template was rolled back.
            'fingerprint_slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
