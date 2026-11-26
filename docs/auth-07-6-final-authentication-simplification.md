# AUTH-07.6 — Final mandatory Google Authenticator simplification

Date: 2026-11-26 (Asia/Bangkok).

**Implementation status: COMPLETED. Operator verification and Production release remain pending.**

The Backend, Admin and Customer source changes are implemented in the three isolated Authentication worktrees. Existing accounts with an established legacy login destination can enroll using password → fresh enrollment email code → local QR/manual key → TOTP → 10 Recovery Codes → save acknowledgment → dashboard. Normal enrollment and recovery accept no operator/migration grants. This is a source completion statement, not a test PASS, a deployed result or Production readiness.

## 1. Exact changed-file manifest

Paths below are relative to the named worktree. These lists are also the complete Git staging allowlists for this stage. No files were deleted.

### Backend: /home/dtd1201/projects/api_origin_wallet_auth_release

Modified:

1. `app/Http/Controllers/Api/AuthController.php`
2. `app/Http/Controllers/Api/MandatoryAuthController.php`
3. `app/Models/User.php`
4. `app/Services/Auth/EnrollmentTrustPolicy.php`
5. `app/Services/Auth/RegistrationService.php`
6. `app/Services/Auth/TotpEnrollmentService.php`
7. `app/Services/Auth/TotpRecoveryService.php`
8. `config/totp.php`
9. `resources/views/emails/enrollment-identity-proof.blade.php`
10. `tests/Feature/AdminAuthTest.php`
11. `tests/Feature/AuthPasswordResetTest.php`
12. `tests/Feature/AuthVerificationSecurityTest.php`

Added:

1. `database/migrations/2026_11_26_100000_bind_enrollment_email_identity.php`
2. `tests/Feature/MandatoryTotpSimplificationTest.php`
3. `tests/Feature/TotpPostgresConcurrencyTest.php`
4. `tests/Support/IsolatedAuthTestCase.php`
5. `tests/Support/MfaFixture.php`
6. `tests/Support/TotpTestTools.php`
7. `phpunit.auth076.xml`
8. `phpunit.auth076-postgres.xml`
9. `docs/auth-07-6-final-authentication-simplification.md`

### Admin: /home/dtd1201/projects/admin_origin_wallet_auth_release

Modified:

1. `src/components/admin/AuthCeremony.tsx`
2. `src/contexts/AuthContext.tsx`
3. `src/contexts/AuthContext.test.tsx`
4. `src/lib/admin-auth.ts`
5. `src/pages/admin/AdminLogin.tsx`
6. `src/pages/admin/AdminLogin.test.tsx`

Added: `vitest.auth076.config.ts`.

### Customer: /home/dtd1201/projects/origin-wallet-platform_auth_release

Modified:

1. `src/components/auth/AuthCeremony.tsx`
2. `src/contexts/AuthContext.tsx`
3. `src/contexts/AuthContext.test.tsx`
4. `src/pages/CustomerAuthentication.test.tsx`
5. `src/pages/Login.tsx`
6. `src/pages/Register.tsx`
7. `src/services/customerAuth.ts`

Added: `vitest.auth076.config.ts`.

Total: 36 files: 25 modified, 11 added, zero deleted. Dependency manifests/locks, existing CSP/translation tests, business source and existing routes remain unchanged.

## 2. Legacy ownership compatibility and its source evidence

The Technical Lead clarified that null `email_verified_at` on pre-existing Production accounts is not proof that email was never verified. The field was absent before this Authentication schema. No verified-email timestamp is backfilled.

Read-only inspection included the release source, retained legacy authentication tests, the original AUTH-01 source audit, and the local legacy implementation in `/home/dtd1201/projects/api_origin_wallet_sgd_quote_release`:

