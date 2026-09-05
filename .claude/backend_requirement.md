# Smart Access Control — Backend & Dashboard Requirements

> **Purpose of this document.** It is the complete build brief for the backend
> service and web dashboard that support the Smart Access Control panel. The
> firmware already exists (separate repository, ESP32-S3); this document
> describes what it needs, the constraints it imposes, and everything the
> backend must add on top. Build it in its own repository.
>
> **Read section 2 before designing anything.** The device's hardware
> constraints are not negotiable and several of them (numeric-only codes, no
> real-time clock, offline tolerance) directly shape the data model and API.

---

## 1. What the system is

A wall-mounted access-control panel that unlocks a door. A person can be
admitted three ways:

| Method | How it works on the device |
|---|---|
| **Fingerprint** | Finger is matched against templates stored in the sensor's own flash. |
| **OTP** | User enters their phone number; a 6-digit code arrives by SMS (and e-mail); they type it in. |
| **Backup code** | User types one of 5 pre-issued 6-digit codes. |

A fourth flow, **Admin Settings**, is not an access method: it uses a
fingerprint to prove the holder is an administrator before exposing the
device's own configuration portal.

The backend's job is to be the system of record for **who may enter, which
devices they may enter through, and everything that was attempted** — plus a
dashboard for managing that.

### 1.1 What the backend must own

1. **Access logging.** Every attempt through every method, successful or not,
   with enough context to answer "who tried to get in, where, when, and why
   were they refused."
2. **Device authentication.** Every device→backend call carries an API key,
   secret and device id. The backend must authenticate the device and attribute
   all data to it.
3. **Member management per device.** Which people are enrolled on which
   devices, and the ability to revoke.
4. **Backup code generation** and lifecycle.
5. **OTP issuance and verification**, including delivery fan-out to e-mail.
6. **Multi-tenancy**, user management and role-based permissions.
7. **Realtime push** to the dashboard for status and live event feeds.

---

## 2. The device — constraints you must design around

These come from the shipped firmware. Violating any of them produces a system
that cannot work on real hardware.

### 2.1 Hard constraints

| Constraint | Consequence for the backend |
|---|---|
| **The keypad is a 4×3 matrix: digits `0-9`, `*`, `#` only.** There is no way to type a letter. | **Every code the backend generates must be numeric.** OTPs and backup codes are 6 **digits**. An alphanumeric code is physically unenterable. |
| **The device has no RTC.** Its clock starts at 1970 until NTP is configured, and NTP is not yet implemented. | **The server must assign all authoritative timestamps.** Never trust a device-supplied wall-clock time. The device may send a monotonic uptime for ordering within a batch. |
| **Fingerprint templates live in the sensor's flash, not the backend.** Slot ids are device-local integers `0 … capacity-1` (capacity is typically 200) and survive reboots *and* firmware reflashes. | An enrollment is the tuple **(device, slot id) → member**. The backend cannot move a template between devices. Deleting a member must also instruct the device to erase that slot. |
| **The device must keep working while offline.** Backup codes are cached in RAM specifically so the door still opens when the network is down. | Access events must be queueable and submitted late; the API must accept batched, back-dated events with an idempotency key. |
| **Phone numbers are capped at 14 characters** in device storage, and the entry screen requires 10+ digits. SMS delivery generally needs international format (`+94…`). | Normalise to E.164 server-side. Reject or normalise anything that will not fit. |
| **The SIM800L may fail to send.** No SIM, no signal, no antenna. | E-mail delivery must be an independent channel, not a fallback triggered by SMS failure. The device reports SMS status only locally. |

### 2.2 Device configuration (stored on-device, set via its own web portal)

The device holds this in NVS. The backend does **not** currently push it, but
see §9.3 — remote configuration is a recommended addition.

`device_id`, `device_name`, `scanner_security` (1-5), `api_base_url`,
`api_key`, `api_secret`, `lock_open_ms`, `wifi_ssid`, `wifi_pass`, `ap_ssid`,
`ap_pass`, `code_ttl_ms`.

