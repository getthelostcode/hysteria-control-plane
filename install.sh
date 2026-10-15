#!/usr/bin/env bash
#
# Hysteria2 Control Plane — one-click installer
#
#   sudo bash install.sh                 # full install (deps, db, migrate, seed, build, systemd)
#   sudo bash install.sh --no-seed       # skip demo fleet seeding
#   sudo bash install.sh --no-services   # skip systemd units (horizon / reverb / scheduler)
#   sudo bash install.sh --upgrade       # update an existing checkout (pull, migrate, rebuild)
#   DB_CONNECTION=sqlite bash install.sh # trial/dev install without MySQL
#
# Idempotent: re-running it will not destroy data. It creates .env from
# .env.example on first run and only fills in what is missing afterwards.
set -Eeuo pipefail

APP_NAME="Hysteria Control Plane"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEED=1
INSTALL_SERVICES=1
UPGRADE_ONLY=0

# DB_CONNECTION=sqlite gives you a zero-dependency trial/dev environment
# (no MySQL needed). Production stays on MySQL 8.
DB_DRIVER="${DB_CONNECTION:-mysql}"
DB_NAME="${DB_NAME:-hysteria_cp}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
APP_URL_VALUE="${APP_URL:-http://127.0.0.1:8080}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"
SERVICE_USER="${SERVICE_USER:-www-data}"
PHP_BIN="${PHP_BIN:-php}"

log()  { printf '\033[1;34m[install]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

for arg in "$@"; do
    case "$arg" in
        --no-seed)     SEED=0 ;;
        --no-services) INSTALL_SERVICES=0 ;;
        --upgrade)     UPGRADE_ONLY=1 ;;
        -h|--help)     sed -n '2,14p' "$0"; exit 0 ;;
        *) die "unknown option: $arg" ;;
    esac
done

# ----------------------------------------------------------------- deps ----
log "checking runtime dependencies"
command -v "$PHP_BIN" >/dev/null || die "php not found (set PHP_BIN=/usr/bin/php8.3)"

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
log "php: $PHP_VERSION"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || die "PHP >= 8.2 is required (found $PHP_VERSION)"

REQUIRED_EXT=(bcmath ctype curl dom fileinfo json mbstring openssl pcre pdo pdo_mysql redis tokenizer xml)
for ext in "${REQUIRED_EXT[@]}"; do
    "$PHP_BIN" -m | grep -qi "^${ext}$" || die "missing PHP extension: ${ext}"
done
log "php extensions: ok"

command -v composer >/dev/null || die "composer not found (https://getcomposer.org)"
if [ "$UPGRADE_ONLY" -eq 0 ]; then
    command -v node >/dev/null || die "node not found (needed to build the panel theme)"
    command -v npm  >/dev/null || die "npm not found"
    log "node: $(node -v) / npm: $(npm -v)"
fi

if [ "$DB_DRIVER" = "mysql" ]; then
    command -v mysql >/dev/null || warn "mysql client not found: create the database manually"
fi

# ------------------------------------------------------------------ env ----
cd "$APP_DIR"

if [ ! -f .env ]; then
    log "creating .env from .env.example"
    cp .env.example .env
fi

set_env() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        # keep local customisations: only rewrite when explicitly forced
        if [ "${3:-keep}" = "force" ] || grep -qE "^${key}=$" .env; then
            "$PHP_BIN" -r '
                $key = $argv[1]; $value = $argv[2]; $file = $argv[3];
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                foreach ($lines as $i => $line) {
                    if (str_starts_with($line, $key . "=")) { $lines[$i] = $key . "=" . $value; }
                }
                file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
            ' "$key" "$value" .env
        fi
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env DB_CONNECTION "$DB_DRIVER" force

if [ "$DB_DRIVER" = "sqlite" ]; then
    SQLITE_FILE="$APP_DIR/database/database.sqlite"
    mkdir -p database
    [ -f "$SQLITE_FILE" ] || : > "$SQLITE_FILE"
    set_env DB_DATABASE "$SQLITE_FILE" force
    set_env DB_FOREIGN_KEYS true force
else
    set_env DB_HOST "$DB_HOST" force
    set_env DB_PORT "$DB_PORT" force
    set_env DB_DATABASE "$DB_NAME" force
    set_env DB_USERNAME "$DB_USER" force
    set_env DB_PASSWORD "$DB_PASSWORD" force