- Legacy `ApiAuthService::startLogin` creates a hashed login code and sends it to the resolved `User::email`; `resendLogin` also sends only to that stored destination. `completeLogin` requires the pending code and deletes its row after success. It does not preserve a successful mailbox-verification timestamp/event.
- Legacy `RegistrationService::completeRegistrationByCode/completeRegistrationByActivationLink` creates the account from the verified pending destination, then deletes the pending registration. Remaining pending rows are not durable evidence of completed registration.
- Admin-created users receive a destination through the Admin-only creation API. Google-created users use the server-verified Google email. Neither a request-supplied replacement email nor a password-reset code can select an enrollment recipient.
- Legacy shared `AuthController::updateProfile`, `User/ProfileController::update` and `Admin/UserController::update` validate no email update. Only validated fields are persisted. The release keeps this property. No email-change endpoint is added.
- Password reset changes the password, not the destination. Legacy Eloquent mutations update `users.updated_at`; the new Authentication services also record `credentials_changed_at` and revoke the auth epoch.

These are local source findings, not an inspection of active Production. The Operator must confirm the same code/provenance applies to the active release and that no out-of-band process allowed untrusted email changes. If it does not, stop cutover and establish the authoritative channel; do not expand trust to KYC, account status, account age, pending login rows or arbitrary current email addresses.

The additive migration automatically snapshots each eligible pre-existing row's **established legacy OTP destination**, password fingerprint and conservative not-before time. This is not a manual account allowlist, an enrollment approval, a statement that an old code succeeded, or fabricated historical verification. Fresh password plus fresh ownership proof remain mandatory. No factor, verified timestamp, full token, grant or registration evidence is generated by the migration.

Compatibility requires a syntactically valid stored email and nonempty password hash with a non-null last mutation timestamp. The snapshot hash must still match the current address. Account mutation within the preceding 72 hours imposes a hold because the legacy schema cannot distinguish a recent password reset from another update. Later tracked credential changes retain the minimum 72-hour hold. A password fingerprint change without the trusted credential-change timestamp fails closed. New accounts created after the snapshot do not acquire legacy eligibility.

Post-release registration retains its independently verified email hash and evidence. Unexpired, unconsumed, same-epoch/password/email registration evidence can authorize setup in the original browser immediately; other eligible original registration proof remains restricted to setup. After that evidence expires, a stable verified registration channel can use a new dedicated email proof after the ownership hold. Missing, invalid or changed ownership channels return a support message and no setup capability.

## 3. Backend state machine and API contracts

```text
email + password, endpoint-selected audience, fresh client binding
  active factor → login_totp → fresh unreplayed TOTP → eligible full token
  no factor → enrollment_identity
    valid bound new-registration evidence → enrollment_setup
    established channel → dedicated enrollment email proof
      verified proof → new enrollment_setup capability
    missing/untrusted channel or active security hold → support message, no setup
  enrollment_setup → encrypted pending secret + local QR/manual key
    valid TOTP, account/factor/challenge locks → activate factor, revoke old authority,
      rotate 10 hashed Recovery Codes, issue TOTP-proven token
      → frontend holds token/codes in memory → save acknowledgment → dashboard

registration details/password + original browser binding
  → registration email verification
  → enrollment_setup directly (no token and no redundant email/password prompt)

password + active factor → login_recovery → consume unused Recovery Code
  → revoke obsolete sessions/challenges → replacement_setup only
  → new TOTP confirmation → replace factor/revoke old codes → 10 new codes + token
```

Endpoint names, binding header, normal TOTP login and completed auth payload remain unchanged. Audience is still determined from the registered Admin/Customer route, never a client claim.

| Contract | Final behavior |
| --- | --- |
| POST both portals' `login` | 202 restricted challenge; password alone never issues a token or QR |
| POST `enrollment/identity/request` | Eligible legacy/existing channel: 202, `methods: ["enrollment_email_proof"]`, `proof_expires_in_seconds`, `resend_after_seconds`; no proof/code in response. Unsupported channel: empty methods and `support_required: true`. Bound registration evidence can still transition immediately to `enrollment_ready`. |
| POST `enrollment/identity/verify` | Required six-digit string `identity_proof`; `grant_token` prohibited. Success consumes identity capability, returns new restricted setup challenge. |
| POST `enrollment/setup`, `enrollment/confirm` | Existing local provisioning and transactional TOTP activation. Trust rechecked against current address/epoch/channel. |
| POST `register/verify` | Still 201, now returns restricted `enrollment_ready` challenge rather than `credentials_required`; requires immutable registration version and original browser binding. No token until TOTP confirmation. |
| GET signed `register/activate` | Still credentials-required; a link click cannot authorize an attacker-controlled registration browser. Password plus retained registration proof is needed for subsequent setup. |
| POST `recovery/verify` | Required `recovery_code`; `grant_token` prohibited; replacement capability only |
| POST `recovery/request` | Compatibility support information only; no ticket, approval, grant, email recovery code or login authority |
| Old `login/resend` and Google login | Remain non-authenticating/retired (410) |

