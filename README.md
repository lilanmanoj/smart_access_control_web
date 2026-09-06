# Smart Access Control — backend & dashboard

The system of record for a wall-mounted ESP32-S3 access panel: who may enter,
which doors they may enter through, and everything that was attempted.

Laravel 13 · MySQL 8 · React 19 (TypeScript, Vite) · Laravel Reverb · Sanctum ·
spatie/laravel-permission · Redis queues — all of it inside Docker. **The host
needs Docker and nothing else** — no PHP, no MySQL, no Apache.

---

## Quick start

```sh
docker compose up -d --build
docker compose exec app php artisan db:seed
```

That is the whole setup. On first boot the entrypoint installs Composer
dependencies, writes a `.env` from `.env.example`, generates an `APP_KEY` and
runs the migrations.

Then open **http://localhost:8000**.

The seeder prints working device credentials for two demo panels and creates
these operator accounts (password `password` for all of them):

| Email | Role | What they can do |
|---|---|---|
| `admin@example.com` | `super_admin` | Everything, across every tenant |
| `tenant.admin@example.com` | `tenant_admin` | Everything within one tenant |
| `device.manager@example.com` | `device_manager` | Devices, members, enrolments, backup codes |
| `operator@example.com` | `operator` | Watch doors, unlock remotely, read the log |
| `auditor@example.com` | `auditor` | Read-only, including the audit log |

> **The first three will be asked to set up two-factor authentication before
> they can do anything.** That is deliberate — any role that can open a door or
> change who may enter carries a second factor. To look around without that,
> sign in as `operator@example.com`. Scan the QR with any authenticator app.

Other local services:

| | |
|---|---|
| Dashboard | http://localhost:8000 |
| Mailpit (catches OTP e-mails) | http://localhost:8025 |
| Reverb websockets | ws://localhost:8081 |
| MySQL (for a GUI client) | `127.0.0.1:3307` |

### Front-end development

The published assets are built into the image, so the dashboard works out of
the box. For hot reload:

```sh
docker compose up -d vite     # http://localhost:5173, proxied automatically
```

### Tests

```sh
docker compose exec app php artisan test
```

The suite runs against **MySQL**, not SQLite. The schema leans on behaviour
SQLite does not share — the enrolments unique index relies on MySQL treating
NULLs as distinct, and the circular `access_events` ⇄ `backup_codes` foreign key
is added to an existing table, which SQLite cannot do. A suite passing on a
different engine than production proves the wrong thing.

---

## Deploying to shared hosting

The target arrangement: **the host provides Apache and MySQL; everything else
runs in containers.** Apache is the only public entrance and reverse-proxies
into the stack, which binds to loopback.

```
Internet ──▶ Apache (:443, host)
               ├── /              ──▶ 127.0.0.1:8000   app  (nginx + PHP-FPM)
               └── /app, /apps    ──▶ 127.0.0.1:8080   reverb (websockets)

                  containers: app · queue · scheduler · reverb · redis
                  host:       MySQL
```

```sh
# 1. Configure
cp .env.production.example .env
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
#    …paste the key into APP_KEY, fill in the database and mail settings

# 2. Start
docker compose -f docker-compose.prod.yml up -d --build

# 3. Point Apache at it
sudo cp docker/apache/smart-access-control.conf /etc/apache2/sites-available/
sudo a2enmod proxy proxy_http proxy_wstunnel headers rewrite ssl
sudo a2ensite smart-access-control && sudo systemctl reload apache2
```

Things worth knowing about this path:

- **The image contains no `.env`.** Production configuration comes entirely
  from the environment, and the container **refuses to start without
  `APP_KEY`** rather than inventing one — each container generating its own
  would break sessions and encrypted columns across the stack.
- **`DB_HOST=host.docker.internal`** reaches a MySQL running on the host; the
  compose file maps it to the host gateway. Use the real hostname if the
  database lives elsewhere.
- **Redis runs inside the stack** because shared hosts rarely provide one, and
  both the queue and Reverb need it.
- **Only the app and reverb ports are published, and only to 127.0.0.1.**
  Nothing in the stack is directly reachable from the internet.
- **PHP is inside the container**, so the host's PHP version — or absence of
  one — does not matter.

### One image, four roles

The same image runs the web server, the queue worker, the scheduler and the
websocket server. `CONTAINER_ROLE` picks which (see `docker/entrypoint.sh`),
so there is one thing to build, tag and roll back.

