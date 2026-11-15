#!/usr/bin/env bash
#
# Smart Access Control — EC2 user-data. Runs once, as root, on first boot.
#
# Turns a bare Amazon Linux 2023 arm64 instance into a host that can run
# aws/deploy.sh. It installs nothing application-specific: no PHP, no MySQL
# client, no Composer. Those all live in the image.
#
# Edit the four values in the CONFIGURATION block before pasting this in as
# user data. Output lands in /var/log/cloud-init-output.log.
#
set -euxo pipefail

# --------------------------------------------------------------------- config
AWS_REGION=ap-south-1
ECR_REPO=smart-access-control
SSM_ENV_PARAM=/smart-access/prod/env
BACKUP_BUCKET=REPLACE-WITH-YOUR-BUCKET
GIT_REMOTE=https://github.com/lilanmanoj/smart_access_control_web.git
COMPOSE_VERSION=v2.32.4
# -----------------------------------------------------------------------------

cat > /etc/smart-access.conf <<CONF
AWS_REGION=${AWS_REGION}
ECR_REPO=${ECR_REPO}
SSM_ENV_PARAM=${SSM_ENV_PARAM}
BACKUP_BUCKET=${BACKUP_BUCKET}
GIT_REMOTE=${GIT_REMOTE}
CONF
chmod 644 /etc/smart-access.conf

dnf -q -y update
dnf -q -y install docker git jq

# Amazon Linux 2023 packages Docker but not the Compose v2 plugin.
install -d /usr/libexec/docker/cli-plugins
curl -fsSL \
  "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-aarch64" \
  -o /usr/libexec/docker/cli-plugins/docker-compose
chmod +x /usr/libexec/docker/cli-plugins/docker-compose

systemctl enable --now docker
usermod -aG docker ec2-user

# ---------------------------------------------------------------------------
# Swap. 2 GiB of RAM across six containers has little headroom, and the
# process the kernel picks when it runs out is whichever is largest — MySQL.
# Swap turns an OOM kill into a slow minute.
# ---------------------------------------------------------------------------
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Swap as a safety net, not as routine storage.
echo 'vm.swappiness=10' > /etc/sysctl.d/99-smart-access.conf
sysctl -p /etc/sysctl.d/99-smart-access.conf

# ---------------------------------------------------------------------------
# Docker's own log and image growth, on a 30 GB root volume.
# ---------------------------------------------------------------------------
cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
JSON
systemctl restart docker

# ---------------------------------------------------------------------------
# The repository, for docker-compose.aws.yml and the configuration it mounts.
# deploy.sh checks out the exact commit of each release.
# ---------------------------------------------------------------------------
if [ ! -d /opt/smart-access/.git ]; then
    git clone --depth 50 --branch prod "${GIT_REMOTE}" /opt/smart-access
fi
git config --global --add safe.directory /opt/smart-access

# ---------------------------------------------------------------------------
# Nightly database backup to S3.
# ---------------------------------------------------------------------------
cat > /etc/systemd/system/smart-access-backup.service <<'UNIT'
[Unit]
Description=Smart Access Control - MySQL backup to S3
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/opt/smart-access/aws/backup-mysql.sh
StandardOutput=journal
StandardError=journal
UNIT

cat > /etc/systemd/system/smart-access-backup.timer <<'UNIT'
[Unit]
Description=Smart Access Control - nightly MySQL backup

[Timer]
# 02:40 UTC is 08:10 in Asia/Colombo — after the 03:15 and 03:45 UTC
# scheduled jobs would have run, and nowhere near a working day.
OnCalendar=*-*-* 02:40:00
Persistent=true
RandomizedDelaySec=300

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now smart-access-backup.timer

# ---------------------------------------------------------------------------
# Unattended security patching. A box on the public internet that nobody logs
# into is a box nobody patches.
# ---------------------------------------------------------------------------
dnf -q -y install dnf-automatic
sed -i 's/^upgrade_type.*/upgrade_type = security/;s/^apply_updates.*/apply_updates = yes/' \
    /etc/dnf/automatic.conf
systemctl enable --now dnf-automatic.timer

echo "bootstrap complete — run aws/deploy.sh <sha> to deploy"