Grant services, models, tables and disabled policy definitions remain for schema compatibility. Normal enrollment/recovery no longer inject or consult the grant service. Even an old grant-authorized setup/replacement challenge fails the current trust-policy allowlist. Enabling an old policy does not restore a grant-based authentication path.

## 4. Email proof security controls

- Six digits from `random_int`, preserving leading zeroes; lifetime is at most 300 seconds and never extends the original password ceremony.
- Only a secure, explicitly supported mail transport delivers enrollment proofs. No log/array/failover transport in runtime; test Mail fakes are test-only. Proof delivery is synchronous after commit, so plaintext enrollment proofs are not queued.
- Only a password-authorized enrollment challenge can request/verify a proof; the recipient is always the current account's independently established address.
- Stored verifier is a password hash of SHA-256(context + proof). Context includes account ID, address hash, auth epoch, audience, purpose, challenge token hash and browser binding hash. The fixed 64-character digest avoids bcrypt's 72-byte truncation.
- Existing verification account/IP/challenge limits, maximum five challenge attempts, expiration, revocation, consumption and generic errors remain. Wrong proof attempts commit.
- The account lock serializes senders across browsers and portals. Minimum 120-second resend cooldown and maximum three sends per 1,200-second window survive credential-login restarts; 429 carries Retry-After. A new send revokes other outstanding identity proofs.
- Verification consumes the identity challenge and clears its verifier; setup uses a new capability with the verified email hash. Address changes, credential revocation, factor activation and stale capabilities cannot transfer that proof.
- No actual credential, email proof, TOTP key, Recovery Code, signing key or grant is included in this report. Existing no-store/error sanitization/CORS remain unchanged.

## 5. Final portal workflows and recovery

Both login pages automatically request the applicable identity method after valid password login. They present a six-digit enrollment email-code field and a cooldown-controlled resend button, followed by the local Blob QR/manual key and six-digit authenticator input. They offer no grant, multiple-approver or pending-policy input. Accounts under a security hold or without a trusted channel see an explicit support message.

Customer registration stays on its registration page after code verification and renders the restricted authenticator ceremony directly. It reuses the verified original browser binding. It neither logs in before activation nor sends redundant enrollment mail. Signed activation links retain their separate safe credentials-required contract.

The token returned by successful factor activation is tracked only in memory while codes are displayed; authoritative portal `/me` validation remains required. Ten codes are displayed once with private copy/save instructions. The Continue button requires the explicit saved-codes checkbox. Only acknowledgment promotes the pending session into normal session storage/dashboard access. Discard/cancel revokes the pending token; sensitive ceremony material is cleared. Save acknowledgment is the existing frontend gate, not a new server attestation that a user actually stored a backup.

Subsequent logins show TOTP only, with no enrollment QR/email send. A Recovery Code authorizes replacement only; existing code hashing/selectors, single-use consumption and transactional revocation remain. A fresh replacement TOTP is mandatory before token issuance, and all old codes become obsolete after successful replacement. Working-authenticator replacement and Recovery Code regeneration still require password/TOTP step-up, initiating session and intent. No disable switch or email-only recovery was added. Losing both factors and codes requires contacting support; exceptional recovery remains out of scope.

Password reset remains email-based and revokes sessions/challenges while preserving the confirmed factor, replay counter and existing Recovery Code set. It creates neither an enrollment proof nor a session. Mandatory TOTP still applies after reset. Security status, remaining-code count, notifications, current RBAC and object authorization remain.

Customer enforcing CSP, binding/CORS integration and Google Sign-In disablement are preserved. **Customer content remains temporarily English-only:** language definitions/preferences and the visible translation-unavailable notice are unchanged; no Google Translate or new third-party executable script was introduced.

## 6. Database impact and required Production configuration

The original pending `2026_11_25_100000_add_mandatory_totp_authentication.php` is unchanged. One minimum additive migration follows it:

