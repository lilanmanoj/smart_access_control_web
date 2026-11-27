<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Realtime\Broker;
use App\Services\Realtime\BrokerUnavailable;

/**
 * An in-memory MQTT broker: records doorbells and dynamic-security commands,
 * and answers "already exists" for set-up it has seen before, as Mosquitto
 * does.
 */
class FakeBroker implements Broker
{
    /** @var list<array{topic: string, message: string}> */
    public array $published = [];

    /** @var list<array<string, mixed>> */
    public array $commands = [];

    public bool $down = false;

    /** @var array<string, true> */
    private array $existing = [];

    /** @var array<string, list<string>> Roles per username, from addClientRole. */
    private array $roles = [];

    /**
     * Mosquitto drops the connection whose own roles change. When set, an
     * addClientRole for this username fails the call after applying it.
     */
    public ?string $dropsOnOwnRoleChange = null;

    public function publish(string $topic, string $message): void
    {
        if ($this->down) {
            throw new BrokerUnavailable('Could not connect to the MQTT broker (fake).');
        }

        $this->published[] = ['topic' => $topic, 'message' => $message];
    }

    public function control(array $commands): array
    {
        if ($this->down) {
            throw new BrokerUnavailable('Could not connect to the MQTT broker (fake).');
        }

        $responses = [];

        foreach ($commands as $command) {
            $this->commands[] = $command;
            $name = (string) $command['command'];
            $key = $name.':'.($command['rolename'] ?? $command['username'] ?? '');

            $response = ['command' => $name];

            if (in_array($name, ['createRole', 'createClient'], true)) {
                if (isset($this->existing[$key])) {
                    $response['error'] = $name === 'createRole' ? 'Role already exists' : 'Client already exists';
                }
                $this->existing[$key] = true;
            }

            if ($name === 'addClientRole') {
                $username = (string) $command['username'];
                $this->roles[$username] = array_values(array_unique([...($this->roles[$username] ?? []), (string) $command['rolename']]));

                if ($username === $this->dropsOnOwnRoleChange) {
                    throw new BrokerUnavailable('MQTT request failed (DataTransferException).');
                }
            }

            if ($name === 'getClient') {
                $response['data'] = ['client' => [
                    'username' => $command['username'],
                    'roles' => array_map(fn (string $r): array => ['rolename' => $r], $this->roles[(string) $command['username']] ?? []),
                ]];
            }

            $responses[] = $response;
        }

        return $responses;
    }

    /** @return list<string> The passwords set for one username, in order. */
    public function passwordsFor(string $username): array
    {
        return array_values(array_map(
            fn (array $c): string => (string) $c['password'],
            array_filter($this->commands, fn (array $c): bool => $c['command'] === 'setClientPassword' && $c['username'] === $username),
        ));
    }

    /** @return list<string> */
    public function commandNames(): array
    {
        return array_map(fn (array $c): string => (string) $c['command'], $this->commands);
    }
}
