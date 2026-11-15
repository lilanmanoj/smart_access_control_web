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
    { "name": "BACKUP_CODE_MAX_AGE_DAYS",   "value": "90" },
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