- `users.auth_legacy_email_hash`: nullable SHA-256 of the established legacy destination.
- `users.auth_legacy_password_fingerprint`: nullable SHA-256 of the existing password hash, not the password.
- `users.auth_legacy_not_before`: nullable last pre-cutover account mutation plus 72 hours.
- `auth_challenges.identity_email_hash`: nullable SHA-256 preserving the recipient across identity → setup.

The User fields are hidden and excluded from mass assignment. No new table, account deletion, historical Nium migration or existing Authentication migration edit. The snapshot leaves user email/password/updated_at/email_verified_at untouched. Its only backfilled fields are explicitly labeled compatibility metadata. Both Authentication migrations are forward-only. Run them only after confirming legacy provenance and pausing all writers; PostgreSQL migration transactions must complete before new code serves requests.

Production prerequisites:

- Existing APP_KEY continuity for factor decryption; current supported PHP/Composer versions and already locked OTPHP/QR dependencies. Do not generate a new key.
- Real secure mail delivery and `AUTH_EXPOSE_VERIFICATION_CODE=false`; `APP_DEBUG=false`; no proof-bearing request bodies, Authorization/binding headers, QR responses or activation URLs in access/APM/queue/debug logs. Existing registration/reset debug exposure remains configuration-controlled and must be off.
- Shared durable cache/rate limiter with atomic locks (dedicated Redis recommended), valid server/device clock and trustworthy configured proxies. No array cache for actual multi-worker protection.
- Exact HTTPS Admin/Customer origins in CORS, Authorization/Content-Type/X-Auth-Client-Binding allowed, Retry-After exposed, credentials false. Preview origins require explicit environment approval; do not add wildcard origins.
- Migration/assisted policies remain disabled; no operator identities, approval keys or CLI authorization. No synthetic E2E runtime/tooling/fixtures from development are copied to the web release.
- Legacy account recently updated within 72 hours may wait on a conservative hold even for a harmless profile edit. Do not backdate records or bypass the hold to obtain a successful smoke.

## 7. Focused Operator verification commands

**None of these commands has been run by the implementation engineer.** Release worktrees currently lack Backend `vendor` and frontend `node_modules`; verification is blocked until the Operator supplies existing, lock-matching dependencies in the isolated worktrees using the separately controlled dependency-preparation procedure. No installation or dependency update was performed here.

Backend selection (SQLite in memory; private empty dotenv/cache/storage paths; outbound Laravel HTTP blocked; fake mail). RefreshDatabase runs repository migrations only against this test database:

```bash
cd /home/dtd1201/projects/api_origin_wallet_auth_release || exit
test -f vendor/autoload.php || exit
env -i PATH="$PATH" ./vendor/bin/phpunit --configuration phpunit.auth076.xml
```

Real concurrency selection: use only an already approved **disposable** local `origin_wallet_auto02b_test` database, user `dtd1201`, port 5432, with pcntl/PDO_pgsql available. The retained OwnerFirstPostgresDatabase/Auto02b reset guard checks the actual database/schema before resetting its public schema. This suite deliberately resets that disposable schema; it must never target the AUTH-06 E2E database or any retained/Production database. Missing infrastructure is a blocker, not permission to select another database.

```bash
cd /home/dtd1201/projects/api_origin_wallet_auth_release || exit
set +x
read -r -s -p 'Approved disposable PostgreSQL password: ' auth076_test_password
printf '\n'
AUTH076_DISPOSABLE_DB_PASSWORD="$auth076_test_password" ./vendor/bin/phpunit --configuration phpunit.auth076-postgres.xml
unset auth076_test_password
```

The test-only bootstrap reads only that dedicated test-password variable, never a repository dotenv or general inherited DB configuration. It hardcodes the disposable DB/loopback destination. Test directories under `/tmp/origin-auth076-tests-*` are private and contain no production credentials.

Admin:

```bash
cd /home/dtd1201/projects/admin_origin_wallet_auth_release || exit
test -d node_modules || exit
./node_modules/.bin/vitest run --config vitest.auth076.config.ts
./node_modules/.bin/tsc --noEmit -p tsconfig.app.json
./node_modules/.bin/tsc --noEmit -p tsconfig.node.json
```

Customer (includes unchanged CSP and translation-isolation tests):

