# Production Deployment

This project is a Laravel 12 API for wallet onboarding, provider integrations, quotes, transfers, and webhooks.

## Recommended stack

- Hetzner Cloud in Singapore for Vietnam-facing traffic
- Ubuntu 24.04 LTS
- Nginx
- PHP 8.3 with `pdo_pgsql`
- PostgreSQL 16
- Supervisor for queue workers
- Redis optional for future queue/cache improvements

## Pre-deploy checklist

- Point the web server document root to `public/`
- Ensure outbound HTTPS is allowed for:
  - Google token verification
  - SMTP provider
  - Currenxie or other provider APIs
- Configure TLS with Let's Encrypt
- Restrict SSH access and enable a firewall

## Required environment values

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://your-api-domain`
- `DB_*`
- `MAIL_*`
- `GOOGLE_CLIENT_ID`
- `AUTH_EXPOSE_VERIFICATION_CODE=false`
- `SUPER_ADMIN_EMAIL`
- `SUPER_ADMIN_PASSWORD`
- `SUPER_ADMIN_FULL_NAME`
- provider integration secrets in `services.php`

## First deploy

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Runtime processes

- PHP-FPM behind Nginx
- A queue worker under Supervisor if mail or background jobs are queued later
- Sample configs are included in:
  - `deploy/nginx/origin_wallet.conf`
  - `deploy/supervisor/origin_wallet-worker.conf`
- A cron entry for the scheduler:

```bash
* * * * * cd /var/www/origin_wallet && php artisan schedule:run >> /dev/null 2>&1
```

## Security notes

- Admin APIs must stay behind `auth.token` and `auth.admin`
- Do not expose `.env`, `storage/logs`, or the repository root over HTTP
- Rotate leaked SMTP or provider credentials immediately
- Review provider request logs regularly and keep sensitive fields masked
- `SuperAdminSeeder` only creates the admin account when `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` are set

## Post-deploy smoke tests

- `GET /api/test`
- register and verify by email
- email/password login with OTP
- Google login with a real `id_token`
- provider webhook signature validation
- a full quote and transfer flow in a non-production provider environment