- `device_id` is generated on first boot as `SMA_<4 digits>` and is
  `[A-Za-z0-9_-]` only. It is **device-asserted**, so the backend must treat it
  as a claim to be verified against the API credentials, not as trusted input.
- `api_base_url` includes the version prefix: `https://example.com/api/v1`.
  Endpoint paths below are appended to it directly.

### 2.3 Current firmware behaviour worth knowing

- OTP TTL and door-open time are device settings (defaults 60 s and 10 s).
- Backup codes are fetched once when the device comes online, and the **whole
  set of 5 is replaced** after any single one is used.
- The device retries the backup-code fetch when the Backup screen is opened if
  the start-up fetch failed; two failures send the user back to the menu.
- Health is polled every 30 s while up, 10 s while down.
- The Admin Settings flow is refused outright if the device cannot reach the
  backend — it will not fall back to letting anyone in.

---

## 3. Technology stack

| Layer | Choice |
|---|---|
| Backend | **Laravel 13** (PHP 8.3+) |
| Database | **MySQL 8** |
| Frontend | **React** (TypeScript, Vite) |
| Realtime | **Laravel Reverb** + Laravel Echo |
| Device & SPA auth | **Laravel Sanctum** |
| Permissions | **spatie/laravel-permission** |
| Queues | Redis-backed, for SMS/e-mail fan-out and event ingestion |
| API docs | OpenAPI 3.1, generated and committed |

> Verify framework-specific APIs against the current Laravel 13 documentation
> before relying on them; do not assume behaviour carried over from earlier
> major versions.

---

## 4. Multi-tenancy

**Single database, `tenant_id` foreign key on every tenant-owned table**, with a
global Eloquent scope applied automatically. This is the right trade-off at this
scale — separate databases per tenant add operational cost the system does not
need.

Requirements:

- Every tenant-owned model carries `tenant_id` and is scoped by a global scope
  bound to the authenticated context (device credential or admin session).
- **A device belongs to exactly one tenant.** Device authentication resolves the
  tenant; no device request may ever read or write across tenants.
- A **SuperAdmin** role may operate cross-tenant, but only through an explicit
  tenant-switch that is written to the audit log.
- Add a test that proves cross-tenant reads fail — this is the single most
  important security test in the system.

---

## 5. Data model

Tables below are the minimum. Add `id`, `created_at`, `updated_at` throughout;
use UUIDs for anything exposed in a URL.

### 5.1 Tenancy & identity

**`tenants`** — `name`, `slug`, `status`, `settings` (JSON).

**`users`** — dashboard operators. `tenant_id` (nullable for SuperAdmin),
`name`, `email`, `password`, `status`, `last_login_at`. Uses
spatie/laravel-permission for `roles` / `permissions`.

**`members`** — **the people who walk through doors.** Deliberately separate
from `users`: a member may have no login at all.
`tenant_id`, `full_name`, `phone` (E.164, indexed), `email` (nullable),
`status` (`active` | `suspended`), `is_admin` (grants the device's Admin
Settings flow), `notes`.

> **Naming:** the firmware currently calls this id `user_id` in its enrollment
> calls. Rename it to `member_id` on both sides — see §10.

### 5.2 Devices

**`devices`** — `tenant_id`, `device_id` (the device-asserted string, unique per
tenant), `name`, `location`, `status` (`provisioned` | `active` |
`suspended` | `retired`), `firmware_version`, `last_seen_at`,
`last_health_at`, `template_capacity`, `enrolled_count`, `metadata` (JSON).

**`device_credentials`** — `device_id` (FK), `api_key` (unique, indexed),
`api_secret_hash` (**hashed, never stored in plaintext**), `last_used_at`,
`expires_at`, `revoked_at`. Supports rotation: allow two live credentials per
device during a rollover window.

**`device_commands`** — the outbound command queue (§9.3).
`device_id`, `type`, `payload` (JSON), `status`
(`pending`|`sent`|`acked`|`failed`|`expired`), `issued_by`, `sent_at`,
`acked_at`, `expires_at`, `result` (JSON).