fi
set_env APP_URL "$APP_URL_VALUE" force
set_env APP_ENV "${APP_ENV:-production}"
set_env APP_DEBUG "${APP_DEBUG:-false}"
set_env QUEUE_CONNECTION redis force
set_env CACHE_STORE redis force
set_env SESSION_DRIVER redis force
set_env BROADCAST_CONNECTION reverb force
set_env REDIS_CLIENT phpredis force
if [ "$DB_DRIVER" = "sqlite" ]; then
    log ".env written (sqlite ${APP_DIR}/database/database.sqlite)"
else
    log ".env written (db ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME})"
fi

# ------------------------------------------------------------- database ----
if [ "$DB_DRIVER" = "sqlite" ]; then
    log "sqlite database ready (no server to provision)"
elif command -v mysql >/dev/null; then
    log "ensuring database ${DB_NAME} exists"
    MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
    " || die "cannot connect to MySQL (host ${DB_HOST}:${DB_PORT}, user ${DB_USER})"
fi

# ------------------------------------------------------------ composer -----
if [ "$UPGRADE_ONLY" -eq 0 ]; then
    log "installing composer dependencies"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --optimize-autoloader
else
    log "updating composer dependencies"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
fi

# APP_KEY must exist before any Crypt/encryption work (node secrets, sessions).
if ! grep -qE '^APP_KEY=base64:.+' .env; then
    log "generating application key"
    "$PHP_BIN" artisan key:generate --force
fi

log "running migrations"
"$PHP_BIN" artisan migrate --force

if [ "$SEED" -eq 1 ]; then
    log "seeding roles, settings and a demo fleet"
    "$PHP_BIN" artisan db:seed --force
fi

# ---------------------------------------------------------------- front ----
if [ "$UPGRADE_ONLY" -eq 0 ]; then
    log "building panel assets (vite)"
    # devDependencies are required by the vite build
    npm install --include=dev --no-audit --no-fund
    npm run build
else
    npm install --include=dev --no-audit --no-fund
    npm run build
fi

# -------------------------------------------------------------- runtime ----
log "preparing runtime directories"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache
"$PHP_BIN" artisan storage:link >/dev/null 2>&1 || true
"$PHP_BIN" artisan optimize:clear >/dev/null

if [ "$(id -u)" -eq 0 ] && id "$SERVICE_USER" >/dev/null 2>&1; then
    chown -R "$SERVICE_USER:$SERVICE_USER" storage bootstrap/cache
    log "storage ownership handed to ${SERVICE_USER}"
fi

# --------------------------------------------------------------- systemd ---
if [ "$INSTALL_SERVICES" -eq 1 ] && [ "$(id -u)" -eq 0 ] && command -v systemctl >/dev/null; then
    log "installing systemd units (horizon / reverb / scheduler)"
    for unit in horizon reverb scheduler; do
        sed -e "s#__APP_DIR__#${APP_DIR}#g" -e "s#__USER__#${SERVICE_USER}#g" -e "s#__PHP__#$(command -v "$PHP_BIN")#g" \
            "deploy/systemd/hysteria-control-plane-${unit}.service" \
            > "/etc/systemd/system/hysteria-control-plane-${unit}.service"
    done
    systemctl daemon-reload
    systemctl enable --now hysteria-control-plane-horizon.service hysteria-control-plane-reverb.service hysteria-control-plane-scheduler.service || \
        warn "start the services manually: systemctl start hysteria-control-plane-{horizon,reverb,scheduler}"
fi

# --------------------------------------------------------------- summary ---
PANEL_PATH="admin"
cat <<SUMMARY

  ${APP_NAME} installed.

  Database   : ${DB_DRIVER}
  Panel      : ${APP_URL_VALUE}/${PANEL_PATH}   (login ${ADMIN_EMAIL} / ${ADMIN_PASSWORD})
  API docs   : ${APP_URL_VALUE}/docs/api        (OpenAPI JSON: /docs/api.json)
  Agent API  : ${APP_URL_VALUE}/api/agent/v1/*
  Admin API  : ${APP_URL_VALUE}/api/admin/v1/*

  Next steps
    1. php ${APP_DIR}/artisan serve --host 0.0.0.0 --port 8088   # quick local run
    2. point your web server at ${APP_DIR}/public and set APP_URL + REVERB_* accordingly
    3. run the queue:  php artisan horizon
    4. run websockets: php artisan reverb:start --host 0.0.0.0 --port 8081
    5. schedule:       * * * * * cd ${APP_DIR} && php artisan schedule:run
    6. create an enrollment token in the panel, then enroll your first node

SUMMARY
