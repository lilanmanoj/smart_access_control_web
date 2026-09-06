<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

class StoreOtpRequestRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            'phone' => ['required', 'string', 'min:9', 'max:20'],
        ]);
    }
}