```bash
cd /home/dtd1201/projects/origin-wallet-platform_auth_release || exit
test -d node_modules || exit
./node_modules/.bin/vitest run --config vitest.auth076.config.ts
./node_modules/.bin/tsc --noEmit -p tsconfig.app.json
./node_modules/.bin/tsc --noEmit -p tsconfig.node.json
```

Dedicated Vitest configs disable dotenv loading and use a synthetic HTTPS API URL. Neither command selects unrelated business tests. Published builds must subsequently be verified by the Operator under the exact intended Cloudflare build configuration; no build was executed here.

Coverage: legacy Admin/Customer with null verified timestamp and policies disabled; password-only denial; invalid/expired/replayed/cross-account/audience/browser/purpose proof; current-recipient verification and setup recheck; real snapshot semantics; code attempts/send budgets/cooldown; encrypted atomic factor activation and 10 hashed codes; enrolled-account initial-enrollment denial; TOTP-only subsequent login; direct bound registration; invalid registration attempts committing; retired grants including a persisted old setup capability; single-use recovery and replacement TOTP; password reset factor preservation; RBAC/audience/session gates. PostgreSQL tests retain actual owner-lock contention, duplicate verification, counter/login/first-factor activation and recovery/replacement/regeneration races.

Existing Admin/verification tests were selectively reused from the original Authentication test source, replacing obsolete normal-login email-code scenario methods with mandatory TOTP, same-cost generic credential failure, retired endpoint and shared-limit/replay assertions. Email-code hashing, expiry, attempt and resend protection now have dedicated enrollment-proof scenarios in the new suite; old login-resend positives are replaced by retired-endpoint denials. The password-reset test now expects the existing sanitized error rather than a raw detailed denial; password hash/token/replay assertions remain. Frontend enrollment assertions now submit `identity_proof` and preserve restricted state, QR/blob cleanup, TOTP, one-time code and save acknowledgment assertions. Grant recovery positives become recovery-code replacement cases; separate new negatives reject obsolete grant-only UI and persisted Backend grant trust. No scenario was dropped merely to obtain PASS and no test was skipped; no runtime result was obtained.

Manual joined verification after source checks pass: using synthetic accounts and isolated services only, observe each portal's legacy password → enrollment email → QR → valid TOTP → 10 codes → saved acknowledgment → dashboard; log out and verify subsequent password → TOTP without email. Also execute new Customer registration → code → QR directly, Recovery Code → replacement TOTP, reset preserving TOTP, negative origin/binding, stale/expired challenges, shared 429/Retry-After and actual served headers. No secret-bearing screenshots/HAR/logs. Automated source coverage does not prove mail delivery, multi-worker runtime configuration or browser behavior.

## 8. Exact Git allowlists and push preparation

Operator only, after verification. Confirm each worktree is still on `release/mandatory-totp-authentication` and its supplied base (Backend 88f5a0e, Admin b09d3dd, Customer 9c64675); inspect unrelated/staged changes without adding them. Do not use `git add .`, copy dirty development worktrees or merge a development branch.

```bash
cd /home/dtd1201/projects/api_origin_wallet_auth_release || exit
git branch --show-current
git status --short
git add -- app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/MandatoryAuthController.php app/Models/User.php app/Services/Auth/EnrollmentTrustPolicy.php app/Services/Auth/RegistrationService.php app/Services/Auth/TotpEnrollmentService.php app/Services/Auth/TotpRecoveryService.php config/totp.php resources/views/emails/enrollment-identity-proof.blade.php database/migrations/2026_11_26_100000_bind_enrollment_email_identity.php tests/Feature/AdminAuthTest.php tests/Feature/AuthPasswordResetTest.php tests/Feature/AuthVerificationSecurityTest.php tests/Feature/MandatoryTotpSimplificationTest.php tests/Feature/TotpPostgresConcurrencyTest.php tests/Support/IsolatedAuthTestCase.php tests/Support/MfaFixture.php tests/Support/TotpTestTools.php phpunit.auth076.xml phpunit.auth076-postgres.xml docs/auth-07-6-final-authentication-simplification.md
git diff --cached --check
git diff --cached --name-status
git diff --cached
git commit -m "Simplify mandatory authenticator enrollment with bound email proof"
git push origin HEAD:release/mandatory-totp-authentication
```

