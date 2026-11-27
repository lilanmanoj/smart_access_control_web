<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Realtime\BrokerUnavailable;
use App\Services\Realtime\DeviceRealtime;
use Illuminate\Console\Command;

/**
 * One-time (and idempotent) set-up of the Mosquitto dynamic-security broker.
 *
 * The broker's first boot creates only the admin login, which may manage
 * logins but not publish. This lets it publish doorbells and pins the default
 * access to deny. Safe to run on every deploy.
 */
class BootstrapMqttBroker extends Command
{
    protected $signature = 'mqtt:bootstrap';

    protected $description = 'Give the backend permission to ring panel doorbells on the MQTT broker';

    public function handle(DeviceRealtime $realtime): int
    {
        if (! config('access.mqtt.enabled')) {
            $this->warn('MQTT_ENABLED is false; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $responses = $realtime->bootstrap();
        } catch (BrokerUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($responses as $response) {
            $error = $response['error'] ?? null;
            $this->line(sprintf(
                '  %-22s %s',
                (string) ($response['command'] ?? '?'),
                $error === null ? 'ok' : "{$error} (expected on a re-run)",
            ));
        }

        $this->info('Broker ready.');

        return self::SUCCESS;
    }
}
