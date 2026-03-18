#!/usr/bin/env bash

set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must be run as root."
  exit 1
fi

APP_DOMAIN="${APP_DOMAIN:-api.khoinguyenoriginwallet.com}"
APP_DIR="${APP_DIR:-/var/www/origin_wallet}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_GROUP="${DEPLOY_GROUP:-www-data}"
DB_NAME="${DB_NAME:-origin_wallet}"
DB_USER="${DB_USER:-origin_wallet_user}"
DB_PASSWORD="${DB_PASSWORD:-}"
PHP_VERSION="${PHP_VERSION:-8.3}"
REPO_URL="${REPO_URL:-}"
REPO_REF="${REPO_REF:-main}"
INSTALL_CERTBOT="${INSTALL_CERTBOT:-0}"
CONFIGURE_SWAP="${CONFIGURE_SWAP:-1}"
SWAP_SIZE_GB="${SWAP_SIZE_GB:-2}"

if [[ -z "${DB_PASSWORD}" ]]; then
  echo "DB_PASSWORD is required."
  echo "Example:"
  echo "  DB_PASSWORD='strong-password' APP_DOMAIN='api.khoinguyenoriginwallet.com' bash deploy/scripts/bootstrap_vps.sh"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

log() {
  printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

install_packages() {
  log "Updating apt and installing base packages"
  apt update
  apt upgrade -y
  apt install -y nginx postgresql postgresql-contrib unzip git supervisor software-properties-common curl ca-certificates lsb-release gnupg

  if ! grep -Rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
    add-apt-repository ppa:ondrej/php -y
    apt update
  fi

  apt install -y \
    "php${PHP_VERSION}" \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-pgsql" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-sqlite3"

  if [[ "${INSTALL_CERTBOT}" == "1" ]]; then
    apt install -y certbot python3-certbot-nginx
  fi
}

install_composer() {
  if command -v composer >/dev/null 2>&1; then
    log "Composer already installed"
    return
  fi

  log "Installing Composer"
  cd /tmp
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f composer-setup.php
}

create_deploy_user() {
  log "Ensuring deploy user exists"
  if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "${DEPLOY_USER}"
  fi

  usermod -aG "${DEPLOY_GROUP}" "${DEPLOY_USER}"
  mkdir -p /var/www
  chown "${DEPLOY_USER}:${DEPLOY_GROUP}" /var/www
  chmod 775 /var/www
}

configure_swap() {
  if [[ "${CONFIGURE_SWAP}" != "1" ]]; then
    return
  fi

  if swapon --show | grep -q "/swapfile"; then
    log "Swapfile already configured"
    return
  fi

  log "Configuring ${SWAP_SIZE_GB}G swapfile"
  fallocate -l "${SWAP_SIZE_GB}G" /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  if ! grep -q "^/swapfile " /etc/fstab; then
    echo "/swapfile none swap sw 0 0" >> /etc/fstab
  fi
}

configure_postgres() {
  log "Creating PostgreSQL database and user if needed"
  sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH ENCRYPTED PASSWORD '${DB_PASSWORD}';"

  sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"

  sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};"
}

prepare_app_dir() {
  log "Preparing application directory"
  mkdir -p "${APP_DIR}"
  chown -R "${DEPLOY_USER}:${DEPLOY_GROUP}" "${APP_DIR}"
}

clone_repo_if_requested() {
  if [[ -z "${REPO_URL}" ]]; then
    log "REPO_URL not provided, skipping repository clone"
    return
  fi

  if [[ -d "${APP_DIR}/.git" ]]; then
    log "Repository already present, fetching latest changes"
    sudo -u "${DEPLOY_USER}" git -C "${APP_DIR}" fetch --all --prune
    sudo -u "${DEPLOY_USER}" git -C "${APP_DIR}" checkout "${REPO_REF}"
    sudo -u "${DEPLOY_USER}" git -C "${APP_DIR}" pull --ff-only origin "${REPO_REF}"
  else
    log "Cloning repository"
    rm -rf "${APP_DIR}"
    sudo -u "${DEPLOY_USER}" git clone --branch "${REPO_REF}" "${REPO_URL}" "${APP_DIR}"
  fi

  log "Installing PHP dependencies"
  sudo -u "${DEPLOY_USER}" composer install --no-dev --optimize-autoloader --working-dir="${APP_DIR}"
}

