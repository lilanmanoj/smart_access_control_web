<?php

declare(strict_types=1);

namespace App\Services\Realtime;

/**
 * The backend's two uses of the MQTT broker: ringing a doorbell, and managing
 * panel logins through the broker's dynamic-security control topic.
 *
 * Every connection is the backend's own, on the internal Docker network.
 * Implementations throw {@see BrokerUnavailable} on any failure; callers
 * decide whether that matters (it never does for a doorbell).
 */
interface Broker
{
    /** Publish once: QoS 0, never retained. */
    public function publish(string $topic, string $message): void;

    /**
     * Send dynamic-security commands and return the broker's responses, one
     * per command, in order. Each response may carry an `error` string.
     *
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    public function control(array $commands): array;
}
