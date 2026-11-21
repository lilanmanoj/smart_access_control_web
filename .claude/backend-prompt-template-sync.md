# Backend task: fingerprint template sync (device API 1.2.0)

You are working in the web portal / backend repository. Implement the two new
device endpoints and the per-device slot assignments they rely on. The
contract is the attached `device-api-v1.yaml` **version 1.2.0**. Where this
prompt and the YAML disagree, the YAML wins. Read its `GET /fingerprint-templates`
and `POST /fingerprint-template-syncs` descriptions in full before you start.

## 1. Context

Each access panel (ESP32-S3 + AS608 fingerprint sensor) stores templates in
numbered sensor slots. Since 1.1.0, `POST /enrollments` uploads each new
template (`fingerprint_template`), and you already store it encrypted. The
panel firmware now **pulls templates back**:

- on every start-up, once WiFi is up and `/health` passes, and then every
  N hours (panel setting, default 3), it calls `GET /fingerprint-templates`;
- it makes its sensor **mirror** the response. It writes the templates it is
  missing, skips the ones it already has (it compares `sha256`), keeps entries
  that have `template: null`, and **deletes every occupied slot the response
  does not list**;
- then it posts what happened to `POST /fingerprint-template-syncs`.

The firmware side is finished and fixed. Build the backend to match.

## 2. Data model

### 2.1 Slot assignments (new table, e.g. `device_template_slots`)

| column | notes |
|---|---|
| `id` | uuid PK |
| `tenant_id` | same tenancy scoping as devices and members |
| `device_id` | FK → devices |
| `slot` | integer, `0 … capacity-1` on that device |
| `member_id` | FK → members, `ON DELETE CASCADE` |
| `fingerprint_template_id` | FK → stored templates, nullable (null = we hold no bytes) |
| `origin` | `enrolled_here` \| `pushed` |
| `status` | `active` (listed to the panel) \| `releasing` (no longer listed; the panel may still hold it) |
| `confirmed_at` | set when a sync report's `slots_present` shows the panel holds this slot |
| timestamps | |

- Unique: `(device_id, slot)` across rows with status `active` or `releasing`.
- **Backfill:** every existing enrolment with a non-null `fingerprint_slot`
  becomes an `active`, `enrolled_here` row for its device. Link its active
  template if one exists, otherwise null.

### 2.2 Sync reports (new table, e.g. `template_sync_reports`)

Store each report body as received (validated), plus `device_id`, `tenant_id`
and `received_at`. The dashboard shows each panel's last sync: status, time,
failures and drift.

## 3. Which templates a device gets (the "desired set")

Compute this for a device, for a given `capacity` and `template_format`:

1. **Eligible members.** Members of the device's tenant who may use this
   device. If the backend models door or access groups, respect them;
   otherwise every door in the tenant. Member status must be `active`, **or**
   `pending` when this is the device they enrolled on. That keeps a fresh
   enrolment working at its own door until it is approved, which is today's
   behaviour. Suspended or deleted members are never eligible.
2. **Their template.** The member's `active` template of the requested format
   (never `superseded` or `deleted`).
3. **Entries.**
   - An `enrolled_here` assignment on this device → its own slot, with its
     template, or `template: null` if no bytes are stored (pre-1.1 firmware, or
     a template that was rejected). These are the only entries that may be
     null.
   - Eligible members enrolled elsewhere who have a template → a `pushed`
     assignment on this device. Reuse the existing one; otherwise allocate the
     **lowest slot not used by any `active` or `releasing` row** on this
     device. Allocation must be transactional and stable: the same member keeps
     the same slot sync after sync.
   - Members with neither a template nor an `enrolled_here` slot here are not
     listed.
4. **Capacity.** At most `capacity` entries, and every `slot < capacity`. If
   more qualify, keep `enrolled_here` first, then `pushed` in a stable order
   (e.g. oldest enrolment first). Set `truncated: true` and show a warning on
   the dashboard. Members cut by capacity get no assignment. An existing
   assignment whose slot is `>= capacity` goes to `releasing`. Re-allocate a
   `pushed` one within range.
5. **Leaving the set.** An `active` row whose member or template stops
   qualifying becomes `releasing`. It is not listed, so the panel deletes it
   on its next sync. A replaced template (a new one supersedes the old) keeps
   the row `active` and points it at the new template.

**Never reuse a slot that is `releasing`** for another member until a report
from that device with `status` `completed` or `partial` has a `slots_present`
that does not contain the slot. Then delete the `releasing` row, which frees
the slot. Until then the panel may still hold the old member's finger in that
slot.

## 4. `GET /fingerprint-templates`

- **Required query params:** `device_id`, `capacity` (1–1024),
  `template_format` (`as608`). A missing or invalid one → `422` in the
  standard `Error` shape with `fields`. A `device_id` that differs from the
  authenticated `X-Device-Id` → `403`. A suspended device → `403`, as
  elsewhere.
- **Optional:** `offset` (default 0), `limit` (default 8, **max 10**, so
  clamp), `since_revision`, `enrolled_count`, `firmware_version`. Update the
  device's stored `template_capacity`, `enrolled_count` and `firmware_version`
  from them.
