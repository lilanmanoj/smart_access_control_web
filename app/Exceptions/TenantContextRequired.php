<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;

/**
 * A tenant-owned row was about to be written with no tenant to own it.
 *
 * The only way to reach this is a SuperAdmin acting from the cross-tenant fleet
 * view: everyone else has a tenant bound at the edge of the request, and the
 * device API resolves one from the credential.
 *
 * It exists so that state produces an answer an operator can act on instead of
 * a raw `Field 'tenant_id' doesn't have a default value` from MySQL.
 */
class TenantContextRequired extends ApiException
{
    public static function forModel(string $modelClass): self
    {
        $label = Str::lower(Str::headline(class_basename($modelClass)));

        return new self(
            'tenant_not_selected',
            "You are viewing all tenants, so there is nowhere to put this {$label}. ".
            'Choose a tenant in the switcher first.',
            // 409: the request is well formed, but the session is in a state
            // that cannot satisfy it.
            409,
        );
    }
}
