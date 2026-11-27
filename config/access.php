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
    | Members
    |--------------------------------------------------------------------------
    */

    'members' => [
        // Per-tenant overridable. When true, a member created by enrolment at
        // a panel waits for an operator to approve them: until then OTP and
        // Admin Settings refuse them, and their fingerprint stays on the panel
        // they enrolled at (where it already works) instead of reaching every
        // door. Members added from the dashboard are approved by whoever adds
        // them. Off by default, which is the behaviour before this existed.
        'require_approval' => (bool) env('MEMBER_REQUIRE_APPROVAL', false),
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
        // The panel validates for exactly five six-digit codes and rejects the
        // whole set otherwise, keeping the set it already holds.
        'per_set' => 5,
        'length' => 6,

        // Per-tenant overridable. False (default) keeps offline operation: the
        // device caches the five plaintext codes. True is the online-only
        // posture — the device caches nothing and calls
        // /backup-code-verifications, losing offline access in exchange.
        'online_verification_only' => (bool) env('BACKUP_CODES_ONLINE_ONLY', false),

        // Sets older than this are rotated by the scheduler even if unused.
        // 0 turns it off, which is recommended: the replacement reaches the
        // door at its next sync, but no operator has seen its codes.
        'max_age_days' => (int) env('BACKUP_CODE_MAX_AGE_DAYS', 0),

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

        // An unlock opens a door, so it lives for two health intervals at most:
        // long enough to survive one missed poll, short enough that it can
        // never open the door for somebody who has already walked away.
        'unlock_ttl_seconds' => (int) env('DEVICE_UNLOCK_TTL_SECONDS', 60),

        // A sync_templates may wait for the panel to be idle. If it expires
        // the panel's own scheduled sync still covers the change.
        'sync_templates_ttl_seconds' => (int) env('DEVICE_SYNC_TEMPLATES_TTL_SECONDS', 1800),

        // How many commands one /device-commands poll may return.
        'command_poll_limit' => 10,

        // The panel reads at most 4096 bytes of a /health or /device-commands
        // body. Commands are added to a response only while their encoded size
        // stays inside this budget; the envelope (status, server_time, device
        // identity) is bounded well below the remainder. Anything that does
        // not fit waits for the next poll — unlocks are listed first, so they
        // always fit.
        'command_response_budget_bytes' => 3000,

        // The largest device-facing command payload, encoded, in bytes.
        'command_payload_max_bytes' => 256,
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
        // Dialled before a national number and dropped from the international
        // form: 0771234567 is +94771234567. Empty for a country without one.
        'trunk_prefix' => env('PHONE_TRUNK_PREFIX', '0'),
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
        // Shared by the template-sync page fetches and its report. A full
        // 1024-slot sensor at the panel's page size of 8 is 128 pages.
        'template_sync' => (int) env('RATE_LIMIT_TEMPLATE_SYNC', 180),
        // The doorbell fetch. A panel merges doorbells to one fetch every few
        // seconds, so this only bites a panel that is misbehaving.
        'device_commands' => (int) env('RATE_LIMIT_DEVICE_COMMANDS', 30),
        // Per HOUR, not per minute: a panel registers once per boot and once
        // more each time the broker rejects its login.
        'registrations_per_hour' => (int) env('RATE_LIMIT_REGISTRATIONS_PER_HOUR', 10),
        'default' => (int) env('RATE_LIMIT_DEVICE_DEFAULT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | MQTT doorbell (device API 1.4.0)
    |--------------------------------------------------------------------------
    |
    | A panel cannot be pushed to over HTTPS, so it polls /health every 30s.
    | With this enabled the backend also rings a doorbell on an MQTT broker
    | (Mosquitto with its dynamic-security plugin) whenever it queues a command
    | for a panel; the panel then fetches GET /device-commands at once.
    |
    | The doorbell carries no data and no authority. Every command is still
    | fetched and acknowledged over the authenticated HTTPS API, so a forged or
    | replayed doorbell can only cause one extra authenticated poll, and with
    | the broker down everything still arrives through /health.
    |
    | Two addresses, because they differ in production: `host`/`port` is how
    | the backend reaches the broker (the internal Docker network); the
    | `public_*` pair is what panels are told to connect to. With no public
    | host the doorbell stays off for panels even if `enabled` is true.
    |
    */

    'mqtt' => [
        'enabled' => (bool) env('MQTT_ENABLED', false),

        'host' => env('MQTT_HOST', 'mosquitto'),
        'port' => (int) env('MQTT_PORT', 1883),

        // The dynamic-security admin. It manages panel logins and, once
        // `php artisan mqtt:bootstrap` has run, may also publish doorbells.
        // It never leaves the Docker network.
        'username' => env('MQTT_ADMIN_USERNAME', 'sa-backend'),
        'password' => env('MQTT_ADMIN_PASSWORD'),

        'public_host' => env('MQTT_PUBLIC_HOST'),
        'public_port' => (int) env('MQTT_PUBLIC_PORT', 1883),

        // Offered to panels in the `realtime` object.
        'keepalive_seconds' => (int) env('MQTT_DEVICE_KEEPALIVE', 60),

        'topic_prefix' => 'sa/v1/dev',

        // Connect and read timeouts for the backend's own connections. Kept
        // short: a doorbell is published inside the dashboard request.
        'timeout_seconds' => 1,
        'control_timeout_seconds' => 3,

        // After a failure the backend stops trying for this long, so a dead
        // broker costs one timeout per window rather than one per command.
        'backoff_seconds' => 30,
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
