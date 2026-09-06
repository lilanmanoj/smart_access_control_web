#!/usr/bin/env bash
#
# Smart Access Control — container entrypoint.
#
# One image, several roles. CONTAINER_ROLE picks which process this container
# runs; everything before the dispatch is shared preparation.
#
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"
APP_DIR=/var/www/html

# An explicit command (`docker compose run app php artisan tinker`) overrides
# the role dispatch entirely, but still gets the preparation below.
if [ "$#" -gt 0 ] && [ "$1" != "supervisord" ]; then
    ROLE="command"
fi

log() { printf '[entrypoint] %s\n' "$*" >&2; }

cd "$APP_DIR"

# ---------------------------------------------------------------------------
# Writable paths. In dev these live on a bind mount that may arrive empty.
# ---------------------------------------------------------------------------
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# Dependencies. The production image bakes vendor/ in; a bind-mounted dev tree
# may not have it yet, so install on first boot.
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
    # Only the web role installs. In development every role shares one
    # bind-mounted tree, and four containers running `composer install` into
    # the same vendor/ at once corrupt each other's work — the others wait for
    # it to appear instead.
    if [ "$ROLE" = "app" ] || [ "$ROLE" = "command" ]; then
        log "vendor/ missing — running composer install"
        COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer}" \
            composer install --no-interaction --prefer-dist --no-progress
    else
        log "waiting for the app container to install dependencies"

        waited=0
        while [ ! -f vendor/autoload.php ] && [ "$waited" -lt 600 ]; do
            sleep 3
            waited=$((waited + 3))
        done

        if [ ! -f vendor/autoload.php ]; then
            log "FATAL: dependencies were never installed."
            exit 1
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Environment file and key. Only ever generated when absent: regenerating
# APP_KEY would make every encrypted column and session unreadable.
# ---------------------------------------------------------------------------
if [ "${APP_ENV:-production}" = "production" ]; then
    # Never seed defaults into production. .env.example carries APP_DEBUG=true
    # and a development database password; dotenv does not override real
    # environment variables, but it *does* fill in anything the environment
    # left unset — which is exactly how a debug-mode production deploy happens.
    #
    # A production container is configured entirely from its environment
    # (docker-compose.prod.yml's env_file), and the one value it cannot
    # generate for itself is APP_KEY: every container in the stack would invent
    # a different one, and sessions and encrypted columns would stop resolving
    # across them.
    if [ -z "${APP_KEY:-}" ] && ! { [ -f .env ] && grep -qE '^APP_KEY=base64:' .env; }; then
        log "FATAL: APP_KEY is not set."
        log "Generate one with:  docker compose run --rm app php artisan key:generate --show"
        log "then put it in the .env file this stack reads."
        exit 1
    fi
else
    if [ ! -f .env ] && [ -f .env.example ]; then
        log ".env missing — seeding it from .env.example"
        cp .env.example .env
    fi

    if [ -f .env ] && ! grep -qE '^APP_KEY=base64:' .env; then
        log "APP_KEY missing — generating one"
        php artisan key:generate --force --no-interaction
    fi
fi

# Compose passes the same .env in as the container environment, and a real
# environment variable always wins over the file. So an empty `APP_KEY=` line —
# which is exactly what a fresh checkout has — keeps overriding the key in the
# file. Re-export it whenever the environment's copy is blank; without this the
# app boots with no encryption key and every request 500s.
if [ -z "${APP_KEY:-}" ] && [ -f .env ]; then
    APP_KEY="$(grep -E '^APP_KEY=' .env | head -n1 | cut -d= -f2-)"
    export APP_KEY
    log "exported APP_KEY from .env"
fi

# ---------------------------------------------------------------------------
# Wait for the database. The shared-hosting deployment points at a MySQL server
# outside the compose project, so this can legitimately take a moment.
# ---------------------------------------------------------------------------
wait_for_database() {
    local attempts="${DB_WAIT_ATTEMPTS:-30}"
    local i=1

    # `db:show` boots the framework, so it resolves the connection exactly the
    # way the application will — including values that live only in .env. A
    # hand-rolled PDO check against getenv() misses those entirely and reports
    # an unreachable database that migrations then connect to fine.
    while [ "$i" -le "$attempts" ]; do
        if php artisan db:show --json >/dev/null 2>&1; then
            log "database reachable"
            return 0
        fi
        log "waiting for database (${i}/${attempts})"
        sleep 2
        i=$((i + 1))
    done

    log "database still unreachable after ${attempts} attempts — continuing anyway"
    return 1
}

if [ "${SKIP_DB_WAIT:-false}" != "true" ]; then
    wait_for_database || true
fi

# ---------------------------------------------------------------------------
# Schema. Off by default: in a shared-hosting deployment the operator decides
# when the database changes. Compose sets RUN_MIGRATIONS=true for the web role.
# ---------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "running migrations"
    php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    log "running seeders"
    php artisan db:seed --force --no-interaction
fi

# ---------------------------------------------------------------------------
# Caches. Built in production, cleared in development so edits take effect.
# ---------------------------------------------------------------------------
if [ "${APP_ENV:-production}" = "production" ] && [ "${SKIP_OPTIMIZE:-false}" != "true" ]; then
    log "caching config, routes and views"
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    php artisan event:cache --no-interaction
else
    php artisan optimize:clear --no-interaction >/dev/null 2>&1 || true
fi

# `storage:link` is a no-op when the link already exists.
php artisan storage:link --no-interaction >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Role dispatch.
# ---------------------------------------------------------------------------
log "starting role: ${ROLE}"

case "$ROLE" in
    app)
        exec supervisord -c /etc/supervisor/supervisord.conf
        ;;
    queue)
        exec php artisan queue:work \
            --queue="${QUEUE_NAMES:-events,notifications,default}" \
            --sleep=1 \
            --tries=3 \
            --max-time=3600 \
            --max-jobs=1000
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    reverb)
        exec php artisan reverb:start \
            --host=0.0.0.0 \
            --port="${REVERB_SERVER_PORT:-8080}"
        ;;
    command)
        exec "$@"
        ;;
    *)
        log "unknown CONTAINER_ROLE '${ROLE}'"
        exit 1
        ;;
esac