- **Revision:** deterministic over the whole desired set. For example, take the
  SHA-256 of a canonical serialisation of
  `format | capacity | sorted [(slot, member_id, template_sha256 or "none")]`
  and keep the first 16–32 hex chars. It must match
  `^[A-Za-z0-9._-]{1,64}$`. The same set gives the same revision, even
  across restarts. Any change gives a new one.
- **`since_revision == revision`:** reply `200` with `unchanged: true`, the
  current `total`, and `templates: []`.
- **Otherwise:** the page `[offset, offset+limit)` of the set, ascending by
  `slot`, with `total`, `offset`, `limit` and `truncated`. Each entry is
  `{slot, member_id, template}`, where `template` is the exact
  `FingerprintTemplate` object as uploaded (`format`, `encoding: base64`,
  `size`, `sha256`, `data`), decrypted for this response only, or `null`.
- **Consistency:** every page of one sync must carry the same `revision`. If
  the set changes between pages, the panel sees a new revision, aborts without
  deleting anything, and retries in about a minute. Make sure that is what
  happens. Never mix two sets under one revision.
- **Performance:** each template is about 0.9 KB of JSON, and the panel blocks
  its screen during a sync. Avoid N+1 queries. Decrypt only the page being
  returned.
- **Headers:** `Cache-Control: no-store`.

## 5. `POST /fingerprint-template-syncs`

- Validate against `TemplateSyncReport` (unknown extra properties are ignored,
  not rejected). Store the report. Reply `201 {"accepted": true}`.
- If `status` is `completed` or `partial` and `slots_present` is present:
  - delete `releasing` rows whose slot is **absent** (the slot is now free);
  - set `confirmed_at` on `active` rows whose slot is present;
  - record **drift**: `active` rows with a template whose slot is absent (the
    panel failed to write it, see `errors`), and slots present with no
    `active` or `releasing` row. Show it on the dashboard; do not
    auto-correct beyond the next sync.
- `aborted` reports change no assignments.
- Write audit-log entries (actor = device) for syncs that served templates and
  for each confirmed release.

## 6. Changes to existing behaviour

- **`POST /enrollments`** with a non-null `fingerprint_slot`: create or refresh
  this member's `enrolled_here` assignment `(device, slot)`, linking the stored
  template if one was accepted. The panel only enrols into a slot that is empty
  on its sensor, so its slot is authoritative. If that slot is held by another
  member's `active` `pushed` row, move that member to another free slot. A
  `releasing` row in that slot means the panel already deleted it, so delete
  the row. The revision changes as a result.
- **Resolving `(device, fingerprint_slot) → member`** in `/access-events`,
  `/authentications` and anywhere else must go through the assignments
  (`active` or `releasing`), not the enrolment's original slot. A pushed
  member's slot on another panel differs from the one they enrolled into.
- `delete_enrollment` device commands are **not executed by the firmware**.
  Template removal now happens through the sync. Leave the endpoints as they
  are.

## 7. Security (non-negotiable)

- This is the only endpoint that ever returns template bytes. Device
  credentials only; it is never reachable from a dashboard session, and the
  bytes appear in no other response, serializer, export, log line, error
  report or queue payload.
- Tenancy by construction: a device only ever receives its own tenant's
  templates.
- Never log `data`. Rate-limit per device as the other endpoints do.

## 8. Tests (minimum)

1. Missing `capacity`, `template_format` or `device_id` → 422. `device_id`
   mismatch → 403. `template_format=r503` → 422.
2. A device with 2 `enrolled_here` members (one with a stored template, one
   without) and 1 eligible member from another device → `total: 3`. The
   pushed member gets the lowest free slot. The template-less entry has
   `template: null`. `data` and `sha256` equal what was uploaded (use the
   fixture in the YAML).
3. Paging: `limit=2` returns 2 + 1 entries across pages, with the same
   revision, ascending slot, and no duplicates.
4. `since_revision` equal to the current revision → `unchanged: true`, empty
   `templates`. After any change (new enrolment, member suspended, template
   superseded, capacity changed) → a different revision.
5. More eligible than `capacity` → exactly `capacity` entries, every
   `enrolled_here` one kept, `truncated: true`. No `slot >= capacity` ever.
6. Suspending a member → their row becomes `releasing` and is not listed. Its
   slot is **not** given to a new pushed member until a `completed` report
   omits it from `slots_present`. After that report it is reusable.
7. `POST /enrollments` into a slot held by a not-yet-delivered pushed member →
   the pushed member moves to another slot, and the enrolment owns the slot.
8. An access event at slot S on device B resolves to the member pushed into S
   on B, not to whoever enrolled into slot S elsewhere.
9. Reports: `completed` with `slots_present` frees releasing slots and sets
   `confirmed_at`. `aborted` changes nothing. Drift is recorded. An unknown
   extra property is accepted.
10. No serializer or dashboard endpoint exposes template bytes. The GET
    response has `Cache-Control: no-store`.
11. Pending member: listed on the device they enrolled on, not on other
    devices. Once active, listed on every device they may use.

## 9. Acceptance

All tests in §8 pass, existing tests pass unchanged, and the served OpenAPI
document (if any) matches `device-api-v1.yaml` 1.2.0.