### 5.3 Enrollments

**`enrollments`** — `tenant_id`, `device_id`, `member_id`,
`fingerprint_slot` (integer, device-local), `status`
(`pending` | `active` | `revoked` | `orphaned`), `enrolled_at`, `revoked_at`.

- **Unique constraint on `(device_id, fingerprint_slot)` where status is
  active.** Two members must never claim the same slot.
- `orphaned` means the backend believes a slot is in use but the device
  disagrees — surfaced for reconciliation (§9.4).

### 5.4 Access logging

**`access_events`** — the core audit trail. Append-only; never updated.

| Column | Notes |
|---|---|
| `tenant_id`, `device_id` | always present |
| `member_id` | nullable — an unknown finger has no member |
| `method` | `fingerprint` \| `otp` \| `backup_code` \| `admin_auth` \| `remote` |
| `result` | `granted` \| `denied` |
| `reason` | machine-readable: `matched`, `no_match`, `not_enrolled`, `member_suspended`, `otp_expired`, `otp_mismatch`, `code_invalid`, `code_expired`, `not_admin`, `backend_unreachable`, `rate_limited` |
| `occurred_at` | **server-assigned** on receipt |
| `device_reported_at` | nullable; device uptime-derived, for ordering only |
| `fingerprint_slot` | nullable |
| `confidence` | nullable; AS608 match confidence |
| `otp_request_id`, `backup_code_id` | nullable FKs |
| `idempotency_key` | unique per device; deduplicates offline replays |
| `metadata` | JSON |

Index on `(tenant_id, occurred_at)`, `(device_id, occurred_at)`,
`(member_id, occurred_at)`. Plan for partitioning or archival — a busy door
generates a lot of rows.

### 5.5 OTP

**`otp_requests`** — `tenant_id`, `device_id`, `member_id` (nullable),
`phone`, `code_hash` (**hashed**), `status`
(`issued`|`delivered`|`verified`|`expired`|`failed`), `attempts`,
`max_attempts`, `expires_at`, `verified_at`, `delivery` (JSON: per-channel
status for SMS and e-mail).

### 5.6 Backup codes

**`backup_code_sets`** — `tenant_id`, `device_id`, `status`
(`active`|`superseded`), `issued_at`, `superseded_at`, `issued_by`.

**`backup_codes`** — `set_id`, `code_hash` (**hashed**), `last4` (for display),
`used_at`, `used_event_id`.

The device holds a set of 5. Using one supersedes the whole set and a new set
of 5 is issued — model that explicitly rather than mutating rows.

### 5.7 Audit

**`audit_logs`** — every dashboard mutation: `tenant_id`, `user_id`, `action`,
`auditable_type`, `auditable_id`, `before` (JSON), `after` (JSON), `ip`,
`user_agent`. Distinct from `access_events`; do not merge them.

---

## 6. Authentication & authorisation

### 6.1 Device authentication

Every device request carries three headers (already implemented in firmware):

```
X-Device-Id:   SMA_4821
X-API-Key:     <opaque key>
X-API-Secret:  <opaque secret>
```

Middleware must:

1. Look up the credential by `api_key`.
2. Verify `api_secret` against `api_secret_hash` with a **constant-time**
   comparison (`hash_equals`).
3. Verify the credential's device's `device_id` **matches the `X-Device-Id`
   header** — reject on mismatch. The header alone is never trusted.
4. Reject revoked/expired credentials and non-`active` devices.
5. Bind the tenant for the rest of the request and touch `last_used_at`.

Return `401` for bad credentials, `403` for a suspended device. Never leak
which of the three values was wrong.

> **Recommended hardening:** the current scheme sends a bearer secret on every
> request. It is acceptable over TLS, but consider adding an **HMAC request
> signature** (`X-Signature` over method + path + body + timestamp + nonce)
> with a ±5 minute window to defeat replay. Note this requires a firmware
> change and the device has no reliable clock — the timestamp would have to
> come from a server-provided epoch offset. Treat as a phase-2 item and
> document the decision either way.

