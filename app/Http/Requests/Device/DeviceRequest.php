<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Support\DeviceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every device-facing request.
 *
 * The firmware sends `device_id` in the body of most calls. It is checked
 * against the authenticated device and then ignored: the credential decides
 * which panel is talking, and a body field never gets to disagree with it.
 */
abstract class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(DeviceContext::class)->has();
    }

    public function device(): Device
    {
        return app(DeviceContext::class)->deviceOrFail();
    }

    /**
     * Rules shared by every device call. Subclasses merge into this.
     *
     * @return array<string, mixed>
     */
    protected function deviceRules(): array
    {
        return [
            'device_id' => ['sometimes', 'string', 'max:64'],
        ];
    }

    protected function passedValidation(): void
    {
        $claimed = $this->input('device_id');

        if ($claimed !== null && ! hash_equals($this->device()->device_id, (string) $claimed)) {
            // The credential authenticated one panel and the body names
            // another. That is either a misconfigured device or a credential
            // being used to write someone else's history.
            throw ApiException::forbidden(
                'device_mismatch',
                'The device_id in the request body does not match the authenticated device.'
            );
        }
    }
}
