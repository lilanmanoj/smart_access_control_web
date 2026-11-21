# Deploying Smart Access Control to AWS (single EC2 instance)

Target: the whole system on one `t4g.small` instance with a static public IP,
deployed automatically when a pull request merges into `prod`. About
**$20/month**, against ~$120 for the ECS Fargate arrangement in
[aws-fargate.md](aws-fargate.md).

Work through it in order — later steps need IDs from earlier ones. Expect about
45 minutes.

TLS and your `domain.com` domain are [the last part](#part-11--later-your-domain-and-https),
and Caddy makes that a two-line change. Until then the instance serves plain
HTTP on its Elastic IP, which is fine for your own testing and **not** fine for
real door panels — their `X-Device-Secret` would travel in cleartext.

---

## Part 0 — The plan

### Shape

```
Internet
   │
   │  Elastic IP (static)  ──  your domain, later
   ▼
┌─────────────────────────────── t4g.small, Amazon Linux 2023 ──┐
│                                                               │
│  caddy :80/:443  ──┬── /app, /apps ──▶ reverb  :8080          │
│                    └── everything   ──▶ app     :80           │
│                                          (nginx + PHP-FPM)    │
│                                                               │
│  queue        scheduler        redis        mysql             │
│                                                               │
└───────────────────────────────────────────────────────────────┘
        all seven containers from one image pulled from ECR
```

Compare that with the shared-hosting layout in the README: the same four
application roles, with Caddy standing in for the host's Apache and MySQL and
Redis joining the stack instead of being provided by the host.

### Why this is so much cheaper

Fargate's cost was never the compute — it was one ALB (~$26/month), a managed
RDS instance (~$16), a managed ElastiCache node (~$12) and four public IPv4
addresses. Here Caddy replaces the ALB, and MySQL and Redis are containers on a
box you are already paying for.

| | |
|---|---|
| `t4g.small` on demand | $12.26 |
| 30 GB gp3 root volume | $2.76 |
| Elastic IP (in use) | $3.65 |
| EBS snapshots, S3 dumps, ECR | ~$1.60 |
| **Total** | **~$20.30** |

A one-year no-upfront Compute Savings Plan takes the instance to about $8.60,
so **~$17/month** once you are confident it is staying.

### What you give up, stated plainly

- **It is one box.** If it fails, the door panels keep working — they are built
  to operate offline, which is the single biggest reason this is a defensible
  choice — but the dashboard, OTP delivery and event ingestion stop until it is
  replaced. Restoring onto a fresh instance is Part 10, and it is about fifteen
  minutes.
- **Deploys have a gap.** `docker compose up -d` recreates the app container, so
  there is roughly 20–40 seconds where Caddy has nothing to proxy to. Devices
  retry and queue their events; operators see one failed page load.
- **MySQL is your responsibility.** No automated point-in-time recovery. Part 8
  sets up nightly dumps to S3 and daily EBS snapshots, which covers the
  realistic failures, but restoring is a command you run rather than a console
  button.
- **You patch the host.** The bootstrap script enables `dnf-automatic` for
  security updates, and the kernel needs an occasional reboot.

If any of those stops being acceptable, [aws-fargate.md](aws-fargate.md) is the
same application on managed infrastructure, and the migration is mostly
changing `DB_HOST`.

### Resource inventory

| Resource | Purpose |
|---|---|
| **ECR** repository | Holds the image CI builds. |
| **EC2** `t4g.small`, Amazon Linux 2023 arm64 | Runs everything. |
| **Elastic IP** | The static public address you asked for — and what your domain will point at later. |
| **Security group** | Inbound 80 and 443 only. No SSH port. |
| **IAM instance profile** | Pull from ECR, read the config parameter, write backups, accept SSM commands. |
| **SSM Parameter Store** SecureString | The entire `.env`. One place, encrypted, survives the instance. |
| **S3** bucket | Nightly database dumps, expired on a lifecycle rule. |
| **DLM** policy | Daily EBS snapshots with automatic retention. |
| **IAM** OIDC role | Lets GitHub Actions push to ECR and trigger a deploy with no stored keys. |
| **CloudWatch** alarm | Auto-recovers the instance if its host fails. |

No VPC to build — the region's default VPC is right for one instance. No load
balancer, no NAT gateway, no subnet groups.

### Will it actually fit in 2 GiB?

This is the one real engineering question, and the answer is yes with the
tuning in [docker/aws/](../../docker/aws/), which this deployment mounts over
the image's defaults.

| | Resident |
|---|---|
| MySQL 8.4 (`innodb_buffer_pool_size=256M`, `performance_schema=OFF`) | ~480 MB |
| `app`: nginx + PHP-FPM master + 8 workers | ~420 MB |
| Redis (`maxmemory 192mb`) | ~120 MB |
| `queue` (one PHP process) | ~90 MB |
| `scheduler` (one PHP process, plus what it spawns) | ~120 MB |
| `reverb` (one long-lived PHP process) | ~90 MB |
| Caddy | ~30 MB |
| OS, dockerd, containerd, SSM agent | ~300 MB |
| **Total** | **~1.65 GB of 1.9 GB usable** |

Three things make that work, and all three are in the committed configuration:

- **`pm.max_children` drops from 20 to 8** in
  [docker/aws/php-fpm.conf](../../docker/aws/php-fpm.conf). The image's pool
  allows 20 children at a 512 MB `memory_limit`; on this box a traffic spike
  would have the kernel OOM-kill MySQL, which is the worst possible choice it
  could make. Eight workers is far more than a few panels and a handful of
  operators need.
- **opcache drops from 192 MB to 64 MB** in
  [docker/aws/php-ec2.ini](../../docker/aws/php-ec2.ini). Four containers run
  PHP and each maps its own opcache segment, so the default reserves 768 MB of
  address space for a codebase that compiles to well under 64 MB.
- **`performance_schema = OFF`** in
  [docker/aws/mysql.cnf](../../docker/aws/mysql.cnf) returns about 200 MB.

Plus a 2 GB swap file, which turns the rare spike into a slow minute instead of
a dead container.

> If you would rather not think about any of this, `t4g.medium` is 4 GiB for
> about $12/month more and the tuning becomes unnecessary (though harmless). It
> is a stop, change instance type, start — about two minutes of downtime,
> and nothing else in this runbook changes.

---

## Part 1 — Prerequisites and shell variables

```sh
aws --version            # aws-cli/2.x
aws sts get-caller-identity
```

You also need the Session Manager plugin. The AWS CLI does not bundle it, and
without it `aws ssm start-session` fails with `SessionManagerPlugin is not
found` — which matters here because an SSM session is the *only* way onto the
instance. There is no SSH port.

**Debian, Ubuntu and derivatives (Linux Mint, Pop!_OS, elementary):**

```sh
curl -fsSL -o /tmp/session-manager-plugin.deb \
  "https://s3.amazonaws.com/session-manager-downloads/plugin/latest/ubuntu_64bit/session-manager-plugin.deb"

sudo dpkg -i /tmp/session-manager-plugin.deb
rm -f /tmp/session-manager-plugin.deb
```

On arm64 (a Raspberry Pi or an ARM laptop), swap `ubuntu_64bit` for
`ubuntu_arm64`. Mint reports its Ubuntu base in
`/etc/upstream-release/lsb-release`; the plugin has no distribution-specific
dependencies, so the Ubuntu package is correct for any Mint release.

**Fedora, RHEL, Amazon Linux:**

```sh
sudo dnf install -y \
  "https://s3.amazonaws.com/session-manager-downloads/plugin/latest/linux_64bit/session-manager-plugin.rpm"
```

**macOS:** `brew install --cask session-manager-plugin`

Verify, in a new shell so `PATH` is picked up:

```sh
session-manager-plugin --version
```

It prints a version such as `1.2.707.0`. The binary installs to
`/usr/local/sessionmanagerplugin/bin/` with a symlink in `/usr/local/bin/`, so
nothing needs adding to `PATH`.

Keep one terminal for the whole runbook. The
[recovery snippet](#recovering-your-shell-variables) at the end re-reads every
ID from AWS if you lose it.

```sh
export AWS_REGION=ap-south-1                 # Mumbai — closest to Colombo
export AWS_PAGER=""
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export PROJECT=smart-access
export GH_REPO=lilanmanoj/smart_access_control_web
export INSTANCE_NAME=smart-access-prod
export BACKUP_BUCKET=$PROJECT-backups-$ACCOUNT_ID    # bucket names are global

echo "$AWS_REGION / $ACCOUNT_ID / $BACKUP_BUCKET"
```

---

## Part 2 — ECR repository

```sh
aws ecr create-repository \
  --repository-name smart-access-control \
  --image-scanning-configuration scanOnPush=true \
  --encryption-configuration encryptionType=AES256

export ECR_URI=$(aws ecr describe-repositories \
  --repository-names smart-access-control \
  --query 'repositories[0].repositoryUri' --output text)
echo "$ECR_URI"

aws ecr put-lifecycle-policy \
  --repository-name smart-access-control \
  --lifecycle-policy-text '{
    "rules": [{
      "rulePriority": 1,
      "description": "Keep the 15 most recent images",
      "selection": { "tagStatus": "any", "countType": "imageCountMoreThan", "countNumber": 15 },
      "action": { "type": "expire" }
    }]
  }'
```

---

## Part 3 — The backup bucket

```sh
aws s3api create-bucket \
  --bucket "$BACKUP_BUCKET" \
  --region "$AWS_REGION" \
  --create-bucket-configuration "LocationConstraint=$AWS_REGION"

aws s3api put-bucket-encryption --bucket "$BACKUP_BUCKET" \
  --server-side-encryption-configuration '{
    "Rules": [{ "ApplyServerSideEncryptionByDefault": { "SSEAlgorithm": "AES256" } }]
  }'

aws s3api put-public-access-block --bucket "$BACKUP_BUCKET" \
  --public-access-block-configuration \
  "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"

aws s3api put-bucket-versioning --bucket "$BACKUP_BUCKET" \
  --versioning-configuration Status=Enabled

# Dumps are small and compressible; 30 days of them costs pennies.
aws s3api put-bucket-lifecycle-configuration --bucket "$BACKUP_BUCKET" \
  --lifecycle-configuration '{
    "Rules": [
      { "ID": "expire-dumps", "Status": "Enabled",
        "Filter": { "Prefix": "mysql/" },
        "Expiration": { "Days": 30 } },
      { "ID": "clean-old-versions", "Status": "Enabled",
        "Filter": { "Prefix": "" },
        "NoncurrentVersionExpiration": { "NoncurrentDays": 7 } },
      { "ID": "abort-incomplete", "Status": "Enabled",
        "Filter": { "Prefix": "" },
        "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 3 } }
    ]
  }'
```

---

## Part 4 — The configuration parameter

The whole `.env` lives in SSM Parameter Store as one encrypted SecureString.
That is the single source of truth: `aws/deploy.sh` fetches it on every deploy,
so a replacement instance configures itself, and nothing secret has to be typed
onto a box twice.

### 4.1 Generate the credentials

```sh
export APP_KEY="base64:$(openssl rand -base64 32)"
export DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-24)
export DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-24)
export REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)
export REVERB_APP_KEY=$(openssl rand -hex 16)
export REVERB_APP_SECRET=$(openssl rand -hex 32)
```

> `tr -dc 'A-Za-z0-9'` is not fussiness. A `$` in a database password is read as
> a variable reference by one tool or another somewhere in a stack this size,
> and the failure is always confusing. Alphanumeric passwords of 24 characters
> are plenty.
>
> **`APP_KEY` is not regenerable.** It encrypts the `two_factor_secret` and
> `two_factor_recovery_codes` columns. Change it and every operator's
> authenticator app stops working with no way back. Store it somewhere you will
> still have in a year — the SSM parameter is that place, but keep your own copy
> too.

You need the Elastic IP before you can finish the file, so allocate it now:

```sh
export EIP_ALLOC=$(aws ec2 allocate-address --domain vpc \
  --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=$INSTANCE_NAME}]" \
  --query 'AllocationId' --output text)

export PUBLIC_IP=$(aws ec2 describe-addresses --allocation-ids "$EIP_ALLOC" \
  --query 'Addresses[0].PublicIp' --output text)
echo "http://$PUBLIC_IP"
```

**That is your public address.** It stays yours until you release it, so it
survives instance replacement — which is exactly what an ALB could not give
you.

### 4.2 Write the parameter

```sh
cat > /tmp/sac.env <<EOF
APP_NAME="Smart Access Control"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=http://$PUBLIC_IP
APP_TIMEZONE=UTC

# Caddy's site address. ":80" serves the bare IP over plain HTTP. Replace with
# your hostname in Part 11 and Caddy obtains a certificate by itself.
SITE_ADDRESS=:80
ACME_EMAIL=you@example.com

# Container output goes to the Docker log, which is where you will look for it.
# The image's default writes to storage/logs inside the container instead.
LOG_CHANNEL=stderr
LOG_LEVEL=info

# -----------------------------------------------------------------------------
# Database. DB_* is what Laravel reads; MYSQL_* is what the mysql container
# reads to initialise itself. Same credentials, two sets of names, written once.
# -----------------------------------------------------------------------------
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=smart_access
DB_USERNAME=smart_access
DB_PASSWORD=$DB_PASSWORD

MYSQL_ROOT_PASSWORD=$DB_ROOT_PASSWORD
MYSQL_DATABASE=smart_access
MYSQL_USER=smart_access
MYSQL_PASSWORD=$DB_PASSWORD

# -----------------------------------------------------------------------------
# Redis — in the stack, serving the cache, the queue, sessions and Reverb.
# -----------------------------------------------------------------------------
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# false while you are on plain HTTP. Setting this true now means the session
# cookie is never sent and login fails with a 419 and no useful message.
SESSION_SECURE_COOKIE=false

# Cookie auth for the dashboard. If this does not match the host in the
# browser's address bar, login succeeds and then every API call returns 401.
SANCTUM_STATEFUL_DOMAINS=$PUBLIC_IP

# -----------------------------------------------------------------------------
# Broadcasting — Reverb
# -----------------------------------------------------------------------------
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET

# Where the Reverb process listens inside its container.
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Where the app containers publish events: straight to the container, on the
# compose network. Not the address the browser uses.
REVERB_PUBLISH_HOST=reverb
REVERB_PUBLISH_PORT=8080
REVERB_PUBLISH_SCHEME=http

# What the browser connects to — through Caddy, on the public address.
REVERB_HOST=$PUBLIC_IP
REVERB_PORT=80
REVERB_SCHEME=http

# -----------------------------------------------------------------------------
# Mail and SMS. Both off until Part 9; OTP codes land in the log meanwhile.
# -----------------------------------------------------------------------------
MAIL_MAILER=log
MAIL_FROM_ADDRESS="access@example.com"
MAIL_FROM_NAME="Smart Access Control"

SMS_DRIVER=null
SMS_SENDER_ID=ACCESS

# -----------------------------------------------------------------------------
# Access control domain settings
# -----------------------------------------------------------------------------
PHONE_DEFAULT_COUNTRY_CODE=+94

OTP_TTL_SECONDS=90
OTP_MAX_ATTEMPTS=3
OTP_RETURN_CODE_TO_DEVICE=false
OTP_REQUIRE_ENROLLMENT=false

BACKUP_CODES_ONLINE_ONLY=false
BACKUP_CODE_MAX_AGE_DAYS=0

DEVICE_OFFLINE_AFTER_SECONDS=100
DEVICE_COMMAND_TTL_SECONDS=300
DEVICE_REQUIRE_SIGNATURE=false

EVENT_DUPLICATE_WINDOW=10
EVENT_RETENTION_DAYS=365

ACCESS_SCHEDULES_ENFORCE=true
ACCESS_SCHEDULE_TIMEZONE=Asia/Colombo
EOF

aws ssm put-parameter \
  --name "/smart-access/prod/env" \
  --type SecureString \
  --description "Smart Access Control production .env" \
  --value "file:///tmp/sac.env" \
  --tier Intelligent-Tiering

shred -u /tmp/sac.env 2>/dev/null || rm -f /tmp/sac.env
```

> **`--tier Intelligent-Tiering`, not `Standard`.** A Standard parameter caps at
> 4096 bytes and the file above lands at roughly 3,700 once the credentials are
> substituted — under the limit, but with only a few hundred bytes spare. Adding
> the SES block in Part 10 would use most of what is left, and the failure comes
> at `put-parameter` time with a message about value length. Intelligent-Tiering
> stays free while the value fits in Standard and silently promotes it to
> Advanced ($0.05/month) if it ever does not.

To change a setting later, edit and re-upload, then redeploy:

```sh
aws ssm get-parameter --name /smart-access/prod/env --with-decryption \
  --query Parameter.Value --output text > /tmp/sac.env
$EDITOR /tmp/sac.env
aws ssm put-parameter --name /smart-access/prod/env --type SecureString \
  --value "file:///tmp/sac.env" --overwrite
rm -f /tmp/sac.env
# then: Part 7's deploy command, or re-run the GitHub workflow
```

Note `REVERB_PUBLISH_HOST=reverb` — on one box the app containers reach Reverb
by its compose service name on the internal network, which is simpler and
faster than the Fargate arrangement, where there is no such name and the
traffic has to hairpin back through the load balancer.

---

## Part 5 — IAM

### 5.1 The instance profile

```sh
aws iam create-role --role-name "$PROJECT-instance" \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ec2.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

# Session Manager shells and Run Command deploys.
aws iam attach-role-policy --role-name "$PROJECT-instance" \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore

# Pull the application image.
aws iam attach-role-policy --role-name "$PROJECT-instance" \
  --policy-arn arn:aws:iam::aws:policy/AmazonEC2ContainerRegistryReadOnly

aws iam put-role-policy --role-name "$PROJECT-instance" \
  --policy-name config-and-backups \
  --policy-document "$(jq -n \
      --arg region "$AWS_REGION" --arg acct "$ACCOUNT_ID" --arg bucket "$BACKUP_BUCKET" '{
    Version: "2012-10-17",
    Statement: [
      { Sid: "ReadTheEnvParameter",
        Effect: "Allow",
        Action: ["ssm:GetParameter"],
        Resource: "arn:aws:ssm:\($region):\($acct):parameter/smart-access/prod/*" },
      { Sid: "DecryptIt",
        Effect: "Allow",
        Action: ["kms:Decrypt"],
        Resource: "*",
        Condition: { StringEquals: { "kms:ViaService": "ssm.\($region).amazonaws.com" } } },
      { Sid: "WriteBackups",
        Effect: "Allow",
        Action: ["s3:PutObject", "s3:GetObject", "s3:ListBucket"],
        Resource: ["arn:aws:s3:::\($bucket)", "arn:aws:s3:::\($bucket)/*"] }
    ]
  }')"

aws iam create-instance-profile --instance-profile-name "$PROJECT-instance"
aws iam add-role-to-instance-profile \
  --instance-profile-name "$PROJECT-instance" --role-name "$PROJECT-instance"
```

The `kms:ViaService` condition is worth noting: it allows decryption only when
the request comes through SSM, so this role cannot be used to read arbitrary
encrypted material.

### 5.2 The GitHub Actions role

Register GitHub as an OIDC provider (once per account — `EntityAlreadyExists`
means it is already done):

```sh
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1
```

```sh
aws iam create-role --role-name "$PROJECT-github-deploy" \
  --assume-role-policy-document "$(jq -n \
      --arg acct "$ACCOUNT_ID" --arg repo "$GH_REPO" '{
    Version: "2012-10-17",
    Statement: [{
      Effect: "Allow",
      Principal: { Federated: "arn:aws:iam::\($acct):oidc-provider/token.actions.githubusercontent.com" },
      Action: "sts:AssumeRoleWithWebIdentity",
      Condition: {
        StringEquals: { "token.actions.githubusercontent.com:aud": "sts.amazonaws.com" },
        StringLike:   { "token.actions.githubusercontent.com:sub": "repo:\($repo):ref:refs/heads/prod" }
      }
    }]
  }')"
```

The `sub` condition is the security boundary, and it matters more here than
usual because this repository is public: **only a workflow running on the `prod`
branch of your repository can assume this role.** A pull request from a fork
cannot, and neither can a workflow on any other branch.

```sh
aws iam put-role-policy --role-name "$PROJECT-github-deploy" \
  --policy-name deploy \
  --policy-document "$(jq -n \
      --arg acct "$ACCOUNT_ID" --arg region "$AWS_REGION" --arg tag "$INSTANCE_NAME" '{
    Version: "2012-10-17",
    Statement: [
      { Sid: "EcrLogin", Effect: "Allow",
        Action: "ecr:GetAuthorizationToken", Resource: "*" },
      { Sid: "EcrPush", Effect: "Allow",
        Action: ["ecr:BatchCheckLayerAvailability","ecr:CompleteLayerUpload",
                 "ecr:InitiateLayerUpload","ecr:PutImage","ecr:UploadLayerPart",
                 "ecr:BatchGetImage","ecr:GetDownloadUrlForLayer"],
        Resource: "arn:aws:ecr:\($region):\($acct):repository/smart-access-control" },
      { Sid: "RunTheDeployScript", Effect: "Allow",
        Action: "ssm:SendCommand",
        Resource: [
          "arn:aws:ssm:\($region)::document/AWS-RunShellScript",
          "arn:aws:ec2:\($region):\($acct):instance/*" ],
        Condition: { StringEquals: { "ssm:resourceTag/Name": $tag } } },
      { Sid: "ReadTheResult", Effect: "Allow",
        Action: ["ssm:ListCommandInvocations","ssm:GetCommandInvocation","ssm:ListCommands"],
        Resource: "*" }
    ]
  }')"

export GH_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-github-deploy
echo "$GH_ROLE_ARN"
```

`ssm:SendCommand` is scoped by instance tag and to one document, so the role can
run `aws/deploy.sh` on this instance and nothing else anywhere.

---

## Part 6 — The instance

### 6.1 Security group

```sh
export VPC_ID=$(aws ec2 describe-vpcs --filters Name=isDefault,Values=true \
  --query 'Vpcs[0].VpcId' --output text)

export SUBNET_ID=$(aws ec2 describe-subnets --filters "Name=vpc-id,Values=$VPC_ID" \
  --query 'Subnets[0].SubnetId' --output text)

export SG_ID=$(aws ec2 create-security-group \
  --group-name "$PROJECT-sg" \
  --description "Smart Access Control - public web only" \
  --vpc-id "$VPC_ID" --query 'GroupId' --output text)

aws ec2 authorize-security-group-ingress --group-id "$SG_ID" \
  --ip-permissions \
    'IpProtocol=tcp,FromPort=80,ToPort=80,IpRanges=[{CidrIp=0.0.0.0/0,Description="HTTP"}]' \
    'IpProtocol=tcp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0,Description="HTTPS"}]' \
    'IpProtocol=udp,FromPort=443,ToPort=443,IpRanges=[{CidrIp=0.0.0.0/0,Description="HTTP/3"}]'

echo "$VPC_ID $SUBNET_ID $SG_ID"
```

**No port 22.** You get a shell through SSM Session Manager, which works over
the instance's outbound connection. Nothing listens for inbound SSH, so nothing
can brute-force it, and there is no key pair to lose.

### 6.2 User data

[aws/ec2-bootstrap.sh](../../aws/ec2-bootstrap.sh) installs Docker and the
Compose plugin, creates the swap file, clones this repository for its compose
file and configuration, installs the nightly backup timer, and turns on
unattended security patching. Fill in the bucket name first:

```sh
sed -e "s|^AWS_REGION=.*|AWS_REGION=$AWS_REGION|" \
    -e "s|^BACKUP_BUCKET=.*|BACKUP_BUCKET=$BACKUP_BUCKET|" \
    -e "s|^GIT_REMOTE=.*|GIT_REMOTE=https://github.com/$GH_REPO.git|" \
    aws/ec2-bootstrap.sh > /tmp/user-data.sh

grep -E '^(AWS_REGION|ECR_REPO|SSM_ENV_PARAM|BACKUP_BUCKET|GIT_REMOTE)=' /tmp/user-data.sh
```

### 6.3 Launch

```sh
# Latest Amazon Linux 2023 for arm64, from the public SSM parameter.
export AMI_ID=$(aws ssm get-parameter \
  --name /aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64 \
  --query 'Parameter.Value' --output text)
echo "$AMI_ID"

export INSTANCE_ID=$(aws ec2 run-instances \
  --image-id "$AMI_ID" \
  --instance-type t4g.small \
  --subnet-id "$SUBNET_ID" \
  --security-group-ids "$SG_ID" \
  --iam-instance-profile "Name=$PROJECT-instance" \
  --user-data "file:///tmp/user-data.sh" \
  --metadata-options "HttpTokens=required,HttpEndpoint=enabled" \
  --block-device-mappings '[{
      "DeviceName": "/dev/xvda",
      "Ebs": { "VolumeSize": 30, "VolumeType": "gp3", "Encrypted": true,
               "DeleteOnTermination": true }
  }]' \
  --credit-specification "CpuCredits=standard" \
  --tag-specifications \
      "ResourceType=instance,Tags=[{Key=Name,Value=$INSTANCE_NAME},{Key=Backup,Value=daily}]" \
      "ResourceType=volume,Tags=[{Key=Name,Value=$INSTANCE_NAME},{Key=Backup,Value=daily}]" \
  --query 'Instances[0].InstanceId' --output text)

echo "$INSTANCE_ID"
aws ec2 wait instance-running --instance-ids "$INSTANCE_ID"

aws ec2 associate-address --instance-id "$INSTANCE_ID" --allocation-id "$EIP_ALLOC"
echo "http://$PUBLIC_IP"
```

Two flags worth explaining:

- **`HttpTokens=required`** forces IMDSv2. Without it, a server-side request
  forgery in any part of the application can read the instance's IAM
  credentials. On a box holding door-access data this is not optional.
- **`CpuCredits=standard`** keeps the instance on baseline CPU credits rather
  than unlimited. `t4g.small` has a 20% baseline, which this workload sits well
  under; `unlimited` would silently bill for surplus credits if something ever
  spun. Switch it if you find yourself throttled.

Watch the bootstrap finish — it takes two or three minutes:

```sh
# Wait for SSM to register the instance, then read the cloud-init log.
until aws ssm describe-instance-information \
  --filters "Key=InstanceIds,Values=$INSTANCE_ID" \
  --query 'InstanceInformationList[0].PingStatus' --output text 2>/dev/null \
  | grep -q Online; do echo "waiting for SSM agent..."; sleep 10; done

aws ssm start-session --target "$INSTANCE_ID"
```

In the session:

```sh
sudo tail -30 /var/log/cloud-init-output.log     # ends: "bootstrap complete"
docker --version && docker compose version
free -m                                           # 2 GB swap present
cat /etc/smart-access.conf
exit
```

---

## Part 7 — First deployment, by hand

Do this once manually. It proves the box before CI touches it.

### 7.1 Build and push

The instance is Graviton, so the image must be `linux/arm64`. From your x86
workstation that means one emulated layer — the PHP extension build — while the
front-end stage compiles natively, because
[docker/Dockerfile](../../docker/Dockerfile) pins it to `$BUILDPLATFORM`.

```sh
docker run --privileged --rm tonistiigi/binfmt --install arm64

aws ecr get-login-password --region "$AWS_REGION" \
  | docker login --username AWS --password-stdin "${ECR_URI%/*}"

export SHA=$(git rev-parse HEAD)

docker buildx build \
  --platform linux/arm64 \
  --target prod \
  -f docker/Dockerfile \
  -t "$ECR_URI:$SHA" \
  -t "$ECR_URI:prod" \
  --provenance false \
  --push .
```

> Expect five to fifteen minutes the first time. Once CI is running
> (Part 8) this happens on a native arm64 runner in two or three, and you will
> not need to build locally again.

#### If `docker login` fails with `error storing credentials`

A message mentioning `gpg: ... Unusable public key` and
`Password encryption aborted` is not an AWS problem. Docker is configured with
a `credsStore` that encrypts saved credentials with GPG (`credsStore: desktop`
or `pass` in `~/.docker/config.json`), and the key backing it has expired.
Check:

```sh
gpg --list-keys --with-colons "$(cat ~/.password-store/.gpg-id)" \
  | awk -F: '/^pub|^sub/ {print $1, "caps="$12, "expires="$7}'
```

An `expires` timestamp in the past on either line is the cause — gpg refuses to
encrypt to an expired key. Extend both the primary key and its encryption
subkey:

```sh
KEY=$(cat ~/.password-store/.gpg-id)
gpg --quick-set-expire "$KEY" 1y
gpg --quick-set-expire "$KEY" 1y '*'     # the subkeys, which is the one that encrypts
```

**Or skip `docker login` altogether**, which is the better answer for ECR. The
Amazon ECR credential helper mints a fresh token from your AWS credentials on
every push, so no password is ever stored and GPG never enters the picture:

```sh
sudo apt install -y amazon-ecr-credential-helper      # Debian/Ubuntu/Mint
which docker-credential-ecr-login

jq --arg reg "${ECR_URI%/*}" \
   '.credHelpers = ((.credHelpers // {}) + {($reg): "ecr-login"})' \
   ~/.docker/config.json > /tmp/dc.json && mv /tmp/dc.json ~/.docker/config.json
```

That registers the helper for this one registry and leaves `credsStore` alone
for every other one. Now `docker buildx build --push` authenticates by itself,
and the `docker login` line above can be dropped.

### 7.2 Deploy

```sh
aws ssm send-command \
  --document-name AWS-RunShellScript \
  --targets "Key=tag:Name,Values=$INSTANCE_NAME" \
  --comment "first deploy" \
  --timeout-seconds 900 \
  --parameters "commands=['sudo /opt/smart-access/aws/deploy.sh $SHA']" \
  --query 'Command.CommandId' --output text
```

Follow it:

```sh
export CMD_ID=<the id printed above>

aws ssm list-command-invocations --command-id "$CMD_ID" --details \
  --query 'CommandInvocations[0].{Status:Status,Output:CommandPlugins[0].Output}' \
  --output text
```

[aws/deploy.sh](../../aws/deploy.sh) checks out the commit (for the compose file
and the mounted configuration — **not** the application code, which is in the
image), fetches the `.env` from SSM, refuses to continue if it has no `APP_KEY`,
pulls the image, starts the stack, and waits for `/up` to answer before
reporting success.

```sh
curl -i "http://$PUBLIC_IP/up"
```

### 7.3 Seed roles and create the first operator

The 22 migrations create the schema but **no roles and no users**. Without the
roles nobody can be granted anything.

```sh
aws ssm start-session --target "$INSTANCE_ID"
```

```sh
cd /opt/smart-access
sudo docker compose --env-file compose.env -f docker-compose.aws.yml \
  exec -e HOME=/tmp app php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

> Run **only** that seeder. The default `DatabaseSeeder` also calls
> `DemoTenantSeeder`, which uses model factories — and `fakerphp/faker` is a dev
> dependency the production image does not contain, so it would fail. You also
> do not want demo accounts with the password `password` on a public IP.

Now your tenant and super-admin:

```sh
sudo docker compose --env-file compose.env -f docker-compose.aws.yml \
  exec -e HOME=/tmp app php artisan tinker
```

```php
$tenant = App\Models\Tenant::create([
    'name'   => 'Head Office',
    'slug'   => 'head-office',
    'status' => 'active',
]);

$admin = App\Models\User::create([
    'tenant_id' => null,          // super_admin operates across tenants
    'name'      => 'Lilan Manoj',
    'email'     => 'you@example.com',
    'password'  => 'change-this-now',   // hashed by the model cast
    'status'    => 'active',
]);
$admin->assignRole('super_admin');

$admin->uuid;   // confirms it saved
```

`uuid` is generated automatically and `password` is hashed by the model's
`casts()`, so pass the plaintext rather than a hash. `HOME=/tmp` is required —
`tinker` wants a writable home directory and the container runs as `www-data`.

### 7.4 Sign in

Open `http://$PUBLIC_IP` and log in. `super_admin` is one of the roles that
**must** set up two-factor authentication before it can do anything — scan the
QR with any authenticator app and keep the recovery codes, because they are
shown exactly once.

Check the memory budget while you are there:

```sh
sudo docker stats --no-stream
free -m
```

---

## Part 8 — The CI/CD pipeline

### 8.1 Branch and repository variables

```sh
git checkout -b prod && git push -u origin prod
```

GitHub → Settings → Secrets and variables → Actions → **Variables** (these are
identifiers, not secrets — nothing here needs encrypting):

| Variable | Value |
|---|---|
| `AWS_REGION` | `ap-south-1` |
| `AWS_ROLE_ARN` | the `$GH_ROLE_ARN` from Part 5.2 |
| `ECR_REPOSITORY` | `smart-access-control` |
| `INSTANCE_NAME_TAG` | `smart-access-prod` |

Then Settings → Branches → add a rule for `prod` requiring a pull request. That
is what makes "merge to `prod`" the deployment trigger rather than an accident.

### 8.2 The workflow

[.github/workflows/deploy-prod.yml](../../.github/workflows/deploy-prod.yml) is
committed and ready. What it does:

1. Checks out the commit, on `ubuntu-24.04-arm` — a native arm64 runner, free
   for public repositories. Building arm64 under QEMU on an x86 runner works but
   spends ten minutes emulating `npm ci`.
2. Exchanges GitHub's OIDC token for the AWS role. No access keys anywhere.
3. Builds the `prod` target and pushes it tagged with the commit SHA and `prod`,
   caching layers in GitHub's cache.
4. Calls `ssm send-command` to run `aws/deploy.sh <sha>` on the instance, polls
   until it finishes, and prints the script's output into the workflow log.

Two decisions in it worth being explicit about:

- **`on: push: branches: [prod]`, not `pull_request_target`.** Merging a PR into
  `prod` *is* a push to `prod`, so this fires exactly when you want.
  `pull_request_target` runs the *unmerged* PR with repository credentials
  available to the contributor's branch. On a public repository that is a
  standing invitation to exfiltrate your AWS role.
- **SSM Run Command, not SSH.** No private key in GitHub secrets, no inbound
  port, and every deploy is recorded in the SSM command history with its output.

### 8.3 Rolling back

Each release is an image tag and a commit, and they are the same string:

```sh
aws ssm send-command \
  --document-name AWS-RunShellScript \
  --targets "Key=tag:Name,Values=$INSTANCE_NAME" \
  --parameters "commands=['sudo /opt/smart-access/aws/deploy.sh <previous-sha>']"
```

Mind the one asymmetry: **a rollback does not undo a migration.** Laravel
migrations here are additive, so rolling the code back onto a newer schema
normally works — but if a release drops or renames a column, roll forward with a
fix instead.

---

## Part 9 — Backups

### 9.1 Nightly database dumps

Already running: the bootstrap script installed a systemd timer that calls
[aws/backup-mysql.sh](../../aws/backup-mysql.sh) at 02:40 UTC. It takes a
`--single-transaction` dump (no table locks — `access_events` is being appended
to continuously), compresses it, streams it to S3, and then checks the object's
size so an empty dump fails loudly rather than sitting there looking like a
backup.

Run it once now rather than finding out in six months:

```sh
aws ssm start-session --target "$INSTANCE_ID"
```

```sh
sudo systemctl start smart-access-backup.service
sudo journalctl -u smart-access-backup.service --no-pager -n 20
systemctl list-timers smart-access-backup.timer
exit
```

```sh
aws s3 ls "s3://$BACKUP_BUCKET/mysql/"
```

### 9.2 Daily EBS snapshots

A Data Lifecycle Manager policy snapshots anything tagged `Backup=daily` — which
the launch in Part 6.3 tagged the volume as — and deletes them on schedule, so
retention needs no cron of its own.

```sh
aws iam create-role --role-name AWSDataLifecycleManagerDefaultRole \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "dlm.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }' 2>/dev/null || echo "(role already exists)"

aws iam attach-role-policy --role-name AWSDataLifecycleManagerDefaultRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSDataLifecycleManagerServiceRole

aws dlm create-lifecycle-policy \
  --description "Smart Access Control daily volume snapshots" \
  --state ENABLED \
  --execution-role-arn "arn:aws:iam::$ACCOUNT_ID:role/AWSDataLifecycleManagerDefaultRole" \
  --policy-details "$(jq -n '{
    PolicyType: "EBS_SNAPSHOT_MANAGEMENT",
    ResourceTypes: ["VOLUME"],
    TargetTags: [{ Key: "Backup", Value: "daily" }],
    Schedules: [{
      Name: "daily-7",
      CreateRule: { Interval: 24, IntervalUnit: "HOURS", Times: ["19:00"] },
      RetainRule: { Count: 7 },
      CopyTags: true
    }]
  }')"
```

19:00 UTC is 00:30 in Asia/Colombo — a quiet hour for a door.

### 9.3 Instance auto-recovery

```sh
aws cloudwatch put-metric-alarm \
  --alarm-name "$PROJECT-system-check-failed" \
  --alarm-description "Recover the instance if its host fails" \
  --namespace AWS/EC2 --metric-name StatusCheckFailed_System \
  --dimensions "Name=InstanceId,Value=$INSTANCE_ID" \
  --statistic Maximum --period 60 --evaluation-periods 2 --threshold 1 \
  --comparison-operator GreaterThanOrEqualToThreshold \
  --alarm-actions "arn:aws:automate:$AWS_REGION:ec2:recover"
```

That covers underlying-hardware failure, which is the failure mode a single
instance is most exposed to. It does not cover the application falling over —
`restart: unless-stopped` on every container does that.

---

## Part 10 — Mail and SMS

OTP delivery is the one feature that does not work yet: `MAIL_MAILER=log` writes
the code to the Docker log, and `SMS_DRIVER=null` reports failure honestly.

```sh
aws sesv2 create-email-identity --email-identity access@yourdomain.com
```

Click the verification link. A new SES account is in the **sandbox** and will
only send to verified addresses — request production access in the SES console
before real users depend on it.

Create SMTP credentials in the SES console, then edit the parameter (Part 4.2's
edit recipe) to replace the mail block:

```ini
MAIL_MAILER=smtp
MAIL_HOST=email-smtp.ap-south-1.amazonaws.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=<ses-smtp-username>
MAIL_PASSWORD=<ses-smtp-password>
MAIL_FROM_ADDRESS="access@yourdomain.com"
MAIL_FROM_NAME="Smart Access Control"
```

Redeploy to pick it up.

For SMS, `SMS_DRIVER=http` posts `{to, from, text}` with a bearer token to
`SMS_HTTP_ENDPOINT`. There is no AWS resource to create; if you later pick SNS
or a Sri Lankan gateway it needs a new driver in
[app/Services/Sms](../../app/Services/Sms).

**E-mail and SMS are independent channels here, by design** — e-mail is never a
fallback triggered by an SMS failure. Configuring only one leaves OTP delivery
working through that one.

---

## Part 11 — Later: your domain and HTTPS

This is where the Caddy choice pays off. Your domain is registered at
domain.com, outside AWS, and that is fine — no Route 53, no ACM certificate, no
load balancer listener.

### 1. Point the domain at the Elastic IP

In domain.com's DNS panel, add an **A record**:

```
access    A    <your Elastic IP>    TTL 300
```

An A record works precisely because this is an Elastic IP and not a load
balancer hostname. Wait for it to resolve:

```sh
dig +short access.yourdomain.com      # should print your Elastic IP
```

### 2. Change three settings and redeploy

Edit the SSM parameter (Part 4.2's recipe):

```diff
-SITE_ADDRESS=:80
+SITE_ADDRESS=access.yourdomain.com
 ACME_EMAIL=you@example.com

-APP_URL=http://1.2.3.4
+APP_URL=https://access.yourdomain.com
-SESSION_SECURE_COOKIE=false
+SESSION_SECURE_COOKIE=true
-SANCTUM_STATEFUL_DOMAINS=1.2.3.4
+SANCTUM_STATEFUL_DOMAINS=access.yourdomain.com

-REVERB_HOST=1.2.3.4
-REVERB_PORT=80
-REVERB_SCHEME=http
+REVERB_HOST=access.yourdomain.com
+REVERB_PORT=443
+REVERB_SCHEME=https
```

Leave `REVERB_PUBLISH_*` alone — that is the app containers talking to the
Reverb container on the internal network, which is still plain HTTP on 8080 and
should be.

Then redeploy. On the first request to the new hostname, Caddy obtains a
Let's Encrypt certificate over the HTTP-01 challenge (port 80 is already open),
starts serving HTTPS, redirects HTTP to it, and renews automatically for as long
as it keeps running. The certificates live in the `caddy-data` volume, so they
survive a redeploy; `docker volume rm` would make it request fresh ones.

```sh
curl -I https://access.yourdomain.com/up
```

[bootstrap/app.php](../../bootstrap/app.php#L35) already trusts every proxy, so
Laravel reads Caddy's `X-Forwarded-Proto` and generates `https://` URLs without
any further change.

### 3. Only now point the panels at it

Update the firmware's server URL to `https://access.yourdomain.com`. Until this
point the device API has been carrying `X-Device-Secret` in cleartext, which is
acceptable for your own testing and not for a device on a wall.

---

## Running it day to day

Everything goes through an SSM session:

```sh
aws ssm start-session --target "$INSTANCE_ID"
cd /opt/smart-access
alias dc='sudo docker compose --env-file compose.env -f docker-compose.aws.yml'
```

| | |
|---|---|
| What is running | `dc ps` |
| Logs, all services | `dc logs -f --tail 100` |
| Logs, one service | `dc logs -f app` |
| Memory per container | `sudo docker stats --no-stream` |
| An artisan command | `dc exec -e HOME=/tmp app php artisan <cmd>` |
| Queue depth | `dc exec redis redis-cli llen queues:events` |
| Restart one role | `dc restart queue` |
| MySQL shell | `dc exec mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" smart_access'` |
| Backup now | `sudo systemctl start smart-access-backup.service` |

One piece of housekeeping the pipeline deliberately does not do: `deploy.sh`
pulls only the application image, so `caddy`, `mysql` and `redis` stay at
whatever patch version was first pulled. That is the right default — you do not
want an unannounced MySQL minor upgrade in the middle of a code deploy — but it
does mean doing it yourself now and then, after a backup:

```sh
sudo systemctl start smart-access-backup.service
dc pull caddy redis mysql && dc up -d
```

## Troubleshooting

**The deploy script fails at `/up never answered`.** It prints the last 80 lines
of the app and Caddy logs before exiting, which is usually enough. Otherwise
`dc logs app`. The entrypoint is loud about why it quits:

| Log line | Cause |
|---|---|
| `FATAL: APP_KEY is not set` | The SSM parameter is missing or truncated. `aws ssm get-parameter --name /smart-access/prod/env --with-decryption` and check it. |
| `waiting for database (30/30)` | MySQL did not become healthy. `dc logs mysql` — on a first boot it is usually a bad `MYSQL_*` value in the parameter. |
| `exec format error` | An amd64 image on a Graviton instance. Rebuild with `--platform linux/arm64`. |

**MySQL is killed repeatedly, or the box becomes unresponsive.** Memory.
`free -m` and `sudo docker stats --no-stream`, then `dmesg | grep -i oom`. Check
the three tuning mounts actually applied:

```sh
dc exec app php -i | grep -E 'opcache.memory_consumption|memory_limit'   # 64, 256M
dc exec mysql sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SELECT @@innodb_buffer_pool_size, @@performance_schema"'
```

If they are the image defaults, the bind mounts are not in place — confirm the
checkout at `/opt/smart-access` is the commit you deployed. If they applied and
it still thrashes, move to `t4g.medium`.

**Login succeeds, then every dashboard call returns 401.**
`SANCTUM_STATEFUL_DOMAINS` does not match the host in the address bar.

**Login fails with 419.** The session cookie is not coming back — almost always
`SESSION_SECURE_COOKIE=true` while still on plain HTTP.

**The dashboard shows "polling" instead of a live feed.** The SPA falls back to
polling and says so when realtime is unavailable. Check, in order: `dc ps reverb`
is up; `REVERB_APP_KEY` is set (a null key disables realtime deliberately);
`REVERB_HOST`/`PORT`/`SCHEME` match what the browser uses; Caddy's `@reverb`
matcher is intact.

**A broadcast never reaches the browser although the socket is connected.** That
is the publish direction, not the subscribe direction: check
`REVERB_PUBLISH_HOST=reverb` and port 8080.

**Caddy will not get a certificate.** `dc logs caddy`. Nearly always DNS not yet
resolving to the Elastic IP, or `SITE_ADDRESS` still `:80`. Let's Encrypt rate
limits failed attempts, so fix the cause before retrying.

**Deploys started failing after a while with no disk space.**
`docker system df`. `deploy.sh` prunes images older than 72 hours, but a stopped
container or dangling volume can accumulate: `sudo docker system prune -f`
(which does **not** touch named volumes, so your database is safe).

### Recovering your shell variables

```sh
export AWS_REGION=ap-south-1
export AWS_PAGER=""
export PROJECT=smart-access
export INSTANCE_NAME=smart-access-prod
export GH_REPO=lilanmanoj/smart_access_control_web
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export BACKUP_BUCKET=$PROJECT-backups-$ACCOUNT_ID
export ECR_URI=$(aws ecr describe-repositories --repository-names smart-access-control --query 'repositories[0].repositoryUri' --output text)
export INSTANCE_ID=$(aws ec2 describe-instances --filters "Name=tag:Name,Values=$INSTANCE_NAME" "Name=instance-state-name,Values=running" --query 'Reservations[0].Instances[0].InstanceId' --output text)
export PUBLIC_IP=$(aws ec2 describe-instances --instance-ids "$INSTANCE_ID" --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)
export EIP_ALLOC=$(aws ec2 describe-addresses --filters "Name=tag:Name,Values=$INSTANCE_NAME" --query 'Addresses[0].AllocationId' --output text)
export VPC_ID=$(aws ec2 describe-vpcs --filters Name=isDefault,Values=true --query 'Vpcs[0].VpcId' --output text)
export SG_ID=$(aws ec2 describe-security-groups --filters "Name=group-name,Values=$PROJECT-sg" --query 'SecurityGroups[0].GroupId' --output text)
export GH_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-github-deploy
echo "http://$PUBLIC_IP  $INSTANCE_ID"
```

Secrets are only in Parameter Store:

```sh
aws ssm get-parameter --name /smart-access/prod/env --with-decryption \
  --query Parameter.Value --output text
```

---

## Rebuilding from nothing

Worth reading once before you need it. Given the SSM parameter and a backup,
a replacement instance is about fifteen minutes:

1. Re-run Part 6 (security group already exists — just the launch).
2. `aws ec2 associate-address` moves the Elastic IP, so the address and your DNS
   do not change.
3. Deploy the last known-good SHA (Part 7.2). The stack comes up with an empty
   database and the migrations run.
4. Restore the most recent dump:

```sh
aws s3 ls "s3://$BACKUP_BUCKET/mysql/" | tail -5

aws ssm start-session --target "$INSTANCE_ID"
cd /opt/smart-access
aws s3 cp "s3://<bucket>/mysql/<file>" - | gunzip \
  | sudo docker compose --env-file compose.env -f docker-compose.aws.yml \
      exec -T mysql sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" smart_access'
```

Or, if the volume itself survived, restore the EBS snapshot and attach it
instead — faster, and it keeps the Redis state too.

Note what is **not** in any backup and does not need to be: the fingerprint
templates in each panel's sensor flash. They survive reflashes and the backend
only records which slot belongs to whom. A restored database knows the
enrolments; the sensors still hold the templates.

## Teardown

```sh
aws ec2 terminate-instances --instance-ids "$INSTANCE_ID"
aws ec2 wait instance-terminated --instance-ids "$INSTANCE_ID"
aws ec2 release-address --allocation-id "$EIP_ALLOC"
aws ec2 delete-security-group --group-id "$SG_ID"

aws cloudwatch delete-alarms --alarm-names "$PROJECT-system-check-failed"
aws dlm get-lifecycle-policies --query 'Policies[].PolicyId'   # then delete-lifecycle-policy
aws ecr delete-repository --repository-name smart-access-control --force
aws s3 rb "s3://$BACKUP_BUCKET" --force
aws ssm delete-parameter --name /smart-access/prod/env

aws iam remove-role-from-instance-profile --instance-profile-name "$PROJECT-instance" --role-name "$PROJECT-instance"
aws iam delete-instance-profile --instance-profile-name "$PROJECT-instance"
# then detach policies and delete the two IAM roles
```

Keep your copy of `APP_KEY` if there is any chance of restoring a backup —
without it, every `two_factor_secret` in it is unreadable.