### 6.2 Dashboard authentication

Sanctum SPA session auth for the React app. Enforce 2FA for any role that can
manage devices or members.

### 6.3 Roles & permissions

| Role | Scope | Capability |
|---|---|---|
| `super_admin` | cross-tenant | everything, plus tenant management |
| `tenant_admin` | one tenant | full control within the tenant |
| `device_manager` | one tenant | devices, enrollments, backup codes; no user/role management |
| `operator` | one tenant | view live status, trigger remote unlock, view events |
| `auditor` | one tenant | read-only, including logs; no mutations |

Permissions must be granular and checked with Policies, not role-name string
comparisons. Minimum set:

```
tenant.manage        device.view       device.create      device.update
device.delete        device.unlock     device.command
member.view          member.create     member.update      member.delete
enrollment.view      enrollment.revoke
backupcode.view      backupcode.rotate
event.view           event.export
user.view            user.invite       user.update        user.delete
role.manage          audit.view
```

---

## 7. Device-facing API (`/api/v1`)

All paths are appended to the device's configured `api_base_url`. Naming
convention already established with the firmware: **kebab-case plural nouns,
HTTP method carries the verb, sub-resources nested.**

Responses are JSON. Errors use a consistent envelope:

```json
{ "error": { "code": "otp_expired", "message": "Human readable" } }
```

### 7.1 Already integrated in firmware

#### `GET /health`
Liveness + reachability probe. Polled every 30 s / 10 s.

**200** — the device requires **both** a 200 and this exact status value:
```json
{ "status": "ok", "server_time": "2026-09-06T10:15:00Z" }
```
> Include `server_time`; it is the device's only path to a real clock (§9.1).

#### `POST /authentications`
Admin gate. Asks whether a matched fingerprint belongs to an administrator.

```json
{ "device_id": "SMA_4821", "enrollment_id": 12 }
```
`enrollment_id` is the **device-local fingerprint slot**, not a member id.

**200**
```json
{
  "is_admin": true,
  "member": { "member_id": "uuid", "full_name": "Jane Doe" }
}
```
The firmware grants **only** on `"is_admin": true`. Anything else — a non-200,
a missing field, a transport failure — denies. Log an `admin_auth` access
event for every call, granted or not.

#### `POST /enrollments`
Creates a member enrollment from a newly stored fingerprint slot.

```json
{ "device_id": "SMA_4821", "fingerprint_slot": 7 }
```
**201**
```json
{ "member_id": "uuid", "enrollment_id": "uuid", "status": "pending" }
```
Status is `pending` until a phone number is attached.

#### `PATCH /enrollments/{memberId}`
Attaches the phone number captured on the next screen.

```json
{ "device_id": "SMA_4821", "phone": "+94771234567", "fingerprint_slot": 7 }
```
**200** → `{ "member_id": "uuid", "status": "active" }`

> The device may reach this step with **no** fingerprint (the user skipped it,
> or registration failed and the template was rolled back). Accept
> `fingerprint_slot: null` and create a phone-only member.

#### `POST /otp-requests`
Issues an OTP for a phone number. See §7.3 — **this contract must change.**

Current firmware expectation:
```json
{ "device_id": "SMA_4821", "phone": "+94771234567" }
```
**200**
```json
{ "valid": true, "otp": "482915", "expires_in": 60 }
```
`valid: false` means the number is not known to this device's tenant; the
device shows "Phone number not found" and lets the user retry.

#### `POST /otp-deliveries`
Fire-and-forget request to fan the code out to e-mail (the device sends the SMS
itself). The device ignores the response.

```json
{ "device_id": "SMA_4821", "phone": "+94771234567", "otp": "482915" }
```
**202** → `{ "queued": ["email"] }`

#### `GET /backup-codes`
Returns the active set. Called when the device comes online and after any code
is used.

