# Working in this repository

Smart Access Control — the backend and dashboard for an ESP32-S3 access panel.
The requirements brief this was built from is `.claude/backend_requirement.md`;
read §2 of it before changing anything that touches the device contract.

## Everything runs in Docker

There is no PHP, Composer, MySQL or Apache on the host, and none is needed.

```sh
docker compose up -d                                   # start the stack
docker compose exec app php artisan <command>          # artisan
docker compose exec app php artisan test               # the suite (MySQL, not SQLite)
docker compose run --rm --no-deps composer install     # composer, if you add a dependency
npm run build                                          # front-end (Node is on the host)
```

`docker compose exec` needs `-e HOME=/tmp` for `tinker`, which wants a writable
home directory.

## Constraints that are not negotiable

These come from shipped firmware. Code that violates one produces a system that
cannot work on real hardware.

- **Codes are numeric.** The keypad is a 4×3 matrix with no letters on it. Six
  digits, leading zeros preserved.
- **The server owns every authoritative timestamp.** The device has no RTC.
  `uptime_ms` orders events within one batch and is never a wall clock.
- **Fingerprint templates are device-local** and survive reflashes. The backend
  records ownership of a slot; it can never move a template.
- **The door must work offline.** Ingestion accepts batched, replayed
  submissions. Backup codes are cached in plaintext on the panel on purpose.
- **Phone numbers are ≤ 14 characters** in E.164.

## Invariants to preserve

- **Access events are append-only.** The model throws on update and on
  individual delete. Retention pruning goes through the query builder, which is
  the one intended exception.
- **Tenant binding runs before route-model binding.** Middleware priority in
  `bootstrap/app.php` enforces this; without it a route binding resolves rows
  from any tenant. `tests/Feature/TenantIsolationTest.php` covers it.
- **Secrets are never returned twice.** Device secrets, backup codes, webhook
  signing secrets and recovery codes each exist in exactly one response.
- **Authorisation is by permission name**, never by role string.
- **No secret is written to a log**, including the audit log — `AuditLogger`
  redacts centrally so a new call site cannot forget.
- **`phpunit.xml` uses `<server>`, not `<env>`.** Laravel's Env repository reads
  `$_SERVER` before `$_ENV`, and Docker delivers container configuration into
  `$_SERVER`; an `<env force="true">` override is silently ignored, and the
  suite would then run `migrate:fresh` against the development database.

## Conventions

- `declare(strict_types=1)` everywhere; PHP 8.4 in the container.
- Laravel 13 model idiom: `#[Fillable]` / `#[Hidden]` attributes, `casts()`
  method, enums for every status column.
- Device routes are kebab-case plural nouns; the HTTP method carries the verb.
- Errors use one envelope: `{"error": {"code", "message"}}`. The `code` is the
  contract; branch on it, not the message.
- Front-end: colour never carries status alone — every badge pairs a colour
  with a glyph and a word.

## Where things live

`README.md` has the full map. The short version: device API in
`app/Http/Controllers/Api/V1`, dashboard API in `app/Http/Controllers/Admin`,
domain logic in `app/Services`, isolation in `app/Models/Scopes/TenantScope.php`,
tunable behaviour in `config/access.php`, committed API specs in `openapi/`.
