<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The MQTT doorbell, from the backend's side (device API 1.4.0).
 *
 * Owns three things:
 *
 *  - the naming: each panel gets the username `dev-<uuid>`, client id
 *    `<uuid>` and topic `sa/v1/dev/<uuid>/notify`, keyed on the UUID because
 *    the device-asserted `device_id` is only unique within a tenant;
 *  - the panel's broker login: created or re-passworded on every
 *    registration, so each password exists in exactly one response, and
 *    disabled when the panel is deactivated or deleted;
 *  - ringing: one empty-ish QoS 0 message, never retained.
 *
 * Nothing here is allowed to break the request it runs in. A broker failure
 * is logged and the backend stops trying for a short back-off; the panel's
 * 30-second /health poll delivers everything regardless.
 */
class DeviceRealtime
{
    private const UNAVAILABLE_KEY = 'mqtt:unavailable';

    /** What a doorbell carries. The panel ignores it. */
    public const DOORBELL_PAYLOAD = '{"v":1}';

    public function __construct(
        private readonly Broker $broker,
    ) {}

    /** Whether panels should be offered the doorbell at all. */
    public function enabled(): bool
    {
        return (bool) config('access.mqtt.enabled')
            && filled(config('access.mqtt.public_host'))
            && filled(config('access.mqtt.password'));
    }

    public function topicFor(Device $device): string
    {
        return config('access.mqtt.topic_prefix', 'sa/v1/dev').'/'.$device->uuid.'/notify';
    }

    public function usernameFor(Device $device): string
    {
        return 'dev-'.$device->uuid;
    }

    public function clientIdFor(Device $device): string
    {
        return (string) $device->uuid;
    }

    /**
     * Tell the panel to fetch its commands now. Never throws.
     *
     * @return bool Whether the doorbell was published.
     */
    public function ring(Device $device): bool
    {
        if (! $this->enabled() || $this->backingOff()) {
            return false;
        }

        try {
            $this->broker->publish($this->topicFor($device), self::DOORBELL_PAYLOAD);

            return true;
        } catch (BrokerUnavailable $e) {
            $this->failed('doorbell', $device, $e);

            return false;
        }
    }

    /**
     * Issue the panel a fresh broker login and return the `realtime` object
     * for its registration response, or null when the doorbell is off, the
     * panel is not active, or the broker could not be reached.
     *
     * The password is generated here and appears in this one response only.
     * Setting it on the broker disconnects any session using the old one.
     *
     * @return array{protocol: string, host: string, port: int, username: string, password: string, client_id: string, topic: string, keepalive: int}|null
     */
    public function issueCredentials(Device $device): ?array
    {
        if (! $this->enabled() || $device->status !== DeviceStatus::Active || $this->backingOff()) {
            return null;
        }

        $username = $this->usernameFor($device);
        $clientId = $this->clientIdFor($device);
        $topic = $this->topicFor($device);
        $password = Str::password(32, symbols: false);

        try {
            $responses = $this->broker->control([
                // Idempotent set-up: each of these answers with an "already
                // exists" error on every registration after the first, which
                // is expected and ignored.
                ['command' => 'createRole', 'rolename' => $username, 'acls' => [
                    ['acltype' => 'subscribeLiteral', 'topic' => $topic, 'allow' => true],
                ]],
                ['command' => 'createClient', 'username' => $username, 'password' => $password,
                    'clientid' => $clientId, 'roles' => [['rolename' => $username]]],
                // What actually has to succeed, every time.
                ['command' => 'setClientPassword', 'username' => $username, 'password' => $password],
                ['command' => 'setClientId', 'username' => $username, 'clientid' => $clientId],
                ['command' => 'addClientRole', 'username' => $username, 'rolename' => $username],
                ['command' => 'enableClient', 'username' => $username],
            ]);
        } catch (BrokerUnavailable $e) {
            $this->failed('credential issue', $device, $e);

            return null;
        }

        foreach ($responses as $response) {
            $command = (string) ($response['command'] ?? '');
            $error = $response['error'] ?? null;

            if ($error === null || in_array($command, ['createRole', 'createClient', 'addClientRole'], true)) {
                continue;
            }

            Log::warning('MQTT broker refused a panel login change.', [
                'device' => $device->uuid,
                'command' => $command,
                'error' => (string) $error,
            ]);

            return null;
        }

        return [
            'protocol' => 'mqtt',
            'host' => (string) config('access.mqtt.public_host'),
            'port' => (int) config('access.mqtt.public_port', 1883),
            'username' => $username,
            'password' => $password,
            'client_id' => $clientId,
            'topic' => $topic,
            'keepalive' => (int) config('access.mqtt.keepalive_seconds', 60),
        ];
    }

