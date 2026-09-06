<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            // The dashboard hides what a user cannot do rather than letting
            // them press it and collect a 403.
            'permissions' => $this->when(
                $request->user()?->is($this->resource) ?? false,
                fn () => $this->getAllPermissions()->pluck('name')->all()
            ),
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'is_super_admin' => $this->isSuperAdmin(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_required' => $this->requiresTwoFactor(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