| Role | Process | Why it exists |
|---|---|---|
| `app` | nginx + PHP-FPM | The only port a proxy needs |
| `queue` | `queue:work` | OTP delivery, webhooks, broadcasts |
| `scheduler` | `schedule:work` | Offline detection, code rotation, retention |
| `reverb` | `reverb:start` | The dashboard's live feed |

---

## How the system is shaped

The panel's hardware drives most of the non-obvious decisions. These are not
style choices, and changing them breaks real doors.

| Constraint | What follows from it |
|---|---|
| The keypad is a 4×3 matrix — digits, `*`, `#`. **No letters.** | Every code the backend issues is six *digits*. An alphanumeric code is physically unenterable. |
| **No real-time clock.** The device reads 1970 until NTP lands. | The server assigns every authoritative timestamp. `GET /health` returns `server_time`, which is the panel's only route to a real clock. `uptime_ms` orders events *within a batch* and nothing more. |
| **Templates live in the sensor's flash**, in device-local slots that survive reflashes. | An enrolment is `(device, slot) → member`. Revoking has to reach back to the device to erase the slot — which is what makes the command queue load-bearing rather than a nicety. |
| **The door must open while the network is down.** | Access events queue on the panel and flush later, so ingestion accepts batched, replayed submissions keyed on an idempotency key. Backup codes are cached in plaintext on the device, deliberately. |
| **Phone numbers cap at 14 characters** in device storage. | Normalised to E.164 server-side; anything that will not fit is refused at the edge. |
| **The SIM800L fails in ordinary ways and reports only locally.** | E-mail is an independent channel, never a fallback triggered by an SMS failure. Server-side SMS is the recommended posture. |

### The two security changes to the original contract

Both are described in §7.3 of the brief and both are implemented here.

**1. The OTP no longer travels to the device.** `POST /otp-requests` returns an
`otp_request_id`, never the code. The backend delivers it over SMS and e-mail
and the device submits what the user typed to `POST /otp-verifications`. Under
the old shape, anyone holding a device credential could request a code for any
phone number and read it out of the response, and no verification attempt could
be counted centrally. A compatibility switch
(`OTP_RETURN_CODE_TO_DEVICE`) restores the old behaviour for firmware that has
not been updated; it is off by default and documented as a weakness wherever it
appears.

**2. Backup codes are stored hashed.** Because only hashes exist server-side, a
set cannot be handed out twice — so every `GET /backup-codes` mints a fresh set
and retires the previous one, which also means a panel reboot rotates the codes.
The device still caches the five plaintext codes, because that is the entire
point of a method that works offline. `BACKUP_CODES_ONLINE_ONLY` (per tenant)
trades that away: the panel caches nothing and verifies through the API, losing
offline access — which for a *backup* method may be exactly the wrong trade, so
it is off by default.

### Multi-tenancy

Single database, `tenant_id` on every tenant-owned table, enforced by a global
Eloquent scope bound at the edge of each request — by the device credential on
the device API, by the operator's own tenant on the dashboard API.

Two details that make it hold:

- **Tenant binding runs before route-model binding.** Middleware priority is set
  explicitly in `bootstrap/app.php` for this reason: if `SubstituteBindings`
  runs first, `/devices/{uuid}` resolves a row from *any* tenant and only the
  policy stops it. `tests/Feature/TenantIsolationTest.php` covers this.
- **Crossing the boundary is explicit and audited.** `TenantContext::crossTenant()`
  is the only way, and the SuperAdmin tenant switch that uses it writes to the
  audit log.

### Roles and permissions

Permissions are granular and checked **by name** in policies. A role is only a
bundle of them; no code branches on a role string, so defining a new role never
requires touching an authorisation check.

```
tenant.manage    device.view/create/update/delete/unlock/command
member.view/create/update/delete    enrollment.view/revoke
backupcode.view/rotate              event.view/export
user.view/invite/update/delete      role.manage    audit.view
schedule.view/manage                webhook.manage
```

`super_admin` · `tenant_admin` · `device_manager` · `operator` · `auditor`.
Two-factor is enforced for the first three.

---

## Layout

```
app/
  Enums/            AccessMethod, DenialReason, DeviceCommandType, …
  Events/           Broadcast + webhook events (one class serves both)
  Http/
    Middleware/     AuthenticateDevice, BindTenantForUser, EnsureTwoFactorSatisfied
    Controllers/
      Api/V1/       The device API — §7 of the brief
      Admin/        The dashboard API
  Models/
    Concerns/       BelongsToTenant, HasUuid
    Scopes/         TenantScope — the isolation mechanism
  Policies/         One per resource; permission checks by name
  Services/         AccessEventRecorder, OtpService, BackupCodeService, …
  Support/          TenantContext, DeviceContext, PhoneNumber, NumericCode

resources/js/       The React dashboard
  components/       Layout, status badges, TrendChart, LiveEvents
  pages/            One per screen
  lib/              api, auth, echo, formatting

openapi/            Committed OpenAPI 3.1 specs (device + dashboard)
docker/             Dockerfile, nginx, php, supervisor, entrypoint, Apache vhost
tests/              Feature tests per device endpoint, isolation, replay
```

