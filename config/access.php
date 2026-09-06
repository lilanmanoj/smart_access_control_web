<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Smart Access Control — domain configuration
|--------------------------------------------------------------------------
|
| Everything here is shaped by the panel's hardware. The keypad is a 4x3
| matrix with no letters on it, so every code the backend generates is
| numeric; the device has no real-time clock, so the server owns every
| authoritative timestamp. See .claude/backend_requirement.md section 2.
|
| Values marked "per-tenant overridable" have a matching key in the tenant's
| `settings` JSON column and are read through App\Support\TenantSettings.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | One-time passwords
    |--------------------------------------------------------------------------
    */

    'otp' => [
        // Six digits, always. An alphanumeric code is physically unenterable
        // on the device's keypad.
        'length' => 6,

        // Seconds. The device's own default OTP TTL is 60s; keeping the server
        // slightly more generous avoids a race where the device still accepts
        // input for a code the server has already expired.
        'ttl' => (int) env('OTP_TTL_SECONDS', 90),

        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),

        // Whether the device may receive the plaintext code in the
        // /otp-requests response. Off is the secure contract (section 7.3):
        // the backend delivers the code and verifies it itself. Turn it on
        // only for legacy firmware that still compares the code locally.
        'return_code_to_device' => (bool) env('OTP_RETURN_CODE_TO_DEVICE', false),

        // Per-tenant overridable. When true, an OTP is only issued to a phone
        // number belonging to a member with an active enrolment on the calling
        // device; when false, any active member of the tenant may receive one.
        'require_enrollment' => (bool) env('OTP_REQUIRE_ENROLLMENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup codes
    |--------------------------------------------------------------------------
    |
    | The device caches a set of five so the door still opens while the network
    | is down. Using any one of them supersedes the whole set.
    |
    */

    'backup_codes' => [
        // The firmware validates for exactly five six-digit codes and rejects
        // the whole set otherwise, disabling backup access until it refetches.
        'per_set' => 5,
        'length' => 6,

        // Per-tenant overridable. False (default) keeps offline operation: the
        // device caches the five plaintext codes. True is the online-only
        // posture — the device caches nothing and calls
        // /backup-code-verifications, losing offline access in exchange.
        'online_verification_only' => (bool) env('BACKUP_CODES_ONLINE_ONLY', false),

        // Sets older than this are rotated by the scheduler even if unused.
        'max_age_days' => (int) env('BACKUP_CODE_MAX_AGE_DAYS', 90),

        // Failed attempts within the window before an alert is raised. Repeated
        // failures against a static secret are the clearest brute-force signal
        // the system has.
        'alert_threshold' => (int) env('BACKUP_CODE_ALERT_THRESHOLD', 5),
        'alert_window_minutes' => (int) env('BACKUP_CODE_ALERT_WINDOW', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    */

    'devices' => [
        // The firmware polls /health every 30s while up. Three missed intervals
        // is the offline threshold.
        'health_interval_seconds' => 30,
        'offline_after_seconds' => (int) env('DEVICE_OFFLINE_AFTER_SECONDS', 100),

        // AS608 sensors in this panel hold 200 templates.
        'default_template_capacity' => 200,

        // Commands the device never picked up are expired rather than left to
        // fire at an arbitrary later date. A stale `unlock` is a security bug.
        'command_ttl_seconds' => (int) env('DEVICE_COMMAND_TTL_SECONDS', 300),

        // How many commands one /device-commands poll may return.
        'command_poll_limit' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Access events
    |--------------------------------------------------------------------------
    */

    'events' => [
        // Devices queue events in NVS while offline and flush on reconnect.
        'max_batch_size' => 100,

        // Anti-passback: a held finger produces bursts. Collapse repeated
        // grants for the same member on the same device inside this window so
        // the log stays readable. Denials are never suppressed.
        'duplicate_suppression_seconds' => (int) env('EVENT_DUPLICATE_WINDOW', 10),

        // Null disables pruning. Retention is a compliance decision — see
        // open question 3 in the requirements.
        'retention_days' => env('EVENT_RETENTION_DAYS') !== null
            ? (int) env('EVENT_RETENTION_DAYS')
            : 365,

    ],

    /*
    |--------------------------------------------------------------------------
    | Phone numbers
    |--------------------------------------------------------------------------
    |
    | Device storage caps phone numbers at 14 characters and the entry screen
    | requires 10+ digits. Anything that will not fit is rejected at the edge.
    |
    */

    'phone' => [
        'default_country_code' => env('PHONE_DEFAULT_COUNTRY_CODE', '+94'),
        'max_length' => 14,
        'min_digits' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS delivery
    |--------------------------------------------------------------------------
    |
    | Server-side delivery is the recommended posture (section 7.3): a gateway
    | is more reliable than a SIM800L on a bench supply, and it keeps the
    | plaintext code away from the device entirely.
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | http | null

        'http' => [
            'endpoint' => env('SMS_HTTP_ENDPOINT'),
            'token' => env('SMS_HTTP_TOKEN'),
            'sender' => env('SMS_SENDER_ID', 'ACCESS'),
            'timeout' => (int) env('SMS_HTTP_TIMEOUT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Per device, per minute. These are the endpoints an attacker holding a
    | device credential would hammer.
    |
    */

    'rate_limits' => [
        'otp_requests' => (int) env('RATE_LIMIT_OTP_REQUESTS', 6),
        'otp_verifications' => (int) env('RATE_LIMIT_OTP_VERIFICATIONS', 10),
        'backup_code_attempts' => (int) env('RATE_LIMIT_BACKUP_ATTEMPTS', 10),
        'access_events' => (int) env('RATE_LIMIT_ACCESS_EVENTS', 120),
        'health' => (int) env('RATE_LIMIT_HEALTH', 30),
        'default' => (int) env('RATE_LIMIT_DEVICE_DEFAULT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security posture
    |--------------------------------------------------------------------------
    */

    'security' => [
        /*
         * Phase-2 HMAC request signing (section 6.1).
         *
         * NOT IMPLEMENTED. It needs a firmware change the panel cannot make
         * yet: signing requires a timestamp, and the device has no reliable
         * clock, so the signature would have to be computed against a
         * server-provided epoch offset.
         *
         * The flag exists so the decision is recorded rather than forgotten.
         * Turning it on does not enable signing — it makes device
         * authentication refuse every request, so a deployment that expects
         * signing fails loudly instead of believing it has protection it does
         * not have.
         */
        'require_request_signature' => (bool) env('DEVICE_REQUIRE_SIGNATURE', false),
        'signature_tolerance_seconds' => 300,

        // Roles that must complete a TOTP challenge before their session is
        // usable. These are the roles that can open doors or change who may.
        'two_factor_required_roles' => ['super_admin', 'tenant_admin', 'device_manager'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime (browser-facing)
    |--------------------------------------------------------------------------
    |
    | What the dashboard's websocket client connects to, delivered to it in the
    | served document. Deliberately separate from the publishing address in
    | config/broadcasting.php: behind a reverse proxy the browser reaches
    | Reverb on 443 at the public hostname, while the server reaches it on the
    | internal network.
    |
    | A null key disables realtime; the dashboard then polls and says so.
    |
    */

    'realtime' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('REVERB_HOST'),
        'port' => (int) env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled access (section 9.7)
    |--------------------------------------------------------------------------
    |
    | Time-of-day / day-of-week windows per member. The schema ships enabled;
    | flip this off to admit members at any hour regardless of their windows.
    |
    */

    'schedules' => [
        'enforce' => (bool) env('ACCESS_SCHEDULES_ENFORCE', true),
        'default_timezone' => env('ACCESS_SCHEDULE_TIMEZONE', 'Asia/Colombo'),
    ],

];
