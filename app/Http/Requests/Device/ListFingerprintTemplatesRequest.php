<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Services\FingerprintTemplateService;
use Illuminate\Validation\Rule;

/**
 * GET /fingerprint-templates — query parameters of the template sync.
 */
class ListFingerprintTemplatesRequest extends DeviceRequest
{
    /** The panel parses one page into a 14 KB buffer; ten templates fit. */
    public const MAX_LIMIT = 10;

    public const DEFAULT_LIMIT = 8;

    public const MAX_CAPACITY = 1024;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required here, unlike in a body: it is how a misrouted sync is
            // caught before it deletes anything. A mismatch is a 403.
            'device_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CAPACITY],
            'template_format' => ['required', 'string', Rule::in(FingerprintTemplateService::FORMATS)],
            'offset' => ['nullable', 'integer', 'min:0'],
            // Above the maximum is clamped rather than refused.
            'limit' => ['nullable', 'integer', 'min:1'],
            'since_revision' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'firmware_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array{
     *     capacity: int,
     *     template_format: string,
     *     offset: int,
     *     limit: int,
     *     since_revision: ?string,
     *     enrolled_count: ?int,
     *     firmware_version: ?string,
     * }
     */
    public function syncParameters(): array
    {
        $validated = $this->validated();

        return [
            'capacity' => (int) $validated['capacity'],
            'template_format' => (string) $validated['template_format'],
            'offset' => (int) ($validated['offset'] ?? 0),
            'limit' => min(self::MAX_LIMIT, (int) ($validated['limit'] ?? self::DEFAULT_LIMIT)),
            'since_revision' => isset($validated['since_revision']) ? (string) $validated['since_revision'] : null,
            'enrolled_count' => isset($validated['enrolled_count']) ? (int) $validated['enrolled_count'] : null,
            'firmware_version' => isset($validated['firmware_version']) ? (string) $validated['firmware_version'] : null,
        ];
    }
}
