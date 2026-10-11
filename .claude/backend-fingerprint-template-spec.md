# Backend task: store fingerprint templates sent at enrolment

**For:** the agent implementing the web backend (separate repository).
**Contract:** `device-api-v1.yaml` version 1.1.0 is the source of truth. This
file explains what to build. If the two ever disagree, the YAML wins.

## 1. Context

Each access panel (ESP32-S3 + AS608 optical fingerprint sensor) enrols a finger
into a numbered **slot** in the sensor's own flash, then calls
`POST /enrollments` with that slot. From firmware 1.1 the panel also reads the
stored template back out of the sensor and sends it, with its SHA-256, in an
**optional** `fingerprint_template` object.

Why: so the backend holds a copy of every template, which a later version will
use to push only *approved* members' templates back onto panels (sync after a
factory reset, enrol once and use at several doors). **That sync is not part of
this task**, but the storage you build must support it (see §5).

## 2. Scope

In scope:
- Accept, validate and store `fingerprint_template` on `POST /enrollments`.
- Return `template_status` (and `template_error` when rejected).
- Persistence, encryption at rest, lifecycle (supersede/delete), tests.

Out of scope:
- Any endpoint that returns template bytes to anyone, whether panel or dashboard.
- Changing `PATCH /enrollments/{memberId}` (it never carries a template).

## 3. Request contract (`POST /enrollments`)

Existing fields are unchanged. New optional field:

```json
{
  "device_id": "SMA_4821",
  "fingerprint_slot": 7,
  "fingerprint_template": {
    "format": "as608",
    "encoding": "base64",
    "size": 512,
    "sha256": "1c7fb1b3a4be3a63e8e9a81e871ff1a1e40c237f773a905bb5f5cd91e604a87f",
    "data": "cHtQC745+ZkdHZBKal34jgOajekuHEcWCmm56bjUgm5VAhJOIEoHQ5PWSCYtZ/nwzKPeN8nmCZlmwCLahBjhflWxEXKQbAhkouKGCfRZiaIXhJjRzlhX+HY0qZ14kUyuXfF6Klwqp2Qm+OAUGQfoeux0raospT7DFXDuS8N5oDvEG7uJTukic/csxeneGKDqBFym3dvp7x13ViqXv+XMCdioswF8p5BWcLHCxZ3AFrXzfvyln2eB51acgW9S0zO4rlYV7I0FDMFQegRvFH9MRRRwQVpnBSBpetkvflKb0eaUY//hE/0HjpO9MNtJAecP/5COM+1lvrIRwmNnlIY05Bsssy/XDTrohQs3ND8n3pp95pcoKpKVbHs7DKwelupdjn4t/QtCRinNmST0Dzi4yvrHjVTA8Bt++NKvQlHKHnCHZq64smZavRKueNYbHvu/D7ggpK1HlmPCkRg24l7EM6EkRSDoLvMoU2jeiO+wRqNt2b1L0wHaPYL+cj81rwnCBxIlLz139rlMhjYoPY3NHyoBPYxjszer/f8YtpcMvDw/ocaTjTGKWwzun1d12X6RTXB0faCCtic2YMZNmX7bFHKzZU+tnw8DGK4Ha7ChU5diQdu/99/YYMHf4KFplKm2KVicXkgRf2qhTxlS2u56hl9BQQqftv7rvs+CaE7aAWY="
  }
}
```

The `data` above is 512 **synthetic** bytes (not a real fingerprint) with a
correct `sha256`. Use it as the happy-path test fixture.

The other shapes you will receive:

```json
{ "device_id": "SMA_4821", "fingerprint_slot": 7 }
```
```json
{ "device_id": "SMA_4821", "fingerprint_slot": null }
```

| Field | Type | Rule |
|---|---|---|
| `format` | string | `as608` only, for now |
| `encoding` | string | `base64` only |
| `size` | integer | 1–1024; decoded byte length must equal it (512 on a genuine AS608) |
| `sha256` | string | `^[0-9a-f]{64}$`; lowercase hex SHA-256 of the **decoded bytes** |
| `data` | string | strict RFC 4648 base64 with padding, at most 1368 chars |

The slot is the top-level `fingerprint_slot`. It is not repeated inside the
template object.

## 4. Behaviour

### 4.1 The golden rule: a template problem never fails the enrolment

- Problems with the template must **not** produce a 4xx. Create the enrolment
  exactly as today, discard the template, and report it in the response.
- Keep `422` for problems with the *existing* fields only.
- The panel already guards against old backends: if it gets a `422` on a
  request that carried a template, it retries once without one. Do not rely on
  that. It is a safety net, not the design.
- Do not reject unknown properties anywhere in this body. Future firmware may
  add fields.

### 4.2 Validation order (first failure wins)