**200**
```json
{
  "set_id": "uuid",
  "codes": ["482915", "730264", "915738", "204681", "657390"],
  "issued_at": "2026-09-06T10:00:00Z"
}
```
**Exactly 5 codes, exactly 6 numeric digits each.** The device validates both
and rejects the whole set otherwise, disabling backup access until it can
refetch.

#### `POST /backup-codes/attempts`
Logs one entry attempt, accepted or rejected, with the code tried.

```json
{ "device_id": "SMA_4821", "code": "482915", "accepted": true }
```
**200** → `{ "logged": true }`

Rate-limit per device. Repeated failures are the signature of a brute-force
attempt against a static secret — raise an alert and emit a realtime event.

### 7.2 New endpoints the backend must add

The firmware does not call these yet; §10 lists the corresponding firmware
work.

#### `POST /access-events`
**The most important addition.** Currently a fingerprint match at the main
screen opens the door and logs *only to the device's serial console* — the
backend never learns about it. Every access decision must be reported.

Single event, or a batch for queued offline events:

```json
{
  "device_id": "SMA_4821",
  "events": [
    {
      "idempotency_key": "SMA_4821-000000123",
      "method": "fingerprint",
      "result": "granted",
      "reason": "matched",
      "fingerprint_slot": 7,
      "confidence": 142,
      "uptime_ms": 1234567
    }
  ]
}
```

**202**
```json
{ "accepted": 1, "duplicates": 0 }
```

- `idempotency_key` is device-generated and unique per device. Replays are
  silently deduplicated — an offline device will resend.
- `occurred_at` is assigned by the server on receipt. `uptime_ms` orders events
  within a batch only.
- Accept up to 100 events per request.

#### `POST /otp-verifications`
Server-side OTP verification — see §7.3.

```json
{ "device_id": "SMA_4821", "otp_request_id": "uuid", "code": "482915" }
```
**200** → `{ "verified": true, "member_id": "uuid" }`
**200** → `{ "verified": false, "reason": "otp_mismatch", "attempts_left": 2 }`

#### `POST /backup-code-verifications`
Optional server-side backup-code verification, for deployments that accept
losing offline operation in exchange for not caching plaintext codes on the
device. See §7.3.

#### `GET /device-commands`
Polled by the device to pick up queued commands (§9.3).

**200**
```json
{ "commands": [
    { "id": "uuid", "type": "delete_enrollment", "payload": { "fingerprint_slot": 7 } }
] }
```

#### `POST /device-commands/{id}/acknowledgements`
```json
{ "status": "acked", "result": { "deleted": true } }
```

#### `POST /device-registrations` *(recommended)*
First-contact provisioning so a device can report its identity, firmware
version and template capacity, and the dashboard can adopt it rather than
requiring credentials to be typed into the device blind.

### 7.3 Security changes to the OTP and backup-code flows

**These are the two most significant design problems in the current system and
the backend must lead the fix.**

**Problem 1 — the OTP travels to the device in plaintext.** `POST /otp-requests`
currently returns the code itself, and the device compares what the user typed
against it locally. Anyone holding the device's API credentials can request a
code for any phone number and read it directly from the response. The
verification decision also cannot be rate-limited centrally.

**Fix.** `POST /otp-requests` returns an `otp_request_id` and **never the code**:

```json
{ "valid": true, "otp_request_id": "uuid", "expires_in": 60 }
```

The backend delivers the code over SMS and e-mail itself, and the device
submits the typed code to `POST /otp-verifications`. This requires the device
to have network access at verification time — acceptable, because the OTP flow
already depends on the backend to issue the code.

> **Note on SMS:** today the *device* sends the SMS through its own SIM800L.
> Moving issuance server-side means either (a) the backend sends the SMS
> through a gateway and the device's modem becomes a fallback, or (b) the
> backend returns the code only in a delivery job, not to the device. **Option
> (a) is recommended** — a server-side SMS gateway is more reliable than a
> SIM800L on a bench supply, and it removes the plaintext-to-device problem
> entirely. Keep the on-device modem as an offline fallback path if the
> deployment needs it, and document that this fallback re-introduces the
> weakness.

