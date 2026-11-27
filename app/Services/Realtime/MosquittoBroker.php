<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

/**
 * Mosquitto 2 with the dynamic-security plugin, over php-mqtt.
 *
 * One short-lived connection per call: the backend publishes a handful of
 * doorbells per operator action, and a held connection would have to survive
 * worker restarts and broker restarts for no measurable gain on an internal
 * network.
 */
class MosquittoBroker implements Broker
{
    private const CONTROL_TOPIC = '$CONTROL/dynamic-security/v1';

    private const RESPONSE_TOPIC = '$CONTROL/dynamic-security/v1/response';

    public function publish(string $topic, string $message): void
    {
        $this->withClient(function (MqttClient $client) use ($topic, $message): void {
            $client->publish($topic, $message, MqttClient::QOS_AT_MOST_ONCE, false);
        });
    }

    public function control(array $commands): array
    {
        // The response topic is shared by every admin connection, so each
        // command carries a correlation id and only our own answers count.
        $correlation = Str::random(16);
        $commands = array_map(
            fn (array $command, int $i): array => $command + ['correlationData' => "{$correlation}-{$i}"],
            $commands,
            array_keys($commands),
        );

        $responses = null;
        $timeout = (float) config('access.mqtt.control_timeout_seconds', 3);

        $this->withClient(function (MqttClient $client) use ($commands, $correlation, $timeout, &$responses): void {
            $client->subscribe(self::RESPONSE_TOPIC, function (string $topic, string $message) use ($client, $correlation, &$responses): void {
                $decoded = json_decode($message, true);
                $first = $decoded['responses'][0]['correlationData'] ?? null;

                if (is_string($first) && str_starts_with($first, $correlation.'-')) {
                    $responses = $decoded['responses'];
                    $client->interrupt();
                }
            }, MqttClient::QOS_AT_MOST_ONCE);

            $client->publish(self::CONTROL_TOPIC, (string) json_encode(['commands' => $commands]), MqttClient::QOS_AT_MOST_ONCE, false);

            $client->registerLoopEventHandler(function (MqttClient $client, float $elapsed) use ($timeout): void {
                if ($elapsed >= $timeout) {
                    $client->interrupt();
                }
            });

            $client->loop(true);
        });

        if (! is_array($responses)) {
            throw new BrokerUnavailable('The broker did not answer the dynamic-security request in time.');
        }

        return array_values($responses);
    }

    /** @param  callable(MqttClient): void  $work */
    private function withClient(callable $work): void
    {
        $timeout = (int) config('access.mqtt.timeout_seconds', 1);

        try {
            $client = new MqttClient(
                (string) config('access.mqtt.host'),
                (int) config('access.mqtt.port'),
                'sa-backend-'.Str::lower(Str::random(10)),
                MqttClient::MQTT_3_1_1,
            );

            $client->connect(
                (new ConnectionSettings)
                    ->setUsername((string) config('access.mqtt.username'))
                    ->setPassword((string) config('access.mqtt.password'))
                    ->setConnectTimeout($timeout)
                    ->setSocketTimeout($timeout)
                    ->setKeepAliveInterval(10),
                true,
            );
        } catch (Throwable $e) {
            // Never pass the original message on verbatim: it is a connection
            // error today, but nothing guarantees it stays free of settings.
            throw new BrokerUnavailable('Could not connect to the MQTT broker ('.class_basename($e).').', 0, $e);
        }

        try {
            $work($client);
        } catch (Throwable $e) {
            throw new BrokerUnavailable('MQTT request failed ('.class_basename($e).').', 0, $e);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Already gone; nothing to clean up.
            }
        }
    }
}
