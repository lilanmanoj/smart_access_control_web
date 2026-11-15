#!/usr/bin/env bash
#
# Smart Access Control — nightly logical backup of the access-control database.
#
# Run by the smart-access-backup.timer systemd unit. Writes a compressed dump
# to S3, where a bucket lifecycle rule expires it. EBS snapshots cover the
# whole volume; this covers the case where the volume is fine and the data is
# not — a bad migration, a mistaken prune, a corrupted table.
#
# Restore:
#   aws s3 cp s3://$BACKUP_BUCKET/mysql/<file> - \
#     | gunzip \
#     | docker compose --env-file compose.env -f docker-compose.aws.yml \
#         exec -T mysql mysql -u root -p"$MYSQL_ROOT_PASSWORD" smart_access
#
set -euo pipefail

APP_DIR=/opt/smart-access
# shellcheck source=/dev/null
. /etc/smart-access.conf          # AWS_REGION, BACKUP_BUCKET, ...

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
KEY="mysql/smart_access-${STAMP}.sql.gz"

cd "$APP_DIR"

# --single-transaction takes a consistent snapshot without locking the tables,
# which matters: access_events is appended to by every door, continuously.
# --set-gtid-purged=OFF keeps the dump replayable into a fresh server.
docker compose --env-file compose.env -f docker-compose.aws.yml exec -T mysql \
    sh -c 'exec mysqldump \
        -u root -p"$MYSQL_ROOT_PASSWORD" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --set-gtid-purged=OFF \
        --default-character-set=utf8mb4 \
        "$MYSQL_DATABASE"' \
  | gzip -9 \
  | aws s3 cp - "s3://${BACKUP_BUCKET}/${KEY}" \
        --region "$AWS_REGION" \
        --storage-class STANDARD_IA \
        --sse AES256 \
        --expected-size 1048576000

printf '[backup] wrote s3://%s/%s\n' "$BACKUP_BUCKET" "$KEY"

# A backup nobody verifies is a hope, not a backup. Fail loudly if the object
# is implausibly small — an empty dump still gzips to about 20 bytes.
SIZE=$(aws s3api head-object \
    --bucket "$BACKUP_BUCKET" --key "$KEY" \
    --region "$AWS_REGION" --query ContentLength --output text)

if [ "$SIZE" -lt 1024 ]; then
    printf '[backup] FATAL: %s is only %s bytes\n' "$KEY" "$SIZE" >&2
    exit 1
fi

printf '[backup] verified %s bytes\n' "$SIZE"