**Problem 2 — backup codes are cached on the device in plaintext.** This is a
deliberate trade: it is what lets the door open while the network is down.

**Fix (conditional).** Store only `code_hash` server-side. Then choose per
deployment:

- **Offline-capable (default):** the device continues to cache the 5 plaintext
  codes. Mitigate with short set lifetimes, immediate rotation on use, and
  alerting on repeated failures.
- **Online-only (high security):** the device caches nothing and calls
  `POST /backup-code-verifications`. Backup access stops working when offline —
  which for a *backup* method may be exactly the wrong trade-off. Make it a
  per-tenant setting and default it off.

Document whichever is chosen in the device's own settings.

---

## 8. Dashboard API & frontend

### 8.1 Admin API

Namespace under `/api/admin/v1`, Sanctum-authenticated, tenant-scoped,
policy-guarded. Standard REST resources for: tenants, users, roles, members,
devices, device credentials, enrollments, access events, OTP requests, backup
code sets, device commands, audit logs.

Beyond CRUD:

- `GET /access-events` with filtering by device, member, method, result, date
  range; cursor pagination; CSV/XLSX export.
- `POST /devices/{id}/unlock` — remote unlock, queued as a device command,
  logged as an access event with `method: remote` and the acting user.
- `POST /devices/{id}/backup-codes/rotations` — force a new set.
- `DELETE /enrollments/{id}` — revokes and queues a `delete_enrollment`
  command so the template is erased from the sensor.
- `GET /dashboard/summary` — counts and trends for the landing page.

### 8.2 React frontend

TypeScript, Vite, React Router, TanStack Query for server state, Laravel Echo
for realtime. Component library is the implementer's choice; be consistent.

Screens:

1. **Login** (+ 2FA), tenant switcher for SuperAdmin.
2. **Dashboard** — device online/offline tiles, today's access counts,
   denied-attempt rate, live event ticker.
3. **Devices** — list with live status; detail view showing health history,
   enrolled count vs capacity, current backup code set (last 4 only), pending
   commands, and a remote-unlock button.
4. **Members** — CRUD, enrollment list per member, admin toggle, suspend.
5. **Access events** — filterable, exportable table with a live-append toggle.
6. **Backup codes** — view the active set's metadata, rotate, reveal a newly
   generated set **exactly once** at generation time.
7. **Users & roles** — invitations, role assignment, permission matrix.
8. **Audit log** — read-only.

**Accessibility and clarity matter more than polish here:** an operator looking
at this screen during an incident needs to see device state and the denied-event
stream immediately. Colour alone must never carry status — pair it with text
and an icon.

---

## 9. Recommended additions

These are not in your brief but follow directly from how the device behaves.
Each is justified — implement or reject deliberately.

### 9.1 Serve the device a clock
The device has no RTC; its status bar currently shows 1970. Returning
`server_time` in `GET /health` costs nothing and lets the firmware set its
clock, which makes on-device logs and the OTP countdown meaningful. **Do this.**

### 9.2 Device heartbeat and offline detection
`GET /health` is already polled every 30 s. Record `last_health_at` and mark a
device offline after ~3 missed intervals, emitting a realtime event. This is
how the dashboard knows a door has gone dark.

### 9.3 Device command queue
Several essential operations are backend→device: erase a revoked fingerprint,
force a backup-code refresh, remote unlock, push changed settings, reboot.
The device cannot receive pushes, so **piggyback a `commands` array on the
health response** (or let it poll `GET /device-commands`), with explicit
acknowledgement. Without this, revoking a member does not actually remove
their finger from the door.

### 9.4 Enrollment reconciliation
The device is the source of truth for which slots are occupied; the backend is
the source of truth for who owns them. They will drift — a failed rollback, a
sensor swap, a manual erase. Have the device report its slot inventory
periodically and flag mismatches as `orphaned` for an operator to resolve.