### Where the interesting logic lives

| Question | File |
|---|---|
| How is a replayed offline batch deduplicated? | `app/Services/AccessEventRecorder.php` |
| How does tenant isolation actually work? | `app/Models/Scopes/TenantScope.php`, `app/Support/TenantContext.php` |
| How is a device authenticated? | `app/Http/Middleware/AuthenticateDevice.php` |
| Why does the trend chart not use red and green? | `resources/js/components/TrendChart.tsx` |
| What settings can a tenant override? | `config/access.php` |

---

## Dashboard

React 19, TypeScript, React Router, TanStack Query, Laravel Echo. Screens:
dashboard, devices (+ detail), members (+ detail), access events, backup codes,
schedules, users & roles, webhooks, audit log, security.

Two rules run through the interface, because it gets read during incidents:

1. **Colour never carries status alone.** Every badge pairs a colour with a
   glyph and a word.
2. **Denials are the signal.** They get the strongest contrast on the page;
   grants recede.

The trend chart follows from the first rule rather than decorating it: green
against red measures ΔE 5.1 under simulated deuteranopia — for the most common
form of colour blindness those two bars are the same bar. So the chart uses
emphasis instead, with denials in the one saturated colour and grants in grey,
which measures 17.1.

---

## Scheduled work

Run by the `scheduler` container.

| Command | When | What |
|---|---|---|
| `devices:detect-offline` | every minute | Marks a door dark after ~3 missed health polls |
| `otp:expire` | every 5 minutes | Closes out unused one-time passwords |
| `backup-codes:rotate-stale` | nightly | Ages out code sets sitting in a panel's RAM |
| `access-events:prune` | nightly | Applies `EVENT_RETENTION_DAYS` (unset = never) |

---

## Firmware changes this backend depends on

Tracked in §10 of the brief. The backend is ready for all of them; the ones
that matter most:

1. **Call `POST /access-events` for every access decision.** Without it the
   backend never learns the door was used.
2. **Queue events in NVS while offline** and flush with a device-generated
   idempotency key.
3. **Move OTP verification server-side** — stop receiving the plaintext code.
4. **Rename `user_id` → `member_id`** in the enrolment calls.
5. **Poll and acknowledge device commands** — revocation and remote unlock are
   impossible without it.
6. **Set the clock from `server_time`** in the health response.

Endpoints 1, 3 and 5 are live and tested; the panel can adopt them one at a
time. The legacy `POST /otp-deliveries` is kept and accepted so existing
firmware does not see a 404.

**Not implemented:** the phase-2 HMAC request signature from §6.1. Signing needs
a timestamp and the panel has no reliable clock, so it would have to sign
against a server-provided epoch offset — a firmware change. The
`DEVICE_REQUIRE_SIGNATURE` flag exists so the decision is recorded, and turning
it on makes device authentication **refuse every request** rather than silently
ignore it: a deployment that expects signing should fail loudly, not believe it
has protection it does not have.

---

## Decisions taken on the open questions

The brief's §12 questions were answered as follows, all reversible:

1. **Backup codes are device-scoped**, matching the firmware — five codes that
   open *this door*, tied to no one. The consequence is stated plainly on the
   backup-codes screen: an entry made with one names no member in the log.
2. **Enrolment is per device.** Templates are device-local, so a member is
   enrolled at each panel they use. The schema does not prevent orchestrating
   this later.
3. **Retention defaults to 365 days** via `EVENT_RETENTION_DAYS`; unset it to
   keep events forever. Exports are not retained server-side.
4. **The OTP flow admits any active member of the tenant.** Set
   `OTP_REQUIRE_ENROLLMENT` (or the per-tenant `otp.require_enrollment`) to
   narrow it to members already enrolled on the calling device.
5. **SMS is server-side**, through a provider-agnostic HTTP gateway; the panel's
   modem stays available as an offline fallback, with the weakness that
   reintroduces documented in `config/access.php`.
6. **Sites/buildings are not modelled yet.** Devices carry a free-text
   `location`; a grouping layer can be added above devices without touching the
   access log.