```bash
cd /home/dtd1201/projects/admin_origin_wallet_auth_release || exit
git branch --show-current
git status --short
git add -- src/components/admin/AuthCeremony.tsx src/contexts/AuthContext.tsx src/contexts/AuthContext.test.tsx src/lib/admin-auth.ts src/pages/admin/AdminLogin.tsx src/pages/admin/AdminLogin.test.tsx vitest.auth076.config.ts
git diff --cached --check
git diff --cached --name-status
git diff --cached
git commit -m "Use email ownership proof for mandatory Admin authenticator enrollment"
git push origin HEAD:release/mandatory-totp-authentication
```

```bash
cd /home/dtd1201/projects/origin-wallet-platform_auth_release || exit
git branch --show-current
git status --short
git add -- src/components/auth/AuthCeremony.tsx src/contexts/AuthContext.tsx src/contexts/AuthContext.test.tsx src/pages/CustomerAuthentication.test.tsx src/pages/Login.tsx src/pages/Register.tsx src/services/customerAuth.ts vitest.auth076.config.ts
git diff --cached --check
git diff --cached --name-status
git diff --cached
git commit -m "Simplify Customer authenticator enrollment after email verification"
git push origin HEAD:release/mandatory-totp-authentication
```

Review staged file lists against section 1 before committing. Existing unrelated staged files must be separated by the Operator. Record the three resulting full commit SHAs and test/build evidence. A push may trigger Cloudflare preview/branch deployment; it does not establish a Production cutover.

## 9. Read-only Production provenance inspection for the Operator

Do not use tinker, print dotenv/config caches, tokens, mail bodies, hashes or user rows. On the VPS, these commands inspect only source and migration definitions in the known active release:

```bash
cd /var/www/origin_wallet_releases/dynamic-d27856a || exit
sed -n '1,245p' app/Services/Auth/ApiAuthService.php
sed -n '1,220p' app/Services/Auth/RegistrationService.php
sed -n '20,155p' app/Http/Controllers/Api/AuthController.php
sed -n '175,230p' app/Http/Controllers/Api/AuthController.php
sed -n '250,310p' app/Http/Controllers/Api/AuthController.php
sed -n '25,125p' app/Http/Controllers/Api/Admin/UserController.php
sed -n '20,70p' app/Http/Controllers/Api/User/ProfileController.php
sed -n '1,100p' app/Services/Auth/GoogleTokenVerifier.php
sed -n '1,210p' app/Services/Auth/PasswordResetService.php
rg -n 'email|updateProfile|register|reset-password|login' routes/api.php routes/admin.php routes/user.php
```

Confirm both old OTP paths use resolved stored email, all supported email creation sources are trusted, no untrusted email-update path exists, and legacy credential mutations update users.updated_at. Investigate any out-of-band account scripts separately before the snapshot. The local legacy worktree is corroboration, not a substitute for this check.

Optional aggregate schema/data readiness inspection, using the Operator-confirmed Production DB name/user and hidden PostgreSQL password prompt. This is read-only and prints counts/column definitions only:

```bash
set +x
read -r -p 'Confirmed Production database name: ' auth076_prod_database
read -r -p 'Read-only PostgreSQL user: ' auth076_readonly_user
psql -X -W -h 127.0.0.1 -p 5432 -U "$auth076_readonly_user" -d "$auth076_prod_database" -v ON_ERROR_STOP=1 <<'SQL'
BEGIN READ ONLY;
SELECT table_name, column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name IN ('users','pending_registrations','pending_logins','auth_security_events')
ORDER BY table_name, ordinal_position;
SELECT count(*) AS total_accounts,
       count(*) FILTER (WHERE email IS NULL OR btrim(email) = '') AS missing_destination,
       count(*) FILTER (WHERE password_hash IS NULL OR password_hash = '') AS missing_password,
       count(*) FILTER (WHERE updated_at IS NULL) AS missing_change_timestamp,
       count(*) FILTER (WHERE updated_at > CURRENT_TIMESTAMP - INTERVAL '72 hours') AS conservative_hold_count
FROM public.users;
SELECT event_type, count(*) FROM public.auth_security_events GROUP BY event_type ORDER BY event_type;
ROLLBACK;
SQL
unset auth076_prod_database auth076_readonly_user
```