### 9.5 Rate limiting and alerting
Per-device limits on `otp-requests`, `backup-codes/attempts` and
`otp-verifications`. Repeated failures on a static code are the clearest
brute-force signal the system has — alert on them and surface prominently.

### 9.6 Anti-passback / duplicate suppression
The AS608 can report the same finger repeatedly. The firmware waits for finger
release, but a member holding the pad may still produce bursts. Suppress
duplicate grants for the same member/device within a short window to keep the
log readable.

### 9.7 Scheduled access
Time-of-day and day-of-week windows per member or per member-group is the
single most-requested feature in this class of system. Design the schema with
it in mind even if you defer building it.

### 9.8 Webhooks
Let tenants subscribe to `access.denied`, `device.offline` and
`backup_codes.rotated` for their own alerting.

---

## 10. Required firmware changes

Track these on the firmware side; the backend depends on them.

| # | Change | Why |
|---|---|---|
| 1 | Call `POST /access-events` for **every** access decision — fingerprint grant/deny at the main screen, OTP result, backup-code result, admin-auth result, and door-open. | The backend currently learns nothing about actual door usage. This is the core of the brief. |
| 2 | Queue access events in NVS while offline and flush on reconnect, with a device-generated `idempotency_key`. | The device must keep working offline; events must not be lost. |
| 3 | Move OTP verification server-side: stop receiving the plaintext code, submit the typed code to `POST /otp-verifications`. | §7.3, problem 1. |
| 4 | Rename `user_id` → `member_id` in the enrollment calls. | Consistency with the backend model; `user` means a dashboard operator. |
| 5 | Poll and acknowledge device commands. | Revocation and remote unlock are impossible without it. |
| 6 | Set the RTC from `server_time` in the health response. | Fixes 1970 timestamps. |
| 7 | Report firmware version, template capacity and slot inventory on health or registration. | Enables reconciliation and fleet visibility. |
| 8 | Make the enroll/phone/backup placeholder calls real HTTP. | They currently only log to serial and branch on a compile-time test flag. |
| 9 | Move blocking HTTP off the LVGL thread. | `netmgr_authenticate_admin()` and the backup-code refetch stall the UI for seconds. |

---

## 11. Non-functional requirements

- **Security:** TLS everywhere; secrets hashed at rest; constant-time
  comparison for credentials; no secret ever written to a log; per-tenant data
  isolation proven by test.
- **Auditability:** access events are append-only; admin mutations are audited
  with before/after state.
- **Performance:** the access-event write path must stay fast under burst —
  queue ingestion rather than writing synchronously if needed. Dashboard
  queries must not scan unbounded event history.
- **Reliability:** device-facing endpoints degrade safely. If the backend
  cannot answer, the device denies admin access and falls back to cached
  backup codes; never design an endpoint whose failure silently grants entry.
- **Testing:** feature tests for every device endpoint including auth failure
  modes; a cross-tenant isolation test; a replay/idempotency test.
- **Deliverables:** committed OpenAPI 3.1 spec, seeders for a demo tenant with
  devices and members, `README` covering local setup with Reverb and queues.

---

## 12. Open questions for the product owner

1. **Are backup codes device-scoped or member-scoped?** The firmware treats
   them as device-scoped — 5 codes that open *this door*, tied to no one. That
   makes the access log unable to say *who* entered. If they should identify a
   person, issue per-member codes instead.
2. **Should a member enrolled on one device be admitted at others in the same
   tenant?** Fingerprint templates are device-local, so this requires
   re-enrolment per device unless the backend orchestrates it.
3. **Retention period for access events**, and whether exports must be
   retained beyond it.
4. **Does the OTP flow admit anyone with a known phone number, or only
   members with an active enrollment?** Currently the device only asks whether
   the number is "valid".
5. **SMS gateway** — server-side, device-side, or both with fallback? (§7.3.)
6. **Does a tenant need multiple sites/buildings** as a grouping layer above
   devices?
