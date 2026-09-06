<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Server-side SMS delivery.
 *
 * The panel has a SIM800L of its own, but it fails in all the ordinary ways —
 * no SIM, no signal, no antenna — and reports its status only locally, so the
 * backend never learns whether a message went out. Sending server-side is both
 * more reliable and what lets the OTP stay off the device entirely.
 */
interface SmsGateway
{
    /**
     * Send a message. Returns the outcome rather than throwing: a failed SMS
     * is recorded against the OTP request's delivery log, and e-mail is an
     * independent channel that must still be attempted.
     */
    public function send(string $to, string $message): SmsResult;
}
