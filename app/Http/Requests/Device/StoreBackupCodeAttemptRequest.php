<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

class StoreBackupCodeAttemptRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            // What the panel decided locally. Recorded, but the backend
            // reaches its own conclusion rather than taking its word.
            'accepted' => ['sometimes', 'boolean'],
        ]);
    }
}
