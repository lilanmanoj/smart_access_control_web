<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Support\DeviceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records dashboard mutations with before/after state.
 *
 * Anything that changes who may open a door, or which door, belongs here —
 * including the SuperAdmin tenant switch, which is otherwise invisible. A few
 * entries are written by a panel rather than an operator (storing or erasing a
 * fingerprint template); those name the device as the actor.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        $user = Auth::user();
        $device = $user === null ? app(DeviceContext::class)->device() : null;

        return AuditLog::create([
            'tenant_id' => $user?->tenant_id ?? $device?->tenant_id ?? ($subject?->getAttribute('tenant_id')),
            'user_id' => $user?->id,
            'device_id' => $device?->id,
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'before' => $before === null ? null : $this->redact($before),
            'after' => $after === null ? null : $this->redact($after),
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }

    /**
     * Convenience for the common "record what changed on this model" case.
     *
     * @param  array<string, mixed>  $before
     */
    public function logChange(string $action, Model $subject, array $before): AuditLog
    {
        return $this->log($action, $subject, $before, $subject->getAttributes());
    }

    /**
     * No secret is ever written to a log, including this one. Redaction is
     * done here rather than at each call site so a new caller cannot forget.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function redact(array $attributes): array
    {
        $sensitive = [
            'password', 'remember_token', 'api_secret', 'api_secret_hash',
            'code_hash', 'secret', 'two_factor_secret', 'two_factor_recovery_codes',
            // Biometric, not secret, but it must never be logged either.
            'data_encrypted', 'fingerprint_template',
        ];

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }
}
