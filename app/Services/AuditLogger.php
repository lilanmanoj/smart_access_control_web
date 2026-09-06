<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records dashboard mutations with before/after state.
 *
 * Anything that changes who may open a door, or which door, belongs here —
 * including the SuperAdmin tenant switch, which is otherwise invisible.
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

        return AuditLog::create([
            'tenant_id' => $user?->tenant_id ?? ($subject?->getAttribute('tenant_id')),
            'user_id' => $user?->id,
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
        ];

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }
}
