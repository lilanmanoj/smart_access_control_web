<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Policies\Concerns\AuthorizesWithinTenant;

/**
 * Webhooks carry access data off the platform, so they are gated on tenant
 * administration rather than on a weaker device or event permission.
 */
class WebhookEndpointPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'webhook.manage');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, 'webhook.manage', $endpoint);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'webhook.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, 'webhook.manage', $endpoint);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->allows($user, 'webhook.manage', $endpoint);
    }
}
