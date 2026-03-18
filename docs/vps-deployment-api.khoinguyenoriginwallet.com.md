# VPS Deployment Guide For `api.khoinguyenoriginwallet.com`

This guide is tailored for deploying this Laravel API to a single VPS on Vultr.

## Recommended VPS

Recommended starting plan:

- Provider: Vultr
- Product: Cloud Compute
- Type: Shared CPU
- Region: Singapore
- OS: Ubuntu 24.04 LTS
- Size: 2 vCPU / 4 GB RAM / 80 GB SSD

Why this size:

- Good enough for Laravel API + Nginx + PHP-FPM + PostgreSQL on one machine
- Safer than 2 GB RAM once logs, database, mail, and provider sync traffic start running together
- Still affordable for an early production deployment

Reference pricing used:

- Vultr shared cloud compute pricing page: https://www.vultr.com/pricing/
- Example plans listed there include:
  - `2 vCPU / 2 GB / 65 GB` at `$15/mo`
  - `2 vCPU / 4 GB / 80 GB` at `$20/mo`

## Recommended Server Layout

Single VPS layout:

- Nginx
- PHP 8.3 + PHP-FPM
- PostgreSQL 16
- Laravel app in `/var/www/origin_wallet`
- Supervisor for queue worker
- Certbot for TLS

This project should use PostgreSQL because the schema uses multiple `jsonb` columns.

## DNS

Create an `A` record:

- Host: `api`
- Value: your VPS public IPv4

Final domain:

```text
https://api.khoinguyenoriginwallet.com
```

## Initial Server Setup

SSH into the server as `root` and run:

```bash
apt update && apt upgrade -y
apt install -y nginx postgresql postgresql-contrib unzip git supervisor software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.3 php8.3-cli php8.3-fpm php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-sqlite3
apt install -y certbot python3-certbot-nginx
```

If you want a near one-shot setup, use the bootstrap script in this repo instead:

```bash
git clone <your-repo-url> /root/origin_wallet
cd /root/origin_wallet
DB_PASSWORD='change-this-password' \
APP_DOMAIN='api.khoinguyenoriginwallet.com' \
REPO_URL='<your-repo-url>' \
REPO_REF='main' \
INSTALL_CERTBOT=1 \
bash deploy/scripts/bootstrap_vps.sh
```

What the script does:

- installs Nginx, PHP 8.3, PostgreSQL, Composer, Supervisor, and Certbot
- creates the deploy user
- creates PostgreSQL database and user
- clones the app
- installs Composer dependencies
- copies the production `.env` template if available
- installs Nginx and Supervisor configs from this repo

What you still need to do manually afterward:

- fill in real `.env` secrets
- run `php artisan key:generate`
- run `php artisan migrate --force`
- run `php artisan db:seed --force`
- issue SSL once DNS points to the VPS

Install Composer:

```bash
cd /tmp
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
```

Create deploy user:

```bash
adduser deploy
usermod -aG www-data deploy
mkdir -p /var/www
chown deploy:www-data /var/www
chmod 775 /var/www
```

Optional but recommended swap for a 4 GB VPS:

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

## PostgreSQL Setup

Create database and user:

```bash
sudo -u postgres psql
```

Then run in PostgreSQL:

```sql
CREATE DATABASE origin_wallet;
CREATE USER origin_wallet_user WITH ENCRYPTED PASSWORD 'change-this-password';
GRANT ALL PRIVILEGES ON DATABASE origin_wallet TO origin_wallet_user;
\q
```

## Deploy Application Code

Switch to deploy user:

```bash
su - deploy
```

Clone the repository:

```bash
git clone <your-repo-url> /var/www/origin_wallet
cd /var/www/origin_wallet
composer install --no-dev --optimize-autoloader
```

Create environment file:

```bash
cp deploy/env/api.khoinguyenoriginwallet.com.env.example .env
php artisan key:generate
```

Then edit `.env` with real secrets.

Set permissions:

```bash
cd /var/www/origin_wallet
mkdir -p storage/logs bootstrap/cache
chown -R deploy:www-data /var/www/origin_wallet
chmod -R 775 storage bootstrap/cache
find storage -type f -exec chmod 664 {} \;
find bootstrap/cache -type f -exec chmod 664 {} \;
```

## Laravel First Deploy

Run:

```bash
cd /var/www/origin_wallet
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If you are not using queued jobs yet, set:

```text
QUEUE_CONNECTION=sync
```

That is the safest simple starting point for this API.

## Nginx Setup

Copy the provided Nginx config:

```bash
sudo cp /var/www/origin_wallet/deploy/nginx/api.khoinguyenoriginwallet.com.conf /etc/nginx/sites-available/api.khoinguyenoriginwallet.com
sudo ln -s /etc/nginx/sites-available/api.khoinguyenoriginwallet.com /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

## TLS / SSL

Run:

```bash
sudo certbot --nginx -d api.khoinguyenoriginwallet.com
```

Verify:

```bash
curl -I https://api.khoinguyenoriginwallet.com/api/test
```

## PHP-FPM Notes

This server should work fine with default PHP-FPM settings, but for a 4 GB machine:

- keep OPcache enabled
- do not set too many workers
- if memory gets tight, reduce idle workers

## Queue Worker

For this codebase right now, queue workers are optional if:

- mail is sent synchronously
- you are not dispatching queued jobs

If later you switch to queued mail or background jobs:

```bash
sudo cp /var/www/origin_wallet/deploy/supervisor/api.khoinguyenoriginwallet.com-worker.conf /etc/supervisor/conf.d/origin_wallet-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## Required Environment Values

Must set these:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.khoinguyenoriginwallet.com`
- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=origin_wallet`
- `DB_USERNAME=origin_wallet_user`
- `DB_PASSWORD=...`
- `MAIL_*`
- `GOOGLE_CLIENT_ID`
- `AUTH_EXPOSE_VERIFICATION_CODE=false`
- `SUPER_ADMIN_EMAIL`
- `SUPER_ADMIN_PASSWORD`
- `SUPER_ADMIN_FULL_NAME`
- provider integration secrets

## Suggested Production Defaults

- `LOG_CHANNEL=stack`
- `LOG_STACK=single`
- `LOG_LEVEL=info`
- `SESSION_DRIVER=database`
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=sync`
- `BROADCAST_CONNECTION=log`

## Post-Deploy Smoke Test

Run these checks:

1. `GET https://api.khoinguyenoriginwallet.com/api/test`
2. `GET https://api.khoinguyenoriginwallet.com/api/providers`
3. register a new account
4. verify registration by code or email link
5. login and verify OTP
6. forgot password and reset password
7. call `GET /api/auth/me` with Bearer token
8. update profile
9. test one provider sync endpoint in non-production credentials

## Ongoing Ops

Useful commands:

```bash
sudo systemctl status nginx
sudo systemctl status php8.3-fpm
sudo systemctl status postgresql
tail -f /var/www/origin_wallet/storage/logs/laravel.log
php artisan optimize:clear
php artisan about
```

## Upgrade Path

If traffic grows:

1. Move from `2 vCPU / 4 GB` to dedicated CPU or a larger shared plan.
2. Move PostgreSQL off-box to a managed database or second server.
3. Switch queue processing to Supervisor-managed workers.
4. Add Redis for cache/queue.