prepare_laravel_dirs() {
  if [[ ! -d "${APP_DIR}" ]]; then
    return
  fi

  log "Preparing Laravel writable directories"
  mkdir -p "${APP_DIR}/storage/logs" "${APP_DIR}/bootstrap/cache"
  chown -R "${DEPLOY_USER}:${DEPLOY_GROUP}" "${APP_DIR}"
  chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
  find "${APP_DIR}/storage" -type f -exec chmod 664 {} \; || true
  find "${APP_DIR}/bootstrap/cache" -type f -exec chmod 664 {} \; || true

  if [[ -f "${APP_DIR}/deploy/env/${APP_DOMAIN}.env.example" && ! -f "${APP_DIR}/.env" ]]; then
    log "Copying production env template"
    cp "${APP_DIR}/deploy/env/${APP_DOMAIN}.env.example" "${APP_DIR}/.env"
    chown "${DEPLOY_USER}:${DEPLOY_GROUP}" "${APP_DIR}/.env"
  fi
}

configure_nginx() {
  local nginx_src="${APP_DIR}/deploy/nginx/${APP_DOMAIN}.conf"
  local nginx_dst="/etc/nginx/sites-available/${APP_DOMAIN}"

  if [[ ! -f "${nginx_src}" ]]; then
    log "Nginx config ${nginx_src} not found, skipping"
    return
  fi

  log "Installing Nginx site config"
  cp "${nginx_src}" "${nginx_dst}"
  ln -sfn "${nginx_dst}" "/etc/nginx/sites-enabled/${APP_DOMAIN}"
  rm -f /etc/nginx/sites-enabled/default
  nginx -t
  systemctl enable nginx
  systemctl reload nginx
}

configure_supervisor() {
  local supervisor_src="${APP_DIR}/deploy/supervisor/${APP_DOMAIN}-worker.conf"
  local supervisor_dst="/etc/supervisor/conf.d/origin_wallet-worker.conf"

  if [[ ! -f "${supervisor_src}" ]]; then
    log "Supervisor worker config not found, skipping"
    return
  fi

  log "Installing Supervisor worker config"
  cp "${supervisor_src}" "${supervisor_dst}"
  supervisorctl reread || true
  supervisorctl update || true
}

enable_services() {
  log "Enabling services"
  systemctl enable "php${PHP_VERSION}-fpm"
  systemctl enable postgresql
  systemctl enable supervisor
  systemctl restart "php${PHP_VERSION}-fpm"
  systemctl restart postgresql
  systemctl restart supervisor
}

print_next_steps() {
  cat <<EOF

Bootstrap completed.

Next steps:
1. SSH as ${DEPLOY_USER} and review ${APP_DIR}/.env
2. Fill in real secrets: APP_KEY, MAIL_*, GOOGLE_*, SUPER_ADMIN_*, provider secrets
3. Run:
   cd ${APP_DIR}
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
4. Point DNS:
   ${APP_DOMAIN} -> this VPS public IP
5. If DNS is ready and certbot is installed:
   certbot --nginx -d ${APP_DOMAIN}
6. Smoke test:
   curl -I https://${APP_DOMAIN}/api/test

Installed:
- Nginx
- PHP ${PHP_VERSION} + FPM
- PostgreSQL
- Composer
- Supervisor

App directory:
- ${APP_DIR}

EOF
}

main() {
  install_packages
  install_composer
  create_deploy_user
  configure_swap
  configure_postgres
  prepare_app_dir
  clone_repo_if_requested
  prepare_laravel_dirs
  configure_nginx
  configure_supervisor
  enable_services
  print_next_steps
}

main "$@"