    /**
     * Shut the panel out of the broker: deactivated or deleted. Disabling a
     * client disconnects its live session at once. Never throws.
     */
    public function revokeCredentials(Device $device): bool
    {
        return $this->setClientEnabled($device, false);
    }

    /**
     * Let a reactivated panel back in with the login it already holds. A
     * panel that never registered has no login, and the broker says so; that
     * is harmless. Never throws.
     */
    public function restoreCredentials(Device $device): bool
    {
        return $this->setClientEnabled($device, true);
    }

    /**
     * One-time broker set-up, idempotent: lets the backend's admin login
     * publish doorbells, and pins the default access to deny.
     *
     * Changing a login's roles makes the broker disconnect that login's
     * sessions — including, here, the very connection making the change. So
     * the role is added in a call of its own whose dropped connection is
     * expected, and the result is then read back rather than assumed.
     *
     * @return list<array<string, mixed>> The broker's responses.
     */
    public function bootstrap(): array
    {
        $admin = (string) config('access.mqtt.username');
        $publisherRole = 'sa-backend-publisher';
        $topicFilter = config('access.mqtt.topic_prefix', 'sa/v1/dev').'/+/notify';

        $responses = $this->broker->control([
            ['command' => 'createRole', 'rolename' => $publisherRole, 'acls' => [
                ['acltype' => 'publishClientSend', 'topic' => $topicFilter, 'allow' => true],
            ]],
            ['command' => 'setDefaultACLAccess', 'acls' => [
                ['acltype' => 'publishClientSend', 'allow' => false],
                ['acltype' => 'publishClientReceive', 'allow' => true],
                ['acltype' => 'subscribe', 'allow' => false],
                ['acltype' => 'unsubscribe', 'allow' => true],
            ]],
        ]);

        if (! $this->clientHasRole($admin, $publisherRole)) {
            try {
                $this->broker->control([
                    ['command' => 'addClientRole', 'username' => $admin, 'rolename' => $publisherRole],
                ]);
            } catch (BrokerUnavailable) {
                // Expected: the broker drops the connection whose roles changed.
            }

            if (! $this->clientHasRole($admin, $publisherRole)) {
                throw new BrokerUnavailable("The broker did not give '{$admin}' the {$publisherRole} role.");
            }

            $responses[] = ['command' => 'addClientRole'];
        } else {
            $responses[] = ['command' => 'addClientRole', 'error' => 'Role already assigned'];
        }

        return $responses;
    }

    private function clientHasRole(string $username, string $role): bool
    {
        $response = $this->broker->control([['command' => 'getClient', 'username' => $username]])[0] ?? [];
        $roles = $response['data']['client']['roles'] ?? [];

        return collect($roles)->contains(fn ($r): bool => ($r['rolename'] ?? null) === $role);
    }

    private function setClientEnabled(Device $device, bool $enabled): bool
    {
        if (! (bool) config('access.mqtt.enabled') || ! filled(config('access.mqtt.password'))) {
            return false;
        }

        try {
            $this->broker->control([
                ['command' => $enabled ? 'enableClient' : 'disableClient', 'username' => $this->usernameFor($device)],
            ]);

            return true;
        } catch (BrokerUnavailable $e) {
            // Revoking: the panel's API access is already gone, and with it
            // any use of a doorbell. Restoring: the panel re-registers within
            // the hour once the broker rejects its login.
            $this->failed($enabled ? 'credential restore' : 'credential revoke', $device, $e);

            return false;
        }
    }

    private function backingOff(): bool
    {
        return Cache::has(self::UNAVAILABLE_KEY);
    }

    private function failed(string $what, Device $device, BrokerUnavailable $e): void
    {
        Cache::put(self::UNAVAILABLE_KEY, true, (int) config('access.mqtt.backoff_seconds', 30));

        Log::warning("MQTT {$what} failed; panels fall back to /health polling.", [
            'device' => $device->uuid,
            'reason' => $e->getMessage(),
        ]);
    }
}