| # | Check | `template_error` |
|---|---|---|
| 0 | `fingerprint_slot` is non-null | `slot_missing` |
| 1 | `format` is known | `unsupported_format` |
| 2 | `encoding == "base64"` and `data` decodes strictly | `invalid_encoding` |
| 3 | `1 ≤ size ≤ 1024` and decoded length `== size` | `size_mismatch` |
| 4 | `sha256(decoded) == sha256` (constant-time compare not required) | `sha256_mismatch` |

A missing required sub-field (for example no `sha256`) counts as a failure of
the first check that needs it. Use `invalid_encoding` for anything in `data`,
`size_mismatch` for `size`, and `sha256_mismatch` for `sha256`.

### 4.3 Response (`201`, shape otherwise unchanged)

```json
{ "member_id": "3f0c6a52-…", "enrollment_id": "8d2b1e47-…", "status": "pending",
  "template_status": "stored" }
```
```json
{ "member_id": "3f0c6a52-…", "enrollment_id": "8d2b1e47-…", "status": "pending",
  "template_status": "rejected", "template_error": "sha256_mismatch" }
```
```json
{ "member_id": "3f0c6a52-…", "enrollment_id": "8d2b1e47-…", "status": "pending",
  "template_status": "absent" }
```

Log every rejection with device id, slot and `template_error`. **Never** log
`data`.

## 5. Storage

New table (adapt names to the codebase's conventions):

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id` | FK | same tenancy scoping as members/devices |
| `member_id` | FK → members | `ON DELETE CASCADE` |
| `enrollment_id` | FK → enrollments | `ON DELETE CASCADE` |
| `device_id` | FK → devices | the panel whose sensor produced it |
| `fingerprint_slot` | integer | slot on that panel |
| `format` | text | `as608` |
| `size` | integer | |
| `sha256` | char(64) | of the plaintext template, as sent |
| `data_encrypted` | bytea / blob | encrypted template bytes (see §6) |
| `status` | enum `active`, `superseded`, `deleted` | |
| `created_at`, `updated_at` | timestamps | |

- Add a **unique partial index** on `(device_id, fingerprint_slot) WHERE status = 'active'`.
- **Supersede:** a panel only reuses a slot after the old template was removed
  from its sensor. So when a new template arrives for an `active`
  `(device_id, slot)` pair, mark the old row `superseded` in the same
  transaction, then insert the new one.
- **Delete:** when a `delete_enrollment` device command is **acknowledged** for
  a slot, mark that row `deleted` and **erase `data_encrypted`** (set it to
  NULL). Biometric data must not outlive its enrolment. Deleting a member or an
  enrolment hard-deletes its rows through the cascade.
- `sha256` is the template's integrity and sync identity. It is **not** a
  biometric identity: the same finger enrolled twice yields different bytes, so
  do not try to de-duplicate people by hash.
- Keep `size` and `format`. The future sync uses `format` to refuse pushing a
  template to an incompatible sensor.

## 6. Security and privacy (non-negotiable)

- **Encrypt at rest** with application-level encryption (AES-256-GCM or the
  framework's encrypted-attribute feature). The key comes from the existing
  secret store or env and is never in the repo. Database-level encryption alone
  is not enough.
- The template must not appear in **any** response, serializer, admin/dashboard
  view, export, log line, error report or queue payload. Add a test that fails
  if a member or enrolment serializer exposes it.
- Raise the request body limit for this route if it is below 8 KB. The payload
  is about 0.9 KB.
- Write an audit-log entry (actor = device) when a template is stored,
  superseded or deleted.
- Tenancy: the device's tenant owns the template. Cross-tenant reads are
  impossible by construction.

## 7. Tests (minimum)

1. The happy path with the fixture from §3 → `201`, `template_status: stored`,
   one `active` row, and `data_encrypted` decrypts to the decoded fixture.
2. No `fingerprint_template` → `201`, `absent`, no row.
3. `fingerprint_slot: null` + template → `201`, `rejected` / `slot_missing`.
4. `format: "r503"` → `rejected` / `unsupported_format`.
5. `data` not valid base64 → `rejected` / `invalid_encoding`.
6. `size: 511` with the fixture → `rejected` / `size_mismatch`.
7. One hex digit of `sha256` changed → `rejected` / `sha256_mismatch`.
8. In every rejected case the member and enrolment were still created
   (`201`, `status: pending`).
9. A second template for the same device + slot → the old row becomes
   `superseded` and the new row is `active`.
10. A `delete_enrollment` command acknowledged → row `deleted`, data erased.
11. The member/enrolment serializers never contain template data.
12. An unknown extra property inside `fingerprint_template` is ignored, not
    rejected.

## 8. Acceptance

- Every test in §7 passes. Existing enrolment tests pass unchanged.
- The OpenAPI document served by the backend (if it serves one) matches
  `device-api-v1.yaml` 1.1.0 for `POST /enrollments`.
- A template's bytes can be decrypted only by server code, never returned by
  any endpoint.