Do not infer success from failed/pending verification events or manufacture history when success records were deleted. Any provenance mismatch blocks deployment; no automatic trust fallback is prepared.

## 10. Updating the prepared VPS Authentication release without losing hotfixes

No Production operation was performed. The supplied active release remains `dynamic-d27856a`; prepared `auth-88f5a0e` still needs the new committed Authentication source and both pending migrations.

Preserve these five files byte-for-byte in the prepared release:

1. `app/Services/Nium/DynamicConfirmation/DynamicBeneficiaryDetailsResponse.php`
2. `app/Services/Nium/DynamicConfirmation/DynamicPayoutEligibility.php`
3. `app/Services/Nium/DynamicConfirmation/DynamicPayoutInstructionValidation.php`
4. `app/Services/Nium/DynamicConfirmation/DynamicTransferConfirmationService.php`
5. `app/Services/Nium/NiumBeneficiarySchemaPattern.php`

Operator procedure: fetch the verified Backend branch into the approved deployment source checkout, resolve its full SHA, and review `git diff --name-status 88f5a0e <new-SHA>` against the Backend allowlist. Any Nium/business change is a stop condition. Record checksums for those five prepared-release files privately before update. Export **only** this stage's ten runtime files from the verified commit:

```bash
# In the Operator-confirmed deployment source checkout, after fetching/reviewing:
read -r -p 'Verified full Backend Authentication commit SHA: ' auth076_backend_commit
git archive "$auth076_backend_commit" -- app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/MandatoryAuthController.php app/Models/User.php app/Services/Auth/EnrollmentTrustPolicy.php app/Services/Auth/RegistrationService.php app/Services/Auth/TotpEnrollmentService.php app/Services/Auth/TotpRecoveryService.php config/totp.php resources/views/emails/enrollment-identity-proof.blade.php database/migrations/2026_11_26_100000_bind_enrollment_email_identity.php > /tmp/auth076-runtime-source.tar
tar -tf /tmp/auth076-runtime-source.tar
tar -xf /tmp/auth076-runtime-source.tar -C /var/www/origin_wallet_releases/auth-88f5a0e
```

The archive must contain exactly those ten names. This overlay avoids a checkout/reset that could overwrite the five live hotfixes. Do not overlay vendor, dotenv, storage, bootstrap caches, test fixtures or synthetic E2E tools. Verify source checksums against the committed export, recheck all five hotfix checksums unchanged, preserve vendor/storage permissions and APP_KEY continuity, and record the new commit SHA as the deployed Authentication source version despite the directory's historical name. The original mandatory migration already in the prepared release must match the verified commit too.

For a new immutable release directory instead, populate it from the verified Authentication commit and deliberately carry forward the five attested hotfix files, existing dependency artifacts and approved environment/storage links; perform the same comparisons before switching. Do not assume a fresh checkout contains the separately copied hotfixes.

## 11. Coordinated Backend/Cloudflare cutover and rollback

1. First complete focused tests, real PostgreSQL contention tests, TypeScript, lock-matching build verification and isolated manual portal smoke. Confirm active legacy provenance, hold impact, mail exposure/transport/logging, clock, Redis locks, APP_KEY, migration plan and five hotfixes. None is attested by this source-only stage.
2. Configure both Cloudflare projects explicitly for the reviewed Authentication SHAs/branch and target environment. Build command `npm run build`, output directory `dist`, project root the respective frontend; preserve the lock's package manager and supported Node configuration. Set the exact HTTPS `VITE_API_BASE_URL` including `/api`. Existing auth-path overrides must point to that same API. No synthetic API URL, secrets or dev dotenv belong in a published build.
3. Customer Vite emits `dist/_headers`; Cloudflare Pages static hosting supports this file. Preserve it through prerender/upload, including direct/fallback account/login/register routes. Netlify.toml is not Cloudflare routing configuration. Preserve Customer `public/_redirects` behavior, confirm Admin SPA fallback in its actual project, and check real HTTP headers on every auth route. If Workers/Functions or edge response rules replace static handling, they must apply the same enforcing CSP themselves before exposure. Never loosen script-src, frame-ancestors or Blob QR compatibility to get a build/smoke working. Check existing Admin response policy permits its local code, API and Blob QR without new third-party scripts.
4. A Cloudflare branch build is not automatically Production. Prepare/verify both actual Production deployments while all three Authentication surfaces remain coordinated under the release maintenance window. Record the exact prior deployment IDs and new artifacts. Disable unintended branch auto-promotion.
5. Pause account/authentication writers and drain the actual old web/queue workers using the Operator's established service configuration. Keep financial services under their existing controls; do not change business logic. Snapshot must not race legacy user creation/password changes. Back up the database and preserve key continuity through the approved operations procedure.
6. With writers paused, apply only the two pending Authentication migrations in order through the prepared release. Operator commands, after confirming the exact target release/database:

   ```bash
   cd /var/www/origin_wallet_releases/auth-88f5a0e || exit
   php artisan migrate --path=database/migrations/2026_11_25_100000_add_mandatory_totp_authentication.php --force --no-interaction
   php artisan migrate --path=database/migrations/2026_11_26_100000_bind_enrollment_email_identity.php --force --no-interaction
   ```

   Do not run an unrestricted migration command that might include unrelated pending Nium/business migrations. Do not rerun or refresh a failed/partially applied snapshot without investigating its transaction state. No seeder/backdated verification/CLI enrollment grant is part of cutover.
