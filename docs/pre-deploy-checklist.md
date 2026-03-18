# Pre-Deploy Checklist

This checklist covers everything needed before deploying the API to a VPS.

## 1. Accounts You Need

Prepare these accounts first:

- Vultr account
- Domain registrar account
- Email / SMTP account for OTP and forgot password emails
- Google Cloud account for Google login
- Git repository access for this project
- Provider integration accounts if you want live sync, quotes, transfers, or webhooks

## 2. Buy A Domain

You do not need the domain before building the server, but you do need it before final production SSL setup.

Recommended structure:

- Main website: `khoinguyenoriginwallet.com`
- API subdomain: `api.khoinguyenoriginwallet.com`

What to buy:

- one domain: `khoinguyenoriginwallet.com`

What you will create later:

- `A` record for `api.khoinguyenoriginwallet.com`

Good registrars:

- Cloudflare Registrar
- Namecheap
- Porkbun

## 3. VPS To Create

Recommended VPS:

- Provider: Vultr
- Type: Cloud Compute
- Plan: Shared CPU
- Region: Singapore
- OS: Ubuntu 24.04 LTS
- Size: 2 vCPU / 4 GB RAM / 80 GB SSD

## 4. Server Software You Will Install

Prepare to install:

- Nginx
- PHP 8.3
- PHP-FPM
- Composer
- PostgreSQL 16
- Supervisor
- Certbot
- Git

## 5. Database Info To Prepare

You need these values:

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=origin_wallet`
- `DB_USERNAME=origin_wallet_user`
- `DB_PASSWORD=<strong password>`

Important:

- This project should use PostgreSQL because the schema uses `jsonb`.

## 6. Environment Variables To Prepare

You should have real values ready for:

- `APP_NAME`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.khoinguyenoriginwallet.com`
- `APP_KEY`
- `DB_*`
- `MAIL_*`
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `SUPER_ADMIN_EMAIL`
- `SUPER_ADMIN_PASSWORD`
- `SUPER_ADMIN_FULL_NAME`
- `CORS_ALLOWED_ORIGINS`
- provider secrets like `CURRENXIE_*` if used

Use this file as the starting template:

- [api.khoinguyenoriginwallet.com.env.example](/home/tiendat/origin_wallet/deploy/env/api.khoinguyenoriginwallet.com.env.example)

## 7. Mail / SMTP Requirements

This API needs working email for:

- registration verification
- login OTP
- forgot password reset code

Prepare:

- SMTP host
- SMTP port
- SMTP username
- SMTP password or app password
- from email address

Recommended options:

- Gmail SMTP for testing
- Resend, Mailgun, Brevo, or Amazon SES for production

## 8. Google Login Requirements

If you want `POST /api/auth/google` to work, prepare:

- Google OAuth client
- authorized JavaScript origin for frontend
- correct Google client ID in backend `.env`

At minimum you need:

- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`

## 9. Domain + DNS Values To Prepare

After buying the domain and creating the VPS, prepare:

- VPS public IPv4
- registrar DNS access

Then create:

- `A` record: `api` -> `your VPS IPv4`

Optional:

- `A` record: `@` -> website server IP
- `CNAME` record: `www` -> `@`

## 10. Files Already Prepared In This Repo

You already have:

- deploy guide:
  [vps-deployment-api.khoinguyenoriginwallet.com.md](/home/tiendat/origin_wallet/docs/vps-deployment-api.khoinguyenoriginwallet.com.md)
- bootstrap script:
  [bootstrap_vps.sh](/home/tiendat/origin_wallet/deploy/scripts/bootstrap_vps.sh)
- Nginx config:
  [api.khoinguyenoriginwallet.com.conf](/home/tiendat/origin_wallet/deploy/nginx/api.khoinguyenoriginwallet.com.conf)
- Supervisor config:
  [api.khoinguyenoriginwallet.com-worker.conf](/home/tiendat/origin_wallet/deploy/supervisor/api.khoinguyenoriginwallet.com-worker.conf)
- frontend API reference:
  [frontend-api.md](/home/tiendat/origin_wallet/docs/frontend-api.md)

## 11. Before First Deploy

Make sure you have:

- purchased the domain
- created the VPS
- SSH access to the VPS
- repository URL ready
- PostgreSQL password chosen
- SMTP credentials ready
- Google credentials ready if needed
- super admin credentials ready
- provider API credentials ready if needed

## 12. After Deploy

You should test:

1. `GET /api/test`
2. register
3. verify registration
4. login
5. verify login OTP
6. forgot password
7. reset password
8. `GET /api/auth/me`
9. profile update
10. one admin API with Bearer token

## 13. Minimum Real-World Deployment Order

1. Buy domain
2. Create VPS
3. Point DNS `api` to VPS IP
4. Install server software
5. Clone code
6. Create `.env`
7. Create PostgreSQL database and user
8. Run migrate and seed
9. Configure Nginx
10. Issue SSL certificate
11. Smoke test APIs
