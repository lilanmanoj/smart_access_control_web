<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Enums\TemplateSyncStatus;
use App\Services\FingerprintTemplateService;
use Illuminate\Validation\Rule;

/**
 * POST /fingerprint-template-syncs — the `TemplateSyncReport` schema.
 *
 * Only what is declared is kept; properties newer firmware adds are ignored
 * rather than rejected, because the panel does not retry a refused report.
 */
class StoreTemplateSyncReportRequest extends DeviceRequest
{
    public const ABORT_REASONS = [
        'http_error', 'invalid_response', 'capacity_exceeded',
        'revision_changed', 'sensor_error', 'out_of_memory',
    ];

    public const SYNC_ERRORS = [
        'invalid_slot', 'duplicate_slot', 'unsupported_format', 'invalid_encoding',
        'size_mismatch', 'sha256_mismatch', 'sensor_not_ready', 'sensor_error',
        'download_rejected', 'store_failed', 'verify_failed',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $count = ['nullable', 'integer', 'min:0', 'max:2147483647'];
        $requiredCount = ['required', 'integer', 'min:0', 'max:2147483647'];

        return array_merge($this->deviceRules(), [
            'device_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Required to be present; an empty string arrives here as null.
            'revision' => ['present', 'nullable', 'string', 'max:128'],
            'status' => ['required', 'string', Rule::in(TemplateSyncStatus::values())],
            'abort_reason' => ['nullable', 'string', Rule::in(self::ABORT_REASONS)],
            'template_format' => ['nullable', 'string', Rule::in(FingerprintTemplateService::FORMATS)],
            'capacity' => ['required', 'integer', 'min:1', 'max:65535'],
            'enrolled_count' => $count,
            'written' => $requiredCount,
            'unchanged' => $requiredCount,
            'deleted' => $requiredCount,
            'retained' => $count,
            'missing' => $count,
            'failed' => $requiredCount,
            'duration_ms' => $count,
            'firmware_version' => ['nullable', 'string', 'max:32'],
            'errors' => ['nullable', 'array', 'max:16'],
            'errors.*' => ['array'],
            'errors.*.slot' => ['required', 'integer', 'min:0', 'max:65535'],
            'errors.*.error' => ['required', 'string', Rule::in(self::SYNC_ERRORS)],
            // A sensor's slots are below its capacity, which a sync caps at 1024.
            'slots_present' => ['nullable', 'array', 'max:1024'],
            'slots_present.*' => ['integer', 'min:0', 'max:65535'],
        ]);
    }
}
