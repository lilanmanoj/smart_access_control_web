# Deploying Smart Access Control to AWS (ECS Fargate)

> **This is the managed-infrastructure option, at roughly $120/month.** For a
> deployment of this size the single-instance arrangement in
> [aws-ec2.md](aws-ec2.md) does the same job for about $20 and is the
> recommended starting point. Come here when one box stops being enough —
> when you want rolling deploys with no gap, no host to patch, or more than one
> web task. The RDS, SES and certificate sections are worth reading either way.

Target: the application running on ECS Fargate behind an Application Load
Balancer, reachable over the ALB's public address, with MySQL on RDS and Redis
on ElastiCache, deployed automatically when a pull request merges into `prod`.

This runbook builds that from an empty AWS account. Work through it in order —
later steps need IDs produced by earlier ones. Expect 60–90 minutes, most of it
waiting for RDS.

TLS and your `domain.com` domain are [the last section](#part-14--later-your-domain-and-https).
Until then the stack serves plain HTTP on the ALB address, which is fine for
your own testing and **not** fine for real door panels — their device secret
travels in a request header.

---

## Part 0 — What this application actually needs

### How it is built

One Docker image serves four roles, selected at runtime by `CONTAINER_ROLE`
(see [docker/entrypoint.sh](../../docker/entrypoint.sh)):

| Role | Process | Listens | Needs |
|---|---|---|---|
| `app` | nginx + PHP-FPM | `:80` | MySQL, Redis |
| `queue` | `queue:work` | — | MySQL, Redis |
| `scheduler` | `schedule:work` | — | MySQL, Redis |
| `reverb` | `reverb:start` | `:8080` | Redis |

So there is **one image to build and push**, and several ECS services that run
it with different environment variables.

### Resource inventory

| AWS resource | Why this application needs it |
|---|---|
| **ECR** repository | Holds the image the pipeline builds. |
| **VPC**, 2 public + 2 private subnets | RDS and ElastiCache both require subnet groups spanning two AZs. |
| **4 security groups** | ALB → tasks → database/cache, each a one-way door. |
| **RDS MySQL 8.4** | `DB_CONNECTION=mysql`. The schema needs MySQL 8 specifically: the enrolments unique index relies on MySQL treating NULLs as distinct, and the circular `access_events` ⇄ `backup_codes` foreign key is added to an existing table. |
| **ElastiCache Redis** | `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` are all Redis. Sessions in Redis is what lets the web service scale past one task without sticky sessions. |
| **ALB** | Two listener targets: the app on `:80`, and Reverb on `:8080` for the `/app` and `/apps` paths. ALB speaks WebSocket natively. |
| **ECS cluster + 3 services** | `sac-app` (web), `sac-reverb` (websockets), `sac-worker` (queue + scheduler containers in one task). |
| **Secrets Manager** secret | `APP_KEY`, the database password and the three Reverb credentials. The container **refuses to start without `APP_KEY`**, deliberately. |
| **CloudWatch Logs** group | The only place container output goes. |
| **IAM**: task execution role, task role, GitHub Actions OIDC role | Pull the image, read the secret, open an ECS Exec shell, and let CI deploy without stored AWS keys. |
| **SES** (later) | OTP e-mail delivery. Start with `MAIL_MAILER=log`. |

### What this application does *not* need

- **No S3, no EFS, no persistent volume.** Nothing is written to disk that has
  to survive a task. Event exports stream straight to the response
  ([AccessEventController::export](../../app/Http/Controllers/Admin/AccessEventController.php#L58)),
  and fingerprint templates are rows in the `fingerprint_templates` table, not
  files — the sensor's own flash is the authoritative copy.
- **No CloudFront, no S3 static hosting.** The React bundle is compiled into
  the image and served by the task's own nginx, same-origin. Nothing about the
  deployment address is baked into the bundle — `SpaController` injects the
  Reverb endpoint at request time, which is exactly why one image works against
  any hostname.
- **No sticky sessions.** Sessions live in Redis.

### Approximate monthly cost (ap-south-1)

| | |
|---|---|
| ALB (+ 2 public IPv4) | ~$26 |
| Fargate: app 0.5 vCPU / 1 GB | ~$18 |
| Fargate: worker 0.5 vCPU / 1 GB | ~$18 |
| Fargate: reverb 0.25 vCPU / 0.5 GB | ~$9 |
| 3 × task public IPv4 | ~$11 |
| RDS `db.t4g.micro`, 20 GB gp3, single-AZ | ~$16 |
| ElastiCache `cache.t4g.micro` | ~$12 |
| ECR, Secrets Manager, CloudWatch Logs | ~$3 |
| **Total** | **~$110–135** |

Two things worth knowing before you commit to it:

- **A single `t4g.small` EC2 instance running the existing
  [docker-compose.prod.yml](../../docker-compose.prod.yml) costs about $15/month
  all-in** and the repo already supports it. For one building's access panels,
  that is the honest recommendation. Fargate buys you zero-downtime rolling
  deploys, no host to patch and no SSH — which is what you asked for, so this
  runbook builds that.
- **There is no NAT gateway here.** Tasks sit in public subnets with public IPs
  so they can reach ECR and Secrets Manager directly. Their security group
  accepts inbound traffic only from the ALB, so they are not reachable from the
  internet. A NAT gateway plus private task subnets is the hardened version and
  costs ~$32/month more per AZ.

---

## Part 1 — Prerequisites and shell variables

Install and authenticate the AWS CLI v2 on your workstation (it does **not**
need to be in the project's Docker stack):

```sh
aws --version            # aws-cli/2.x
aws sts get-caller-identity
```

Everything below uses these variables. **Open one terminal and keep it** — each
step appends to this set. If you lose the terminal, the
[recovery snippet](#recovering-your-shell-variables) at the end re-reads every
ID from AWS.

```sh
export AWS_REGION=ap-south-1                 # Mumbai — closest to Colombo
export AWS_PAGER=""                          # stop the CLI opening a pager
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export PROJECT=sac                           # prefix for every resource name
export GH_REPO=<your-github-user>/<your-repo>  # e.g. lilanmanoj/smart_access_control_web

echo "$AWS_REGION / $ACCOUNT_ID / $GH_REPO"
```

---

## Part 2 — ECR repository

```sh
aws ecr create-repository \
  --repository-name smart-access-control \
  --image-scanning-configuration scanOnPush=true \
  --image-tag-mutability MUTABLE \
  --encryption-configuration encryptionType=AES256 \
  --region "$AWS_REGION"

export ECR_URI=$(aws ecr describe-repositories \
  --repository-names smart-access-control \
  --query 'repositories[0].repositoryUri' --output text)
echo "$ECR_URI"
```

Add a lifecycle policy so old images do not accumulate:

```sh
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

## Part 3 — Network

### 3.1 VPC

```sh
export VPC_ID=$(aws ec2 create-vpc \
  --cidr-block 10.20.0.0/16 \
  --tag-specifications "ResourceType=vpc,Tags=[{Key=Name,Value=$PROJECT-vpc}]" \
  --query 'Vpc.VpcId' --output text)

aws ec2 modify-vpc-attribute --vpc-id "$VPC_ID" --enable-dns-support '{"Value":true}'
aws ec2 modify-vpc-attribute --vpc-id "$VPC_ID" --enable-dns-hostnames '{"Value":true}'
echo "$VPC_ID"
```

### 3.2 Subnets

Two availability zones, because RDS and ElastiCache subnet groups require it.

```sh
export AZ1=$(aws ec2 describe-availability-zones \
  --query 'AvailabilityZones[0].ZoneName' --output text)
export AZ2=$(aws ec2 describe-availability-zones \
  --query 'AvailabilityZones[1].ZoneName' --output text)
echo "$AZ1 $AZ2"

mk_subnet() {   # name cidr az
  aws ec2 create-subnet --vpc-id "$VPC_ID" --cidr-block "$2" --availability-zone "$3" \
    --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=$1}]" \
    --query 'Subnet.SubnetId' --output text
}

export PUB1=$(mk_subnet "$PROJECT-public-1" 10.20.1.0/24  "$AZ1")
export PUB2=$(mk_subnet "$PROJECT-public-2" 10.20.2.0/24  "$AZ2")
export PRIV1=$(mk_subnet "$PROJECT-private-1" 10.20.11.0/24 "$AZ1")
export PRIV2=$(mk_subnet "$PROJECT-private-2" 10.20.12.0/24 "$AZ2")
echo "$PUB1 $PUB2 $PRIV1 $PRIV2"
```

### 3.3 Internet gateway and public routing

```sh
export IGW_ID=$(aws ec2 create-internet-gateway \
  --tag-specifications "ResourceType=internet-gateway,Tags=[{Key=Name,Value=$PROJECT-igw}]" \
  --query 'InternetGateway.InternetGatewayId' --output text)

aws ec2 attach-internet-gateway --vpc-id "$VPC_ID" --internet-gateway-id "$IGW_ID"

export RT_PUBLIC=$(aws ec2 create-route-table --vpc-id "$VPC_ID" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=$PROJECT-rt-public}]" \
  --query 'RouteTable.RouteTableId' --output text)

aws ec2 create-route --route-table-id "$RT_PUBLIC" \
  --destination-cidr-block 0.0.0.0/0 --gateway-id "$IGW_ID"

for s in "$PUB1" "$PUB2"; do
  aws ec2 associate-route-table --route-table-id "$RT_PUBLIC" --subnet-id "$s"
done
```

The private subnets keep the VPC's default route table, which has no route to
the internet. RDS and ElastiCache do not need one.

---

## Part 4 — Security groups

Four groups, each allowing exactly one hop.

```sh
mk_sg() {   # name description
  aws ec2 create-security-group --group-name "$1" --description "$2" \
    --vpc-id "$VPC_ID" --query 'GroupId' --output text
}

export SG_ALB=$(mk_sg   "$PROJECT-alb-sg"   "Public entrance")
export SG_APP=$(mk_sg   "$PROJECT-app-sg"   "Fargate tasks")
export SG_RDS=$(mk_sg   "$PROJECT-rds-sg"   "MySQL")
export SG_REDIS=$(mk_sg "$PROJECT-redis-sg" "Redis")
echo "$SG_ALB $SG_APP $SG_RDS $SG_REDIS"
```

```sh
# Internet -> ALB on 80. (Port 443 is added in Part 14, with the certificate.)
aws ec2 authorize-security-group-ingress --group-id "$SG_ALB" \
  --protocol tcp --port 80 --cidr 0.0.0.0/0

# ALB -> app tasks on 80 (nginx) and 8080 (Reverb). Nothing else reaches them.
aws ec2 authorize-security-group-ingress --group-id "$SG_APP" \
  --protocol tcp --port 80 --source-group "$SG_ALB"
aws ec2 authorize-security-group-ingress --group-id "$SG_APP" \
  --protocol tcp --port 8080 --source-group "$SG_ALB"

# app tasks -> MySQL / Redis.
aws ec2 authorize-security-group-ingress --group-id "$SG_RDS" \
  --protocol tcp --port 3306 --source-group "$SG_APP"
aws ec2 authorize-security-group-ingress --group-id "$SG_REDIS" \
  --protocol tcp --port 6379 --source-group "$SG_APP"
```

Outbound is left at the default allow-all: tasks need to reach ECR, Secrets
Manager, CloudWatch and SES.

---

## Part 5 — RDS MySQL

RDS takes 10–15 minutes to come up, so start it now and continue with Part 6
while it provisions.

```sh
aws rds create-db-subnet-group \
  --db-subnet-group-name "$PROJECT-db-subnets" \
  --db-subnet-group-description "Smart Access Control private subnets" \
  --subnet-ids "$PRIV1" "$PRIV2"
```

Generate and keep the master password — you will paste it into Secrets Manager
in Part 7:

```sh
export DB_PASSWORD=$(openssl rand -base64 30 | tr -d '/@" ' | cut -c1-28)
echo "DB_PASSWORD=$DB_PASSWORD"      # write this down
```

> RDS forbids `/`, `@`, `"` and spaces in a master password; the `tr` above
> strips them.

Check which MySQL 8.4 versions your region offers, then create the instance:

```sh
aws rds describe-db-engine-versions --engine mysql \
  --query 'DBEngineVersions[?starts_with(EngineVersion, `8.4`)].EngineVersion' --output text

aws rds create-db-instance \
  --db-instance-identifier "$PROJECT-mysql" \
  --db-instance-class db.t4g.micro \
  --engine mysql \
  --engine-version 8.4 \
  --master-username sacadmin \
  --master-user-password "$DB_PASSWORD" \
  --allocated-storage 20 \
  --storage-type gp3 \
  --storage-encrypted \
  --db-name smart_access \
  --db-subnet-group-name "$PROJECT-db-subnets" \
  --vpc-security-group-ids "$SG_RDS" \
  --no-publicly-accessible \
  --no-multi-az \
  --backup-retention-period 7 \
  --preferred-backup-window 20:00-20:30 \
  --copy-tags-to-snapshot \
  --auto-minor-version-upgrade \
  --deletion-protection
```

Notes on these choices:

- **MySQL 8.4** matches the `mysql:8.4` image the development stack and the test
  suite run against. 8.0 also works; a different engine entirely does not.
- **`--db-name smart_access`** creates the database, so no manual `CREATE
  DATABASE` step. The master user has every privilege the 22 migrations need.
- **`--no-multi-az`** halves the cost and is the right call for a first
  deployment. Switch it on later with `modify-db-instance --multi-az`; it is a
  no-downtime change.
- **`--deletion-protection`** will block a teardown until you turn it off. That
  is the point.

When it reports `available`, capture the endpoint:

```sh
aws rds wait db-instance-available --db-instance-identifier "$PROJECT-mysql"

export DB_HOST=$(aws rds describe-db-instances \
  --db-instance-identifier "$PROJECT-mysql" \
  --query 'DBInstances[0].Endpoint.Address' --output text)
echo "$DB_HOST"
```

---

## Part 6 — ElastiCache Redis

```sh
aws elasticache create-cache-subnet-group \
  --cache-subnet-group-name "$PROJECT-redis-subnets" \
  --cache-subnet-group-description "Smart Access Control private subnets" \
  --subnet-ids "$PRIV1" "$PRIV2"

aws elasticache create-cache-cluster \
  --cache-cluster-id "$PROJECT-redis" \
  --engine redis \
  --engine-version 7.1 \
  --cache-node-type cache.t4g.micro \
  --num-cache-nodes 1 \
  --cache-subnet-group-name "$PROJECT-redis-subnets" \
  --security-group-ids "$SG_REDIS" \
  --preferred-availability-zone "$AZ1"

aws elasticache wait cache-cluster-available --cache-cluster-id "$PROJECT-redis"

export REDIS_HOST=$(aws elasticache describe-cache-clusters \
  --cache-cluster-id "$PROJECT-redis" --show-cache-node-info \
  --query 'CacheClusters[0].CacheNodes[0].Endpoint.Address' --output text)
echo "$REDIS_HOST"
```

**Why a plain single-node cluster rather than ElastiCache Serverless:**
Serverless mandates TLS. This application can do TLS to Redis — Laravel maps a
`tls://` URL scheme onto phpredis, so `REDIS_URL=tls://host:6379` works — but
Reverb's own Redis client (used only when `REVERB_SCALING_ENABLED=true`) does
not accept that scheme. A single node in a private subnet behind
`$SG_REDIS` is simpler, cheaper, and keeps the option of scaling Reverb later.
If you do want TLS, create a replication group with
`--transit-encryption-enabled`, set `REDIS_URL=tls://<endpoint>:6379`, and leave
Reverb at one task.

> You could drop ElastiCache entirely by switching `CACHE_STORE`,
> `QUEUE_CONNECTION` and `SESSION_DRIVER` to `database`, saving ~$12/month. It
> also makes the `access_events` table carry your queue traffic, and moves
> sessions into MySQL. Not recommended, but it is a real option if cost is
> tight.

---

## Part 7 — Secrets

Five values must never appear in a task definition, a log or the repository.
Generate them:

```sh
export APP_KEY="base64:$(openssl rand -base64 32)"
export REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)
export REVERB_APP_KEY=$(openssl rand -hex 16)
export REVERB_APP_SECRET=$(openssl rand -hex 32)

cat <<EOF
APP_KEY=$APP_KEY
REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
EOF
```

> **`APP_KEY` is not regenerable.** It encrypts the `two_factor_secret` and
> `two_factor_recovery_codes` columns. Change it and every operator's
> authenticator app stops working, with no way back. Store it somewhere you
> will still have in a year.

Put them in one Secrets Manager secret — ECS can pull individual JSON keys out
of it, so one secret covers every task definition:

```sh
export SECRET_ARN=$(aws secretsmanager create-secret \
  --name "$PROJECT/prod" \
  --description "Smart Access Control production secrets" \
  --secret-string "$(jq -n \
      --arg app_key        "$APP_KEY" \
      --arg db_password    "$DB_PASSWORD" \
      --arg reverb_app_id  "$REVERB_APP_ID" \
      --arg reverb_app_key "$REVERB_APP_KEY" \
      --arg reverb_secret  "$REVERB_APP_SECRET" \
      '{APP_KEY:$app_key, DB_PASSWORD:$db_password,
        REVERB_APP_ID:$reverb_app_id, REVERB_APP_KEY:$reverb_app_key,
        REVERB_APP_SECRET:$reverb_secret,
        MAIL_PASSWORD:"", SMS_HTTP_TOKEN:""}')" \
  --query 'ARN' --output text)
echo "$SECRET_ARN"
```

`MAIL_PASSWORD` and `SMS_HTTP_TOKEN` are placeholders so the key paths exist;
fill them in Part 13 when you wire up SES.

---

## Part 8 — CloudWatch log group

```sh
aws logs create-log-group --log-group-name "/ecs/$PROJECT"
aws logs put-retention-policy --log-group-name "/ecs/$PROJECT" --retention-in-days 14
```

---

## Part 9 — IAM roles

### 9.1 Task execution role — pulls the image, reads the secret

```sh
aws iam create-role --role-name "$PROJECT-task-execution" \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

aws iam attach-role-policy --role-name "$PROJECT-task-execution" \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy

aws iam put-role-policy --role-name "$PROJECT-task-execution" \
  --policy-name read-secrets \
  --policy-document "$(jq -n --arg arn "$SECRET_ARN" '{
    Version: "2012-10-17",
    Statement: [{
      Effect: "Allow",
      Action: ["secretsmanager:GetSecretValue"],
      Resource: [$arn]
    }]
  }')"

export EXEC_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-task-execution
```

### 9.2 Task role — what the application itself may call

The application makes no AWS API calls today, but it needs ECS Exec so you can
run `artisan` inside a live task (seeding roles, creating the first operator,
`tinker`). Later it needs SES for OTP e-mail.

```sh
aws iam create-role --role-name "$PROJECT-task" \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

aws iam put-role-policy --role-name "$PROJECT-task" \
  --policy-name exec-and-mail \
  --policy-document '{
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Action": [
          "ssmmessages:CreateControlChannel",
          "ssmmessages:CreateDataChannel",
          "ssmmessages:OpenControlChannel",
          "ssmmessages:OpenDataChannel"
        ],
        "Resource": "*"
      },
      {
        "Effect": "Allow",
        "Action": ["ses:SendEmail", "ses:SendRawEmail"],
        "Resource": "*"
      }
    ]
  }'

export TASK_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-task
```

### 9.3 GitHub Actions role — deploys without stored AWS keys

Register GitHub as an OIDC identity provider (once per account):

```sh
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1
```

If it already exists, the call fails with `EntityAlreadyExists` — carry on.

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

The `sub` condition is the security boundary: **only workflows running on the
`prod` branch of that one repository can assume this role.** A workflow on any
other branch, or on a fork, gets nothing.

```sh
aws iam put-role-policy --role-name "$PROJECT-github-deploy" \
  --policy-name deploy \
  --policy-document "$(jq -n \
      --arg acct "$ACCOUNT_ID" --arg region "$AWS_REGION" --arg project "$PROJECT" '{
    Version: "2012-10-17",
    Statement: [
      { Effect: "Allow", Action: "ecr:GetAuthorizationToken", Resource: "*" },
      { Effect: "Allow",
        Action: ["ecr:BatchCheckLayerAvailability","ecr:CompleteLayerUpload",
                 "ecr:InitiateLayerUpload","ecr:PutImage","ecr:UploadLayerPart",
                 "ecr:BatchGetImage","ecr:GetDownloadUrlForLayer"],
        Resource: "arn:aws:ecr:\($region):\($acct):repository/smart-access-control" },
      { Effect: "Allow",
        Action: ["ecs:DescribeServices","ecs:DescribeTaskDefinition","ecs:DescribeTasks",
                 "ecs:RegisterTaskDefinition","ecs:UpdateService","ecs:ListTasks","ecs:RunTask"],
        Resource: "*" },
      { Effect: "Allow", Action: "iam:PassRole", Resource: [
          "arn:aws:iam::\($acct):role/\($project)-task-execution",
          "arn:aws:iam::\($acct):role/\($project)-task" ] }
    ]
  }')"

export GH_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-github-deploy
echo "$GH_ROLE_ARN"
```

---

## Part 10 — Load balancer

### 10.1 ALB and target groups

```sh
export ALB_ARN=$(aws elbv2 create-load-balancer \
  --name "$PROJECT-alb" \
  --subnets "$PUB1" "$PUB2" \
  --security-groups "$SG_ALB" \
  --scheme internet-facing --type application --ip-address-type ipv4 \
  --query 'LoadBalancers[0].LoadBalancerArn' --output text)

export ALB_DNS=$(aws elbv2 describe-load-balancers --load-balancer-arns "$ALB_ARN" \
  --query 'LoadBalancers[0].DNSName' --output text)
echo "http://$ALB_DNS"
```

**`$ALB_DNS` is your public address.** Write it down; it goes into `APP_URL`,
`SANCTUM_STATEFUL_DOMAINS` and `REVERB_HOST` in the next part.

Raise the idle timeout — the default 60 seconds is below Reverb's WebSocket ping
interval and would drop live connections:

```sh
aws elbv2 modify-load-balancer-attributes --load-balancer-arn "$ALB_ARN" \
  --attributes Key=idle_timeout.timeout_seconds,Value=300
```

```sh
export TG_APP=$(aws elbv2 create-target-group \
  --name "$PROJECT-app-tg" --protocol HTTP --port 80 --vpc-id "$VPC_ID" \
  --target-type ip \
  --health-check-protocol HTTP --health-check-path /up \
  --health-check-interval-seconds 30 --health-check-timeout-seconds 5 \
  --healthy-threshold-count 2 --unhealthy-threshold-count 3 \
  --matcher HttpCode=200 \
  --query 'TargetGroups[0].TargetGroupArn' --output text)

export TG_REVERB=$(aws elbv2 create-target-group \
  --name "$PROJECT-reverb-tg" --protocol HTTP --port 8080 --vpc-id "$VPC_ID" \
  --target-type ip \
  --health-check-protocol HTTP --health-check-path / \
  --health-check-interval-seconds 30 --health-check-timeout-seconds 5 \
  --healthy-threshold-count 2 --unhealthy-threshold-count 3 \
  --matcher HttpCode=200-499 \
  --query 'TargetGroups[0].TargetGroupArn' --output text)

for tg in "$TG_APP" "$TG_REVERB"; do
  aws elbv2 modify-target-group-attributes --target-group-arn "$tg" \
    --attributes Key=deregistration_delay.timeout_seconds,Value=30
done
```

Two details that matter:

- **`/up`** is the health endpoint Laravel registers in
  [bootstrap/app.php](../../bootstrap/app.php#L29). It is unauthenticated, which
  is what a load balancer needs. The device-facing `/api/v1/health` is a
  different, authenticated thing and must not be used here.
- **The Reverb target group accepts `200-499`.** Reverb answers only the
  pusher-protocol paths and returns 404 for `/`. A 404 proves the process is
  alive and listening, which is all the health check needs to know.

### 10.2 Listener and the Reverb path rule

```sh
export LISTENER_ARN=$(aws elbv2 create-listener \
  --load-balancer-arn "$ALB_ARN" --protocol HTTP --port 80 \
  --default-actions "Type=forward,TargetGroupArn=$TG_APP" \
  --query 'Listeners[0].ListenerArn' --output text)

aws elbv2 create-rule --listener-arn "$LISTENER_ARN" --priority 10 \
  --conditions 'Field=path-pattern,Values=/app,/app/*,/apps,/apps/*' \
  --actions "Type=forward,TargetGroupArn=$TG_REVERB"
```

Those four patterns are the whole Reverb surface:

- `/app/{key}` — the WebSocket upgrade the browser's Echo client opens
- `/apps/{id}/events` — the HTTP endpoint the app tasks publish events to

Everything else falls through to the app. This is safe because neither Laravel
nor the React router owns a path under `/app` or `/apps` — the SPA's routes are
`/devices`, `/members`, `/events`, `/backup-codes`, `/schedules`, `/users`,
`/webhooks`, `/audit`, `/security` and `/login`. Use the exact patterns above,
not `/app*`, which would also swallow a future `/application` route.

---

## Part 11 — ECS cluster, task definitions and services

### 11.1 Cluster

```sh
aws ecs create-cluster --cluster-name "$PROJECT-cluster" \
  --capacity-providers FARGATE FARGATE_SPOT \
  --default-capacity-provider-strategy capacityProvider=FARGATE,weight=1
```

### 11.2 The shared environment

Every role gets nearly the same environment. The three task definitions are
generated from one script so they cannot drift apart — it is already in the
repository at [aws/render-taskdefs.sh](../../aws/render-taskdefs.sh), reproduced
here so you can see what it builds:

```sh
#!/usr/bin/env bash
# Renders the three ECS task definitions from the current shell variables.
# Requires: AWS_REGION ACCOUNT_ID PROJECT ECR_URI EXEC_ROLE_ARN TASK_ROLE_ARN
#           SECRET_ARN DB_HOST REDIS_HOST ALB_DNS
set -euo pipefail

: "${IMAGE_TAG:=bootstrap}"
OUT=$(mktemp -d)

common_env() {
cat <<JSON
    { "name": "APP_NAME",   "value": "Smart Access Control" },
    { "name": "APP_ENV",    "value": "production" },
    { "name": "APP_DEBUG",  "value": "false" },
    { "name": "APP_URL",    "value": "http://$ALB_DNS" },
    { "name": "APP_TIMEZONE", "value": "UTC" },

    { "name": "LOG_CHANNEL", "value": "stderr" },
    { "name": "LOG_LEVEL",   "value": "info" },

    { "name": "DB_CONNECTION", "value": "mysql" },
    { "name": "DB_HOST",       "value": "$DB_HOST" },
    { "name": "DB_PORT",       "value": "3306" },
    { "name": "DB_DATABASE",   "value": "smart_access" },
    { "name": "DB_USERNAME",   "value": "sacadmin" },

    { "name": "REDIS_CLIENT", "value": "phpredis" },
    { "name": "REDIS_HOST",   "value": "$REDIS_HOST" },
    { "name": "REDIS_PORT",   "value": "6379" },

    { "name": "CACHE_STORE",          "value": "redis" },
    { "name": "QUEUE_CONNECTION",     "value": "redis" },
    { "name": "SESSION_DRIVER",       "value": "redis" },
    { "name": "SESSION_LIFETIME",     "value": "120" },
    { "name": "SESSION_SECURE_COOKIE", "value": "false" },

    { "name": "SANCTUM_STATEFUL_DOMAINS", "value": "$ALB_DNS" },

    { "name": "BROADCAST_CONNECTION", "value": "reverb" },
    { "name": "REVERB_SERVER_HOST",    "value": "0.0.0.0" },
    { "name": "REVERB_SERVER_PORT",    "value": "8080" },
    { "name": "REVERB_PUBLISH_HOST",   "value": "$ALB_DNS" },
    { "name": "REVERB_PUBLISH_PORT",   "value": "80" },
    { "name": "REVERB_PUBLISH_SCHEME", "value": "http" },
    { "name": "REVERB_HOST",           "value": "$ALB_DNS" },
    { "name": "REVERB_PORT",           "value": "80" },
    { "name": "REVERB_SCHEME",         "value": "http" },

    { "name": "MAIL_MAILER",       "value": "log" },
    { "name": "MAIL_FROM_ADDRESS", "value": "access@example.com" },
    { "name": "SMS_DRIVER",        "value": "null" },
    { "name": "SMS_SENDER_ID",     "value": "ACCESS" },

    { "name": "PHONE_DEFAULT_COUNTRY_CODE", "value": "+94" },
    { "name": "OTP_TTL_SECONDS",            "value": "90" },
    { "name": "OTP_MAX_ATTEMPTS",           "value": "3" },
    { "name": "OTP_RETURN_CODE_TO_DEVICE",  "value": "false" },
    { "name": "OTP_REQUIRE_ENROLLMENT",     "value": "false" },
    { "name": "BACKUP_CODES_ONLINE_ONLY",   "value": "false" },
    { "name": "BACKUP_CODE_MAX_AGE_DAYS",   "value": "0" },
    { "name": "DEVICE_OFFLINE_AFTER_SECONDS", "value": "100" },
    { "name": "DEVICE_COMMAND_TTL_SECONDS",   "value": "300" },
    { "name": "DEVICE_REQUIRE_SIGNATURE",     "value": "false" },
    { "name": "EVENT_DUPLICATE_WINDOW",       "value": "10" },
    { "name": "EVENT_RETENTION_DAYS",         "value": "365" },
    { "name": "ACCESS_SCHEDULES_ENFORCE",     "value": "true" },
    { "name": "ACCESS_SCHEDULE_TIMEZONE",     "value": "Asia/Colombo" }
JSON
}

common_secrets() {
cat <<JSON
    { "name": "APP_KEY",           "valueFrom": "$SECRET_ARN:APP_KEY::" },
    { "name": "DB_PASSWORD",       "valueFrom": "$SECRET_ARN:DB_PASSWORD::" },
    { "name": "REVERB_APP_ID",     "valueFrom": "$SECRET_ARN:REVERB_APP_ID::" },
    { "name": "REVERB_APP_KEY",    "valueFrom": "$SECRET_ARN:REVERB_APP_KEY::" },
    { "name": "REVERB_APP_SECRET", "valueFrom": "$SECRET_ARN:REVERB_APP_SECRET::" }
JSON
}

log_config() {   # stream prefix
cat <<JSON
  "logConfiguration": {
    "logDriver": "awslogs",
    "options": {
      "awslogs-group":         "/ecs/$PROJECT",
      "awslogs-region":        "$AWS_REGION",
      "awslogs-stream-prefix": "$1"
    }
  }
JSON
}

# --------------------------------------------------------------------- app
cat > "$OUT/app.json" <<JSON
{
  "family": "$PROJECT-app",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "runtimePlatform": { "cpuArchitecture": "X86_64", "operatingSystemFamily": "LINUX" },
  "executionRoleArn": "$EXEC_ROLE_ARN",
  "taskRoleArn": "$TASK_ROLE_ARN",
  "containerDefinitions": [{
    "name": "app",
    "image": "$ECR_URI:$IMAGE_TAG",
    "essential": true,
    "portMappings": [{ "containerPort": 80, "protocol": "tcp" }],
    "environment": [
      { "name": "CONTAINER_ROLE", "value": "app" },
      { "name": "RUN_MIGRATIONS", "value": "true" },
$(common_env)
    ],
    "secrets": [
$(common_secrets)
    ],
    "healthCheck": {
      "command": ["CMD-SHELL", "curl -fsS http://127.0.0.1/up || exit 1"],
      "interval": 30, "timeout": 5, "retries": 3, "startPeriod": 120
    },
$(log_config app)
  }]
}
JSON

# ------------------------------------------------------------------ reverb
cat > "$OUT/reverb.json" <<JSON
{
  "family": "$PROJECT-reverb",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "256",
  "memory": "512",
  "runtimePlatform": { "cpuArchitecture": "X86_64", "operatingSystemFamily": "LINUX" },
  "executionRoleArn": "$EXEC_ROLE_ARN",
  "taskRoleArn": "$TASK_ROLE_ARN",
  "containerDefinitions": [{
    "name": "reverb",
    "image": "$ECR_URI:$IMAGE_TAG",
    "essential": true,
    "portMappings": [{ "containerPort": 8080, "protocol": "tcp" }],
    "environment": [
      { "name": "CONTAINER_ROLE", "value": "reverb" },
      { "name": "SKIP_OPTIMIZE",  "value": "true" },
      { "name": "SKIP_DB_WAIT",   "value": "true" },
$(common_env)
    ],
    "secrets": [
$(common_secrets)
    ],
$(log_config reverb)
  }]
}
JSON

# ------------------------------------------------------------------ worker
cat > "$OUT/worker.json" <<JSON
{
  "family": "$PROJECT-worker",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "cpu": "512",
  "memory": "1024",
  "runtimePlatform": { "cpuArchitecture": "X86_64", "operatingSystemFamily": "LINUX" },
  "executionRoleArn": "$EXEC_ROLE_ARN",
  "taskRoleArn": "$TASK_ROLE_ARN",
  "containerDefinitions": [
    {
      "name": "queue",
      "image": "$ECR_URI:$IMAGE_TAG",
      "essential": true,
      "environment": [
        { "name": "CONTAINER_ROLE", "value": "queue" },
        { "name": "SKIP_OPTIMIZE",  "value": "true" },
        { "name": "QUEUE_NAMES",    "value": "events,notifications,default" },
$(common_env)
      ],
      "secrets": [
$(common_secrets)
      ],
$(log_config queue)
    },
    {
      "name": "scheduler",
      "image": "$ECR_URI:$IMAGE_TAG",
      "essential": true,
      "environment": [
        { "name": "CONTAINER_ROLE", "value": "scheduler" },
        { "name": "SKIP_OPTIMIZE",  "value": "true" },
$(common_env)
      ],
      "secrets": [
$(common_secrets)
      ],
$(log_config scheduler)
    }
  ]
}
JSON

echo "$OUT"
```

It writes the three files to a temporary directory and prints its path:

```sh
export TD_DIR=$(./aws/render-taskdefs.sh)
jq -r '.family' "$TD_DIR"/*.json       # sanity check before registering

for f in app reverb worker; do
  aws ecs register-task-definition --cli-input-json "file://$TD_DIR/$f.json" \
    --query 'taskDefinition.taskDefinitionArn' --output text
done
```

The choices in there worth explaining:

- **`LOG_CHANNEL=stderr`.** This is the one setting a Fargate deployment must
  change from the shared-hosting example. The default `stack` → `single` writes
  to `storage/logs/laravel.log` *inside the container*, where nobody will ever
  read it and which vanishes when the task stops. `stderr` sends it to
  CloudWatch.
- **`RUN_MIGRATIONS=true` on the `app` role only**, and keep that service at
  **one task** while it is true. The entrypoint runs `php artisan migrate
  --force` on boot; two tasks booting at once would run it concurrently. See
  [11.4](#114-scaling-past-one-app-task) for how to scale.
- **`SKIP_OPTIMIZE=true` on everything but `app`.** The web role caches config,
  routes, views and events on boot. The workers do not need that work repeated,
  and Reverb must not do it.
- **`SKIP_DB_WAIT=true` on `reverb`.** Reverb never touches MySQL; without this
  it spends 60 seconds in the entrypoint's database probe before starting.
- **`REVERB_PUBLISH_HOST=$ALB_DNS`.** The app tasks have to publish broadcast
  events *to* the Reverb task. In Compose that is the service name `reverb`; on
  Fargate there is no such DNS name, so the simplest correct answer is to go
  back out through the ALB, which already routes `/apps` to Reverb. The pusher
  HTTP signature covers the method, path and body — not the `Host` header — so
  proxying does not break it. If you would rather keep that traffic inside the
  VPC, add an ECS Service Connect namespace and point `REVERB_PUBLISH_HOST` at
  the service alias instead.
- **`SESSION_SECURE_COOKIE=false`** and no `SESSION_DOMAIN`. You are on plain
  HTTP at an ALB hostname. Setting the secure flag now means the session cookie
  is never sent and **login silently fails**. Part 14 turns it back on with TLS.
- **`SANCTUM_STATEFUL_DOMAINS=$ALB_DNS`** is what makes cookie auth work for the
  dashboard. Omit it and every dashboard API call returns 401 even after a
  successful login.

### 11.3 Services

```sh
# Web — behind the ALB default rule.
aws ecs create-service \
  --cluster "$PROJECT-cluster" --service-name "$PROJECT-app" \
  --task-definition "$PROJECT-app" --desired-count 1 --launch-type FARGATE \
  --platform-version LATEST --enable-execute-command \
  --network-configuration "awsvpcConfiguration={subnets=[$PUB1,$PUB2],securityGroups=[$SG_APP],assignPublicIp=ENABLED}" \
  --load-balancers "targetGroupArn=$TG_APP,containerName=app,containerPort=80" \
  --health-check-grace-period-seconds 240 \
  --deployment-configuration "minimumHealthyPercent=100,maximumPercent=200"

# Reverb — behind the /app, /apps rule.
aws ecs create-service \
  --cluster "$PROJECT-cluster" --service-name "$PROJECT-reverb" \
  --task-definition "$PROJECT-reverb" --desired-count 1 --launch-type FARGATE \
  --platform-version LATEST --enable-execute-command \
  --network-configuration "awsvpcConfiguration={subnets=[$PUB1,$PUB2],securityGroups=[$SG_APP],assignPublicIp=ENABLED}" \
  --load-balancers "targetGroupArn=$TG_REVERB,containerName=reverb,containerPort=8080" \
  --health-check-grace-period-seconds 120 \
  --deployment-configuration "minimumHealthyPercent=100,maximumPercent=200"

# Queue + scheduler — no load balancer.
aws ecs create-service \
  --cluster "$PROJECT-cluster" --service-name "$PROJECT-worker" \
  --task-definition "$PROJECT-worker" --desired-count 1 --launch-type FARGATE \
  --platform-version LATEST --enable-execute-command \
  --network-configuration "awsvpcConfiguration={subnets=[$PUB1,$PUB2],securityGroups=[$SG_APP],assignPublicIp=ENABLED}" \
  --deployment-configuration "minimumHealthyPercent=0,maximumPercent=100"
```

They will fail to start until Part 12 pushes an image — the `bootstrap` tag does
not exist yet. That is expected.

`--enable-execute-command` is what lets you run `artisan` inside a live task.
You need it in Part 12.

> **The scheduler must stay at exactly one task.** Every scheduled command in
> [routes/console.php](../../routes/console.php) uses `withoutOverlapping()`,
> which takes a Redis lock, so a second scheduler would mostly be harmless — but
> `devices:detect-offline` runs every minute and the lock is the only thing
> stopping duplicate offline transitions. One task.

### 11.4 Scaling past one app task

When you want more than one web task:

1. Set `RUN_MIGRATIONS=false` in the `app` task definition.
2. Run migrations as a one-off task in the pipeline instead:

```sh
aws ecs run-task --cluster "$PROJECT-cluster" \
  --task-definition "$PROJECT-app" --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[$PUB1],securityGroups=[$SG_APP],assignPublicIp=ENABLED}" \
  --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","migrate","--force"]}]}'
```

3. Raise `--desired-count`.

Sessions are in Redis, so no sticky sessions are needed. Reverb stays at one
task unless you enable `REVERB_SCALING_ENABLED=true`, which needs Redis pub/sub
and therefore a non-TLS ElastiCache endpoint — which is what Part 6 gave you.

---

## Part 12 — First deployment, by hand

Do this once manually. It proves the infrastructure before you add CI.

### 12.1 Build and push

```sh
aws ecr get-login-password --region "$AWS_REGION" \
  | docker login --username AWS --password-stdin "${ECR_URI%/*}"

docker buildx build \
  --platform linux/amd64 \
  --target prod \
  -f docker/Dockerfile \
  -t "$ECR_URI:bootstrap" \
  -t "$ECR_URI:latest" \
  --push .
```

`--platform linux/amd64` matters: the task definitions above specify `X86_64`.
If you are on an ARM Mac, without this flag you push an arm64 image and the task
fails with `exec format error`. (You could instead set `ARM64` in the task
definitions and use `--platform linux/arm64` — Fargate Graviton is about 20%
cheaper. Keep both in sync.)

The build runs `tsc --noEmit && vite build` in the frontend stage, so a
TypeScript error fails the image build rather than shipping a broken dashboard.

### 12.2 Wait for the services

```sh
aws ecs update-service --cluster "$PROJECT-cluster" --service "$PROJECT-app" --force-new-deployment
aws ecs update-service --cluster "$PROJECT-cluster" --service "$PROJECT-reverb" --force-new-deployment
aws ecs update-service --cluster "$PROJECT-cluster" --service "$PROJECT-worker" --force-new-deployment

aws ecs wait services-stable --cluster "$PROJECT-cluster" \
  --services "$PROJECT-app" "$PROJECT-reverb" "$PROJECT-worker"
```

Watch the boot, including the migrations:

```sh
aws logs tail "/ecs/$PROJECT" --follow --since 10m
```

You are looking for `[entrypoint] database reachable`, `[entrypoint] running
migrations`, then `[entrypoint] starting role: app`.

```sh
curl -i "http://$ALB_DNS/up"
```

### 12.3 Seed roles and create the first operator

The 22 migrations create the schema but **no roles and no users**. Without the
roles, nobody can be granted anything.

```sh
export APP_TASK=$(aws ecs list-tasks --cluster "$PROJECT-cluster" \
  --service-name "$PROJECT-app" --desired-status RUNNING \
  --query 'taskArns[0]' --output text)

aws ecs execute-command --cluster "$PROJECT-cluster" --task "$APP_TASK" \
  --container app --interactive \
  --command "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
```

> Run **only** that seeder. The default `DatabaseSeeder` also calls
> `DemoTenantSeeder`, which uses model factories — and `fakerphp/faker` is a dev
> dependency that the production image does not contain. It would fail, and you
> do not want demo accounts with the password `password` in production anyway.

Now create your tenant and super-admin. `tinker` needs a writable home
directory:

```sh
aws ecs execute-command --cluster "$PROJECT-cluster" --task "$APP_TASK" \
  --container app --interactive --command "/bin/bash"
```

Inside the container:

```sh
export HOME=/tmp
php artisan tinker
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

`uuid` is generated automatically, and `password` is hashed by the model's
`casts()` — pass the plaintext, not a hash.

### 12.4 Sign in

Open `http://$ALB_DNS` and log in. `super_admin` is one of the roles that
**must** set up two-factor authentication before it can do anything — scan the
QR code with any authenticator app and keep the recovery codes, because they are
shown exactly once.

---

## Part 13 — Mail and SMS

OTP delivery is the one feature that does not work yet: `MAIL_MAILER=log` writes
the code to CloudWatch instead of sending it, and `SMS_DRIVER=null` reports
failure honestly.

### E-mail via SES

```sh
aws sesv2 create-email-identity --email-identity access@yourdomain.com
```

Click the verification link. A brand-new SES account is in the **sandbox**,
which only sends to verified addresses — request production access in the SES
console before real users depend on it.

Then create SMTP credentials in the SES console (IAM user with
`ses:SendRawEmail`), store the password in the secret, and change the task
definition's mail variables:

```sh
aws secretsmanager put-secret-value --secret-id "$SECRET_ARN" \
  --secret-string "$(aws secretsmanager get-secret-value --secret-id "$SECRET_ARN" \
      --query SecretString --output text \
      | jq --arg p '<ses-smtp-password>' '.MAIL_PASSWORD = $p')"
```

In `common_env()` in `aws/render-taskdefs.sh`, replace the mail block with:

```json
{ "name": "MAIL_MAILER",       "value": "smtp" },
{ "name": "MAIL_HOST",         "value": "email-smtp.ap-south-1.amazonaws.com" },
{ "name": "MAIL_PORT",         "value": "587" },
{ "name": "MAIL_ENCRYPTION",   "value": "tls" },
{ "name": "MAIL_USERNAME",     "value": "<ses-smtp-username>" },
{ "name": "MAIL_FROM_ADDRESS", "value": "access@yourdomain.com" },
{ "name": "MAIL_FROM_NAME",    "value": "Smart Access Control" },
```

and add `{ "name": "MAIL_PASSWORD", "valueFrom": "$SECRET_ARN:MAIL_PASSWORD::" }`
to `common_secrets()`. Re-render and redeploy.

### SMS

`SMS_DRIVER=http` posts `{to, from, text}` with a bearer token to
`SMS_HTTP_ENDPOINT`. Put the token in the secret's `SMS_HTTP_TOKEN` key and the
endpoint in the environment. There is no AWS resource to create — if you later
pick SNS or a local Sri Lankan gateway, it needs a new driver in
[app/Services/Sms](../../app/Services/Sms).

Note that **e-mail and SMS are independent channels here, by design** — e-mail
is never a fallback triggered by an SMS failure. Configuring only one leaves OTP
delivery working through that one.

---

## Part 14 — The CI/CD pipeline

### 14.1 Create the branch and the repository variables

```sh
git checkout -b prod && git push -u origin prod
```

Then in GitHub → Settings → Secrets and variables → Actions → **Variables**
(these are identifiers, not secrets — nothing here needs encrypting):

| Variable | Value |
|---|---|
| `AWS_REGION` | `ap-south-1` |
| `AWS_ROLE_ARN` | the `$GH_ROLE_ARN` from Part 9.3 |
| `ECR_REPOSITORY` | `smart-access-control` |
| `ECS_CLUSTER` | `sac-cluster` |

Protect the branch: Settings → Branches → add a rule for `prod` requiring a
pull request. That is what makes "merge to `prod`" the deployment trigger rather
than an accident.

### 14.2 The workflow

Save as `.github/workflows/deploy-prod.yml`:

```yaml
name: Deploy to production

on:
  push:
    branches: [prod]
  workflow_dispatch:

concurrency:
  group: deploy-prod
  cancel-in-progress: false

permissions:
  id-token: write      # required for OIDC
  contents: read

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: aws-actions/configure-aws-credentials@v4
        with:
          role-to-assume: ${{ vars.AWS_ROLE_ARN }}
          aws-region: ${{ vars.AWS_REGION }}

      - id: ecr
        uses: aws-actions/amazon-ecr-login@v2

      - uses: docker/setup-buildx-action@v3

      - name: Build and push
        uses: docker/build-push-action@v6
        with:
          context: .
          file: docker/Dockerfile
          target: prod
          platforms: linux/amd64
          push: true
          tags: |
            ${{ steps.ecr.outputs.registry }}/${{ vars.ECR_REPOSITORY }}:${{ github.sha }}
            ${{ steps.ecr.outputs.registry }}/${{ vars.ECR_REPOSITORY }}:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max

      # One image, three services. The app service migrates on boot, so it
      # goes first and the workers follow only once it is stable.
      - name: Deploy sac-app
        uses: ./.github/actions/deploy-service
        with:
          service: sac-app
          container: app
          image: ${{ steps.ecr.outputs.registry }}/${{ vars.ECR_REPOSITORY }}:${{ github.sha }}
          cluster: ${{ vars.ECS_CLUSTER }}

      - name: Deploy sac-reverb
        uses: ./.github/actions/deploy-service
        with:
          service: sac-reverb
          container: reverb
          image: ${{ steps.ecr.outputs.registry }}/${{ vars.ECR_REPOSITORY }}:${{ github.sha }}
          cluster: ${{ vars.ECS_CLUSTER }}

      - name: Deploy sac-worker
        uses: ./.github/actions/deploy-service
        with:
          service: sac-worker
          container: queue           # the composite action updates every container
          image: ${{ steps.ecr.outputs.registry }}/${{ vars.ECR_REPOSITORY }}:${{ github.sha }}
          cluster: ${{ vars.ECS_CLUSTER }}
```

And the composite action, `.github/actions/deploy-service/action.yml`:

```yaml
name: Deploy one ECS service
inputs:
  service:   { required: true }
  container: { required: true }
  image:     { required: true }
  cluster:   { required: true }

runs:
  using: composite
  steps:
    - shell: bash
      run: |
        set -euo pipefail
        family=$(aws ecs describe-services \
          --cluster "${{ inputs.cluster }}" --services "${{ inputs.service }}" \
          --query 'services[0].taskDefinition' --output text)

        # Point every container in the definition at the new image, and drop
        # the read-only fields describe-task-definition adds.
        aws ecs describe-task-definition --task-definition "$family" \
          --query 'taskDefinition' --output json \
          | jq --arg img "${{ inputs.image }}" '
              .containerDefinitions |= map(.image = $img)
              | del(.taskDefinitionArn, .revision, .status,
                    .requiresAttributes, .compatibilities,
                    .registeredAt, .registeredBy, .deregisteredAt)
            ' > /tmp/td.json

        arn=$(aws ecs register-task-definition \
          --cli-input-json file:///tmp/td.json \
          --query 'taskDefinition.taskDefinitionArn' --output text)

        aws ecs update-service --cluster "${{ inputs.cluster }}" \
          --service "${{ inputs.service }}" --task-definition "$arn" >/dev/null

        aws ecs wait services-stable \
          --cluster "${{ inputs.cluster }}" --services "${{ inputs.service }}"
        echo "deployed $arn"
```

Two things to be clear about:

- **Use `on: push: branches: [prod]`, not `pull_request_target`.** Merging a PR
  into `prod` *is* a push to `prod`, so this fires exactly when you want.
  `pull_request_target` runs on the *unmerged* PR with repository secrets and
  write permissions available to the contributor's branch — it is a well-known
  way to leak credentials, and it is not what you are describing.
- **Images are tagged with the commit SHA**, and each deploy registers a new
  task definition revision. Rolling back is
  `aws ecs update-service --task-definition sac-app:<previous-revision>` — which
  is the whole reason not to deploy a mutable `latest`.

---

## Part 15 — Later: your domain and HTTPS

You will want this before a single real panel talks to the server. Right now the
device API carries `X-Device-Secret` over plain HTTP.

Your domain is registered at domain.com, outside AWS. You have two routes.

### Option A — keep DNS at domain.com (least change)

1. Request a certificate in **ACM, in the same region as the ALB**:

```sh
aws acm request-certificate \
  --domain-name access.yourdomain.com \
  --validation-method DNS \
  --query 'CertificateArn' --output text
```

2. Read the validation record ACM wants and create it as a CNAME in domain.com's
   DNS panel:

```sh
aws acm describe-certificate --certificate-arn <arn> \
  --query 'Certificate.DomainValidationOptions[0].ResourceRecord'
```

3. Add a CNAME `access.yourdomain.com` → `$ALB_DNS` in the same panel. An ALB
   has no static IP, so it must be a CNAME (or an ALIAS, which domain.com does
   not offer) — never an A record.

4. Add the HTTPS listener and redirect HTTP to it:

```sh
aws ec2 authorize-security-group-ingress --group-id "$SG_ALB" \
  --protocol tcp --port 443 --cidr 0.0.0.0/0

aws elbv2 create-listener --load-balancer-arn "$ALB_ARN" \
  --protocol HTTPS --port 443 \
  --certificates CertificateArn=<arn> \
  --ssl-policy ELBSecurityPolicy-TLS13-1-2-2021-06 \
  --default-actions "Type=forward,TargetGroupArn=$TG_APP"

# Re-create the Reverb rule on the new listener.
aws elbv2 create-rule --listener-arn <https-listener-arn> --priority 10 \
  --conditions 'Field=path-pattern,Values=/app,/app/*,/apps,/apps/*' \
  --actions "Type=forward,TargetGroupArn=$TG_REVERB"

# Turn the HTTP listener into a redirect.
aws elbv2 modify-listener --listener-arn "$LISTENER_ARN" \
  --default-actions '[{
    "Type": "redirect",
    "RedirectConfig": { "Protocol": "HTTPS", "Port": "443", "StatusCode": "HTTP_301" }
  }]'
```

5. Update the application's view of itself — **this step is not optional**, and
   it is where this kind of migration usually goes wrong. In
   `aws/render-taskdefs.sh`:

```diff
-{ "name": "APP_URL", "value": "http://$ALB_DNS" },
+{ "name": "APP_URL", "value": "https://access.yourdomain.com" },
-{ "name": "SESSION_SECURE_COOKIE", "value": "false" },
+{ "name": "SESSION_SECURE_COOKIE", "value": "true" },
-{ "name": "SANCTUM_STATEFUL_DOMAINS", "value": "$ALB_DNS" },
+{ "name": "SANCTUM_STATEFUL_DOMAINS", "value": "access.yourdomain.com" },
-{ "name": "REVERB_HOST",   "value": "$ALB_DNS" },
-{ "name": "REVERB_PORT",   "value": "80" },
-{ "name": "REVERB_SCHEME", "value": "http" },
+{ "name": "REVERB_HOST",   "value": "access.yourdomain.com" },
+{ "name": "REVERB_PORT",   "value": "443" },
+{ "name": "REVERB_SCHEME", "value": "https" },
```

Leave `REVERB_PUBLISH_*` on `http` and port 80 — that is the server-to-server
hop through the ALB's HTTP listener, which still exists behind the redirect only
for `/apps`. Simpler alternative: point `REVERB_PUBLISH_HOST` at the HTTPS
hostname with port 443 and scheme `https`, which survives the HTTP listener
becoming a pure redirect. Prefer that.

`trustProxies(at: '*')` is already configured in
[bootstrap/app.php](../../bootstrap/app.php#L35), so Laravel will read the ALB's
`X-Forwarded-Proto` and generate `https://` URLs correctly.

6. Re-render, redeploy, then update the firmware's server URL.

### Option B — move DNS to Route 53

Create a hosted zone for the domain, change the nameservers at domain.com to the
four Route 53 gives you, and use an ALIAS A record to the ALB. Costs $0.50/month
per zone, validates ACM certificates automatically, and lets you use health-check
failover later. Worth it if you will add more than one subdomain.

---

## Troubleshooting

**Tasks start and stop in a loop.** `aws logs tail "/ecs/$PROJECT" --since 15m`.
The entrypoint is loud about why it quits:

| Log line | Cause |
|---|---|
| `FATAL: APP_KEY is not set` | The `secrets` mapping is wrong, or the execution role cannot read the secret. Check the `:APP_KEY::` suffix — the two trailing colons are required. |
| `waiting for database (30/30)` then `still unreachable` | `$SG_RDS` does not allow `$SG_APP` on 3306, or `DB_HOST` is wrong. |
| `exec format error` (stopped-task reason, no logs) | arm64 image, X86_64 task definition. Rebuild with `--platform linux/amd64`. |
| `ResourceInitializationError: unable to pull secrets` | The task has no route out. Confirm `assignPublicIp=ENABLED` and that the subnets are the *public* ones. |

**Targets never become healthy.**
`aws elbv2 describe-target-health --target-group-arn "$TG_APP"`. `Timeout`
means the security group; `connection refused` means the container died.

**`curl http://$ALB_DNS/up` returns 503.** No healthy targets yet. The app task
spends up to a minute on migrations before nginx answers, which is why the
service has a 240-second grace period.

**Login succeeds, then every dashboard call returns 401.** `$ALB_DNS` is not in
`SANCTUM_STATEFUL_DOMAINS`.

**Login fails with 419.** The session cookie is not coming back — almost always
`SESSION_SECURE_COOKIE=true` while on plain HTTP.

**The dashboard shows "polling" instead of a live feed.** The SPA falls back to
polling and says so when realtime is unavailable. Check, in order: the Reverb
target group is healthy; the ALB rule for `/app` exists at a lower priority
number than any catch-all; `REVERB_APP_KEY` is set in the app task (a null key
disables realtime deliberately); the ALB idle timeout is 300, not 60.

**A broadcast never reaches the browser although the socket is connected.**
That is the publish direction, not the subscribe direction — check
`REVERB_PUBLISH_HOST/PORT/SCHEME` and that the app task can reach the ALB.

**Queue jobs never run.** `aws ecs describe-services --cluster "$PROJECT-cluster"
--services "$PROJECT-worker"`. Both containers are `essential: true`, so if one
exits the whole task recycles.

### Recovering your shell variables

```sh
export AWS_REGION=ap-south-1
export AWS_PAGER=""
export PROJECT=sac
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export VPC_ID=$(aws ec2 describe-vpcs --filters "Name=tag:Name,Values=$PROJECT-vpc" --query 'Vpcs[0].VpcId' --output text)
subnet() { aws ec2 describe-subnets --filters "Name=tag:Name,Values=$1" --query 'Subnets[0].SubnetId' --output text; }
export PUB1=$(subnet "$PROJECT-public-1")  PUB2=$(subnet "$PROJECT-public-2")
export PRIV1=$(subnet "$PROJECT-private-1") PRIV2=$(subnet "$PROJECT-private-2")
sg() { aws ec2 describe-security-groups --filters "Name=group-name,Values=$1" "Name=vpc-id,Values=$VPC_ID" --query 'SecurityGroups[0].GroupId' --output text; }
export SG_ALB=$(sg "$PROJECT-alb-sg") SG_APP=$(sg "$PROJECT-app-sg")
export SG_RDS=$(sg "$PROJECT-rds-sg") SG_REDIS=$(sg "$PROJECT-redis-sg")
export ECR_URI=$(aws ecr describe-repositories --repository-names smart-access-control --query 'repositories[0].repositoryUri' --output text)
export SECRET_ARN=$(aws secretsmanager describe-secret --secret-id "$PROJECT/prod" --query ARN --output text)
export DB_HOST=$(aws rds describe-db-instances --db-instance-identifier "$PROJECT-mysql" --query 'DBInstances[0].Endpoint.Address' --output text)
export REDIS_HOST=$(aws elasticache describe-cache-clusters --cache-cluster-id "$PROJECT-redis" --show-cache-node-info --query 'CacheClusters[0].CacheNodes[0].Endpoint.Address' --output text)
export ALB_ARN=$(aws elbv2 describe-load-balancers --names "$PROJECT-alb" --query 'LoadBalancers[0].LoadBalancerArn' --output text)
export ALB_DNS=$(aws elbv2 describe-load-balancers --load-balancer-arns "$ALB_ARN" --query 'LoadBalancers[0].DNSName' --output text)
export TG_APP=$(aws elbv2 describe-target-groups --names "$PROJECT-app-tg" --query 'TargetGroups[0].TargetGroupArn' --output text)
export TG_REVERB=$(aws elbv2 describe-target-groups --names "$PROJECT-reverb-tg" --query 'TargetGroups[0].TargetGroupArn' --output text)
export LISTENER_ARN=$(aws elbv2 describe-listeners --load-balancer-arn "$ALB_ARN" --query 'Listeners[?Port==`80`].ListenerArn' --output text)
export EXEC_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-task-execution
export TASK_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-task
export GH_ROLE_ARN=arn:aws:iam::$ACCOUNT_ID:role/$PROJECT-github-deploy
```

`DB_PASSWORD`, `APP_KEY` and the Reverb credentials are only in Secrets Manager:

```sh
aws secretsmanager get-secret-value --secret-id "$SECRET_ARN" --query SecretString --output text | jq .
```

---

## Teardown

In this order, to avoid dependency errors:

```sh
for s in app reverb worker; do
  aws ecs update-service --cluster "$PROJECT-cluster" --service "$PROJECT-$s" --desired-count 0
  aws ecs delete-service  --cluster "$PROJECT-cluster" --service "$PROJECT-$s" --force
done
aws ecs delete-cluster --cluster "$PROJECT-cluster"

aws elbv2 delete-load-balancer --load-balancer-arn "$ALB_ARN"
sleep 30
aws elbv2 delete-target-group --target-group-arn "$TG_APP"
aws elbv2 delete-target-group --target-group-arn "$TG_REVERB"

aws elasticache delete-cache-cluster --cache-cluster-id "$PROJECT-redis"

aws rds modify-db-instance --db-instance-identifier "$PROJECT-mysql" --no-deletion-protection --apply-immediately
aws rds delete-db-instance --db-instance-identifier "$PROJECT-mysql" \
  --final-db-snapshot-identifier "$PROJECT-mysql-final"

aws secretsmanager delete-secret --secret-id "$SECRET_ARN" --recovery-window-in-days 7
aws ecr delete-repository --repository-name smart-access-control --force
aws logs delete-log-group --log-group-name "/ecs/$PROJECT"
```

Then the IAM roles, the security groups, the subnets, the internet gateway and
the VPC. Keep the `APP_KEY` even after teardown if there is any chance of
restoring that final snapshot — without it, every `two_factor_secret` in it is
unreadable.
