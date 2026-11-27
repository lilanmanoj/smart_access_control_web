#!/usr/bin/env bash
#
# Smart Access Control — deploy one build onto this instance.
#
#   sudo /opt/smart-access/aws/deploy.sh <git-sha>
#
# Invoked by GitHub Actions through SSM Run Command, and safe to run by hand
# over an SSM session. It is idempotent: running it twice with the same sha
# converges on the same state.
#
# The sha identifies both halves of a release — the image tag in ECR and the
# commit this repository is checked out at, which supplies docker-compose.aws.yml
# and the configuration it mounts. Pinning both to one sha is what makes a
# rollback a single argument.
#
set -euo pipefail

SHA="${1:?usage: deploy.sh <git-sha>}"
APP_DIR=/opt/smart-access
COMPOSE=(docker compose --env-file "$APP_DIR/compose.env" -f "$APP_DIR/docker-compose.aws.yml")

# shellcheck source=/dev/null
. /etc/smart-access.conf          # AWS_REGION, ECR_REPO, SSM_ENV_PARAM, GIT_REMOTE

log() { printf '[deploy] %s\n' "$*"; }

ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
REGISTRY="${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
IMAGE="${REGISTRY}/${ECR_REPO}:${SHA}"

cd "$APP_DIR"

# ---------------------------------------------------------------------------
# 1. The compose file and mounted configuration, at this release's commit.
#    No application code runs from here — that lives in the image.
# ---------------------------------------------------------------------------
log "checking out $SHA"
git fetch --depth 50 origin "$SHA" 2>/dev/null || git fetch --depth 50 origin prod
git checkout --force --detach "$SHA"

# ---------------------------------------------------------------------------
# 2. Configuration. The single source of truth is the SSM parameter, so a
#    replacement instance configures itself and no secret survives on a
#    detached volume longer than the instance does.
# ---------------------------------------------------------------------------
log "fetching .env from $SSM_ENV_PARAM"
umask 077
aws ssm get-parameter \
    --name "$SSM_ENV_PARAM" \
    --with-decryption \
    --region "$AWS_REGION" \
    --query 'Parameter.Value' --output text > "$APP_DIR/.env.new"

if ! grep -qE '^APP_KEY=base64:' "$APP_DIR/.env.new"; then
    log "FATAL: the parameter does not contain an APP_KEY. Refusing to deploy."
    rm -f "$APP_DIR/.env.new"
    exit 1
fi

mv "$APP_DIR/.env.new" "$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"

# Compose reads this for ${APP_IMAGE} and the Caddy site address. It is kept
# separate from .env so that Compose never interpolates application secrets —
# a '$' in a password would otherwise be eaten.
{
    echo "APP_IMAGE=${IMAGE}"
    grep -E '^(SITE_ADDRESS|ACME_EMAIL)=' "$APP_DIR/.env" || true
} > "$APP_DIR/compose.env"

# ---------------------------------------------------------------------------
# 3. Pull and roll. `up -d` recreates only what changed; Redis and MySQL keep
#    running, so a deploy does not drop the queue or close every connection.
# ---------------------------------------------------------------------------
log "logging in to $REGISTRY"
aws ecr get-login-password --region "$AWS_REGION" \
    | docker login --username AWS --password-stdin "$REGISTRY"

log "pulling $IMAGE"
"${COMPOSE[@]}" pull --quiet app

log "starting"
"${COMPOSE[@]}" up -d --remove-orphans

# ---------------------------------------------------------------------------
# 4. Verify. The app container runs the migrations on boot, so this waits on
#    real readiness rather than on the container merely existing.
#
#    The probe runs *inside* the app container rather than against
#    http://127.0.0.1 on the host. Once SITE_ADDRESS is a hostname rather than
#    ":80", Caddy serves only that hostname and answers a loopback request with
#    404 — so a host-side probe would fail against a completely healthy stack
#    and every deploy after the domain cutover would report failure.
# ---------------------------------------------------------------------------
log "waiting for the application to answer /up"
for attempt in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T app curl -fsS --max-time 5 http://127.0.0.1/up >/dev/null 2>&1; then
        log "application healthy after $((attempt * 5))s"
        break
    fi

    if [ "$attempt" -eq 60 ]; then
        log "FATAL: /up never answered. Recent application output:"
        "${COMPOSE[@]}" logs --tail 80 app caddy
        exit 1
    fi

    sleep 5
done

# Caddy's side of it. Checked through the public site address, because that is
# the only name Caddy answers for once one is configured. Advisory only: DNS
# and certificate issuance can legitimately still be settling right after a
# cutover, and that is not a reason to fail a deploy whose app is healthy.
SITE=$(grep -E '^SITE_ADDRESS=' "$APP_DIR/.env" | head -n1 | cut -d= -f2- | tr -d '"' | cut -d, -f1)

if [ -n "$SITE" ] && [ "${SITE#:}" = "$SITE" ]; then
    if curl -fsS --max-time 10 -H "Host: ${SITE}" http://127.0.0.1/up >/dev/null 2>&1; then
        log "caddy is serving ${SITE}"
    else
        log "NOTE: caddy did not answer for ${SITE} yet — DNS or the certificate may still be settling"
    fi
else
    curl -fsS --max-time 10 http://127.0.0.1/up >/dev/null 2>&1 \
        && log "caddy is serving on :80" \
        || log "NOTE: caddy did not answer on :80 yet"
fi

# ---------------------------------------------------------------------------
# 5. MQTT doorbell (device API 1.4.0). Idempotent: lets the backend publish
#    doorbells on the broker. Advisory, like the Caddy check: with the broker
#    down panels simply keep polling /health, which is no reason to fail a
#    deploy whose application is healthy.
# ---------------------------------------------------------------------------
if grep -qE '^MQTT_ENABLED=(true|1)\b' "$APP_DIR/.env"; then
    if "${COMPOSE[@]}" exec -T app php artisan mqtt:bootstrap; then
        log "mqtt broker ready"
    else
        log "NOTE: mqtt:bootstrap failed — panels stay on /health polling. Check: ${COMPOSE[*]} logs mosquitto"
    fi
fi

docker image prune -af --filter 'until=72h' >/dev/null 2>&1 || true
log "deployed ${SHA}"
