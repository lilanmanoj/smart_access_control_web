<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

class StoreOtpVerificationRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge($this->deviceRules(), [
            'otp_request_id' => ['required', 'uuid'],
            // Six digits: the keypad has no letters on it.
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code must be exactly six digits.',
        ];
    }
}