7. Rebuild only the prepared release's approved Laravel caches using its correct environment and established deployment procedure, retaining separate cache paths and APP_KEY. Switch the actual Nginx document root/upstream and queue-worker release path to the prepared Authentication source together; validate/reload using the existing site/service names. Those names are not supplied here and must be resolved by the Operator, not invented. Do not let old workers keep issuing legacy tokens.
8. Promote both verified frontend artifacts to their actual Production domains. Confirm precise CORS/preflights/binding, enforcing Customer CSP including direct routes, no email-code normal login, legacy token denial, and a controlled real enrollment/subsequent TOTP login for each portal before lifting maintenance. Preserve support communication for genuine holds and missing channels; do not evade the holds.

Rollback before migration/cutover can leave the active old deployment unchanged. **After migration and mandatory-TOTP cutover, do not roll back to d27856a or an older frontend/backend that permits email/password-only authority.** Preserve all factor ciphertext, APP_KEY, epochs, counters and recovery-consumption history; no schema down, factor deletion or reset. Prefer a verified MFA-preserving forward fix or a previously verified compatible Authentication artifact. If no compatible rollback exists, keep authentication in maintenance while repairing it. Reverse Cloudflare deployments only with Backend contract compatibility; preserve the five Nium hotfixes in every Backend candidate. A database restore would need a separate security plan for replay/revocation/consumption history, not this runbook.

## 12. Remaining limitations and execution record

- Operator evidence is pending: no tests/builds/TypeScript/runtime/Production inspection or deployment were executed. Missing dependencies in these local release worktrees currently block executing verification.
- Actual Production legacy provenance and absence of out-of-band untrusted email changes still require source inspection. Stop if the compatibility assumptions do not match. No blanket verified-email backfill or historical success claim is made.
- Conservative legacy updated-at holds may temporarily block recently edited accounts. Actual mail delivery/exposure, configured cache/distributed locks, browser headers, Cloudflare Production branch/domain selection and deployment service names remain operational prerequisites.
- Exceptional lost-authenticator-and-all-codes recovery remains unimplemented by design. No single-operator recovery authority or email-only substitute was added.
- Registration activation links remain credentials-required for safe browser binding; direct registration-code verification is the streamlined original-browser path. Customer translation remains temporarily unavailable.
- Existing broad historical business tests are outside this focused stage; no broad-suite PASS or closure of prior unrelated findings is claimed. Tests from original development were selectively reused as source only; accepted historical suites were not rerun.

No Nium/business source, Financial Authorization, wallet/FX/transfer/beneficiary/KYC/RFI/SGD routes or policies was modified. The five named hotfix files were untouched. Original development repositories were read only and were not modified. Every source addition/modification used apply_patch. No tests, builds, lint, TypeScript checks, Git commands/commit/push/merge, migrations, seeders, package installation/update, Production/SSH commands, servers, service restarts, provider/external API calls or Cloudflare deployment was executed. No real secrets, Production dotenv files or resolved Production configuration were accessed, printed or modified.
