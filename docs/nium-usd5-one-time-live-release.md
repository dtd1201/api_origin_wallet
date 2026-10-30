# One-time GREENTECH → Andy USD 5 Production test

**Admin Approve can reach the existing NIUM Production Remittance transport: YES, after the exact authorization is staged and activated and the retained checks pass.** No confirmed quote, complete fee schedule, charge ceiling, separate funding credit or fee reserve is required for this expressly authorized test. This supersedes the fee-ceiling prerequisites in the previous funded-pilot report for this test only. No live NIUM call, Production change, deployment, commit or push was performed by Codex.

## Exact blockers and change

1. Ordinary Financial Admission: `NiumSgdLocalPayoutGate::assertSubmissionAllowed()` calls `NiumPayoutFinancialControls::assertFor()`. Its ordinary branch requires a confirmed, complete, intent-bound, unexpired all-in quote whose zero source-wallet fees make total debit equal principal. `NiumConfiguredRemittancePricing` cannot establish those facts and returns unavailable. **Neither file's ordinary pricing behavior changed in this increment.**
2. Previous pilot admission: `NiumPayoutPilotPolicy::authorize()`/`terms()` required a separately posted funding credit, a binding all-charges ceiling and a protected reserve. That check was repeated through `NiumSgdLocalPayoutAuthorization::reviewSnapshot()`/`assertDispatchSnapshot()` immediately before `NiumService::post()` allowed transport. The frontend also required a positive charge reserve to enable maker approval.
3. The same durable pilot authorization now supports `policy=one_time_uncapped`. `authorizeOneTime()` and the local `nium:authorize-payout-pilot` command record the explicit business authorization reference and `additional_nium_charges_are_unknown_uncapped_and_not_reserved`. The existing selected-pilot branch returns principal-only terms before calling the ordinary pricing adapter. Fee, total debit, reserve and ceiling are **null**, not zero. Existing funded-authorizations retain their original conditions and history.

The new mode requires **exactly USD 5**, GREENTECH user **12**, Andy beneficiary **9**, USD→SGD payout, authenticated current NIUM source wallet, active verified/reconciled corporate account, owned active registered SG/SGD Individual LOCAL beneficiary, a supported truthful purpose and fresh attached NIUM FX lock. The grant freezes the local transfer ID/number/client reference, authenticated client/customer/wallet/base URL, external beneficiary, purpose, principal and FX quote/audit/rate/expiry. Verify the Production identity labels against these IDs before use. The grant's unique exercise and transfer identities prevent reuse for another payment; an existing grant cannot be replaced by this command.

The local authorizer must be active, have `transfers.approve` and be independent of the maker. Staging creates **no payment approval, provider request or fund hold**. The maker then reviews the exact terms and requests approval. A separately authenticated independent Admin uses the existing Approve action. The Admin may also be the local authorizer; neither may be the maker.

The principal is reserved atomically with the existing durable operation claim and grant consumption. `NiumService::post()` still checks the approved snapshot, owned USD 5 hold, payload/path, consumed audit and single-dispatch marker before using the existing `ProviderHttpClient` transport. The wire payload contains `sourceAmount: 5`, `sourceCurrency: USD` and the genuine attached numeric `auditId`. The locked effective FX rate is unchanged, no markup is added to principal, and NIUM performs FX. Duplicate approval and uncertain outcomes cannot send another POST.

## Accepted exposure and financial recovery

**USD 5 caps principal only. There is no verified maximum total NIUM debit and no fee reserve.** Additional charges can exceed USD 5 or the remaining wallet balance, cause insufficient-funds rejection, create a funding shortfall or make Origin's reconciled balance negative. Final SGD beneficiary credit is not guaranteed by the displayed conversion estimate. This authorization accepts the financial exposure; it does not establish provider acceptance, zero fees, an all-in price, or a provider waiver.

Existing authenticated wallet-activity reconciliation records the verified principal and each separately evidenced settled USD fee linked to this exact remittance as append-only entries. Duplicate evidence cannot charge twice. New-mode fees use available funds, with no invented reserve coverage; actual liability remains recorded even if the balance becomes negative. The API/UI shows observed additional charges separately, `pilot_charge_exposure=uncapped`, and `pilot_additional_charges_observed`. `pilot_charge_ceiling_breached` is null because no ceiling exists. Absence of fee rows never confirms zero fees or charge completeness.

Accounting remains subject to the existing evidence and completed-payout checks. Rejected/returned/unknown outcomes, missing or conflicting wallet references, unproven refunds and charges outside the supported USD evidence contract require existing controlled financial review. The principal hold remains unresolved rather than being released from payout status alone. Recovery/reconciliation continues after disabling the pilot or expiry. An unknown outcome never authorizes another POST, a new client reference, deleting the grant or resetting its operation/audit identity.

## Release commands — operator only

These are exact commands for the repository's documented VPS layout: `/var/www/origin_wallet`, deployment user `deploy`, runtime user `www-data`, `/usr/bin/php8.3`, `php8.3-fpm`, Supervisor group `origin_wallet_worker`. Confirm that layout and the actual host before running. The Cloudflare Pages project and Production branch are not recorded in the repository, so the operator supplies their existing values. No new deployment target is assumed.

Because the isolated candidates contain uncommitted and untracked files, deploying their unchanged Git HEAD would omit the implementation. The following packages the complete reviewed backend candidate, including new files, without Git metadata, local dependencies, runtime data or environment secrets. Use the normal database backup and maintenance window. Drain existing in-flight operations and stop all additional application writers if this installation has runtimes beyond the documented worker/FPM/cron.

On the release machine, after reviewing the cumulative candidate:

```bash
set -euo pipefail
cd /home/dtd1201/projects/api_origin_wallet_sgd_phase3
mkdir -p storage/app/payout-pilot-release
tar --exclude='./.git' --exclude='./.env' --exclude='./.env.*' \
  --exclude='./auth.json' --exclude='./vendor' --exclude='./node_modules' \
  --exclude='./storage' --exclude='./bootstrap/cache' --exclude='./.phpunit.result.cache' \
  -czf storage/app/payout-pilot-release/backend.tgz .
sha256sum storage/app/payout-pilot-release/backend.tgz
read -r -p 'Existing Production SSH target (user@host): ' NIUM_PILOT_SSH_TARGET
test -n "$NIUM_PILOT_SSH_TARGET"
scp storage/app/payout-pilot-release/backend.tgz "$NIUM_PILOT_SSH_TARGET:/tmp/origin-wallet-payout-pilot-backend.tgz"
```

On that VPS, verify the transferred SHA-256 against the reviewed artifact. Then run in one persistent Bash session:

```bash
set -euo pipefail
cd /var/www/origin_wallet
sha256sum /tmp/origin-wallet-payout-pilot-backend.tgz
read -r -p 'Reviewed artifact SHA-256: ' NIUM_PILOT_ARTIFACT_SHA256
[[ "$NIUM_PILOT_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]]
test "$(sha256sum /tmp/origin-wallet-payout-pilot-backend.tgz | cut -d ' ' -f 1)" = "$NIUM_PILOT_ARTIFACT_SHA256"
sudo -u www-data /usr/bin/php8.3 artisan down --retry=60
sudo supervisorctl stop 'origin_wallet_worker:*'
sudo systemctl stop cron.service php8.3-fpm.service
sudo -u deploy tar --no-same-owner -xzf /tmp/origin-wallet-payout-pilot-backend.tgz -C /var/www/origin_wallet
sudo -u deploy composer install --no-dev --optimize-autoloader --no-scripts
sudo chgrp -R www-data app bootstrap config database public resources routes vendor
sudo find app bootstrap config database public resources routes vendor -type d -exec chmod g+rx {} +
sudo find app bootstrap config database public resources routes vendor -type f -exec chmod g+r {} +
sudo chown -R www-data:www-data storage bootstrap/cache
```

Define this helper in that same VPS session. It modifies only the named non-secret controls and never prints `.env` or NIUM credentials. Keep the existing authenticated Production NIUM origin, client, credentials, wallet mapping, VA flags and other transfer policies intact.

```bash
nium_pilot_set() {
  sudo -u deploy python3 - "$1" "$2" <<'PY'
from pathlib import Path
import re, sys
key, value = sys.argv[1:]
allowed = {'NIUM_PAYOUT_PILOT_ENABLED', 'NIUM_PAYOUT_PILOT_AUTHORIZATION_ID',
           'NIUM_PAYOUT_FX_ENABLED', 'WALLET_LEDGER_ENABLED', 'NIUM_TRANSFER_RECONCILIATION_ENABLED'}
assert key in allowed
assert (not value or re.fullmatch(r'[a-f0-9-]{36}', value)) if key.endswith('_ID') else value in ('true', 'false')
p = Path('.env')
s = re.sub(r'^' + re.escape(key) + r'=.*\n?', '', p.read_text(), flags=re.M)
p.write_text(s.rstrip('\n') + '\n' + key + '=' + value + '\n')
PY
}
nium_pilot_set NIUM_PAYOUT_PILOT_ENABLED false
nium_pilot_set NIUM_PAYOUT_PILOT_AUTHORIZATION_ID ''
nium_pilot_set NIUM_PAYOUT_FX_ENABLED true
nium_pilot_set WALLET_LEDGER_ENABLED true
nium_pilot_set NIUM_TRANSFER_RECONCILIATION_ENABLED true
sudo -u www-data /usr/bin/php8.3 artisan config:clear
sudo -u www-data /usr/bin/php8.3 artisan package:discover --ansi
sudo -u www-data /usr/bin/php8.3 artisan migrate:status
# Review all pending migrations before this release operation.
sudo -u www-data /usr/bin/php8.3 artisan migrate --force
sudo -u www-data /usr/bin/php8.3 artisan config:cache
sudo -u www-data /usr/bin/php8.3 artisan route:cache
sudo -u www-data /usr/bin/php8.3 artisan tinker --execute='throw_if(! app()->environment("production"), RuntimeException::class, "Production runtime required"); dump(["nium_origin" => config("services.nium.base_url"), "pilot_enabled" => config("services.nium.payout_pilot.enabled"), "fx_enabled" => config("services.nium.payout_fx_enabled"), "ledger_enabled" => config("wallet.ledger.enabled")]);'
sudo systemctl start php8.3-fpm.service cron.service
sudo supervisorctl start 'origin_wallet_worker:*'
sudo -u www-data /usr/bin/php8.3 artisan up
```

Expected before activation: actual Production NIUM origin (the repository template is `https://gateway.nium.com`), pilot=false, FX=true, ledger=true. Do not run allocation, wallet conversion, seed, key-generation or pricing-refresh commands. This release adds `2026_10_30_231000_allow_explicit_uncapped_nium_pilot_authorization`; it also needs the earlier pilot/evidence/ledger migrations from the cumulative candidate. Existing funded grants are preserved. Do not roll back/delete a populated pilot authorization table to repeat the exercise.

Build and publish the reviewed frontend while the backend pilot remains disabled:

```bash
cd /home/dtd1201/projects/origin-wallet-platform_sgd_phase3
set -euo pipefail
npm ci
VITE_API_BASE_URL=https://api.originwallet.asia/api npm run build
npx --yes wrangler@4 pages project list
read -r -p 'Existing Cloudflare Pages project: ' NIUM_PILOT_PAGES_PROJECT
read -r -p 'Its Production branch: ' NIUM_PILOT_PAGES_BRANCH
test -n "$NIUM_PILOT_PAGES_PROJECT"
test -n "$NIUM_PILOT_PAGES_BRANCH"
npx --yes wrangler@4 pages deploy dist --project-name "$NIUM_PILOT_PAGES_PROJECT" --branch "$NIUM_PILOT_PAGES_BRANCH"
npx --yes wrangler@4 pages deployment list --project-name "$NIUM_PILOT_PAGES_PROJECT"
```

Use the existing authenticated Cloudflare session/scoped token. Confirm the listed deployment is Production on Origin Wallet's existing domain. No frontend project ID or deployed revision has been independently verified by Codex.

## Exact activation and one payment

1. In Origin Wallet, confirm user 12 is GREENTECH and owned active beneficiary 9 is Andy. Confirm the authenticated source wallet's available USD balance covers at least USD 5 after existing reservations, and its balance is current (the pilot retains the five-minute freshness check). Resolve existing ledger/provider discrepancies through the established process. **No fee ceiling or separate reserve proof is required.**
2. GREENTECH enters **USD 5**, chooses Andy, and selects/confirms a truthful supported purpose. Use the existing preparation screen to save one draft and explicitly acquire a fresh USD/SGD NIUM lock. Keep its transfer ID and client reference. A draft without a fresh lock cannot be authorized. Do not use the generic create/submit route to bypass this workflow.
3. On the VPS, in the same shell containing `nium_pilot_set`, stage that exact draft. The reference below identifies this explicit business authorization, not provider fee evidence. Use an active authorized local authorizer other than user 12:

   ```bash
   cd /var/www/origin_wallet
   read -r -p 'Exact saved USD 5 draft transfer ID: ' NIUM_PILOT_TRANSFER_ID
   read -r -p 'Independent active authorizer user ID: ' NIUM_PILOT_AUTHORIZER_ID
   [[ "$NIUM_PILOT_TRANSFER_ID" =~ ^[1-9][0-9]*$ ]]
   [[ "$NIUM_PILOT_AUTHORIZER_ID" =~ ^[1-9][0-9]*$ ]]
   test "$NIUM_PILOT_AUTHORIZER_ID" != 12
   sudo -u www-data /usr/bin/php8.3 artisan nium:authorize-payout-pilot \
     "$NIUM_PILOT_TRANSFER_ID" "$NIUM_PILOT_AUTHORIZER_ID" \
     'ORIGIN-WALLET-ONE-TIME-GREENTECH-ANDY-USD5-2026-10-30' \
     --accept-uncapped-charges
   ```

   This command makes no provider request. Copy its printed UUID. If a grant already exists, or the FX audit expires, stop and resolve that existing exercise explicitly; never delete/rebind it or update timestamps to force a retry.

4. Activate only that UUID and refresh runtime configuration:

   ```bash
   read -r -p 'Printed one-time authorization UUID: ' NIUM_PILOT_AUTHORIZATION_ID
   [[ "$NIUM_PILOT_AUTHORIZATION_ID" =~ ^[a-f0-9-]{36}$ ]]
   sudo -u www-data /usr/bin/php8.3 artisan down --retry=60
   sudo supervisorctl stop 'origin_wallet_worker:*'
   sudo systemctl stop cron.service php8.3-fpm.service
   nium_pilot_set NIUM_PAYOUT_PILOT_AUTHORIZATION_ID "$NIUM_PILOT_AUTHORIZATION_ID"
   nium_pilot_set NIUM_PAYOUT_PILOT_ENABLED true
   sudo -u www-data /usr/bin/php8.3 artisan config:cache
   sudo systemctl start php8.3-fpm.service cron.service
   sudo supervisorctl start 'origin_wallet_worker:*'
   sudo -u www-data /usr/bin/php8.3 artisan up
   ```

5. GREENTECH refreshes the **same** preparation, sees unknown/uncapped fees and no fee reserve, confirms the truthful purpose and presses **Submit for Admin Approval**. The frontend uses the current bound `financial_terms_token`; no pricing refresh or confirmed quote is needed. An independent active Admin with `transfers.approve` logs in separately and presses **Approve** before FX/grant expiry. The existing endpoint is `POST /api/admin/transfers/{transferId}/approve`. **That action is the live Remittance dispatch.** Do not perform another customer Submit.
6. After the attempt, switch admission off without disturbing the durable operation or accounting history:

   ```bash
   nium_pilot_set NIUM_PAYOUT_PILOT_ENABLED false
   sudo -u www-data /usr/bin/php8.3 artisan config:cache
   sudo systemctl restart php8.3-fpm.service
   sudo supervisorctl restart 'origin_wallet_worker:*'
   ```

   Keep reconciliation enabled and retain the authorization UUID. Disabling the flag cannot cancel an in-flight POST. The consumed authorization prevents a second attempt even before the configuration refresh completes.
7. Follow existing captured-outcome recovery and read-only status/financial reconciliation to authoritative payout status, actual USD principal/fees and Andy's SGD receipt. The existing scheduled command is `sudo -u www-data /usr/bin/php8.3 artisan nium:reconcile-transfers --limit=50`; it reads statuses/wallet activity for eligible existing transfers and never submits a Remittance POST. A missing-reference unknown outcome still needs the existing captured-outcome/controlled recovery path. Reconcile separately posted charges and funding shortfalls; do not infer zero fees or release unknown holds.

## Changed files and validation

Backend changes in this increment:

- `app/Services/Nium/NiumPayoutPilotPolicy.php`
- `app/Console/Commands/AuthorizeNiumPayoutPilot.php`
- `database/migrations/2026_10_30_231000_allow_explicit_uncapped_nium_pilot_authorization.php`
- `app/Services/Nium/NiumSgdLocalPayoutWorkflow.php`
- `app/Services/Nium/NiumPayoutDebitReconciliation.php`
- `app/Services/Nium/NiumSgdLocalPayoutAmounts.php`
- `tests/Feature/NiumPayoutPilotPolicyTest.php`
- `tests/Feature/NiumRemittanceExecutionPostgresTest.php`
- `docs/nium-usd5-principal-only-pilot.md` (supersession notice)
- `docs/nium-usd5-one-time-live-release.md` (this report)

Frontend changes in this increment:

- `src/services/moneyMovementService.ts`
- `src/pages/account/SgdLocalPayoutWorkflow.tsx`
- `src/pages/account/SgdLocalPayoutWorkflow.test.tsx`
- `src/pages/account/SgdLocalAmountBreakdown.tsx`
- `src/pages/account/SgdLocalAmountBreakdown.test.tsx`

Focused final validation:

| Check | Result |
| --- | --- |
| Offline SQLite pilot/SGD execution, reconciliation, review, presentation and Remittance safety selection | **161 tests, 1,328 assertions pass** |
| Disposable PostgreSQL migration/execution/concurrency suite, including uncapped and funded competing approvals | **20 tests, 327 assertions pass** |
| Frontend workflow and amount-breakdown selection | **46 tests pass** |
| Pint on all eight changed PHP files | Pass |
| Focused frontend ESLint and both repositories' whitespace checks | Pass |
| Frontend production build/prerender | Pass (existing bundle-size/Browserslist warnings) |
| Local command registration/signature; all seven release/activation Bash blocks and embedded Python syntax | Pass; release commands were parsed only |

The Admin API test uses the real application approval/manager/service/HTTP-client stack with HTTP faked only at the transport boundary. It asserts the authenticated Remittance URL, header and unchanged USD 5/audit wire payload. The pricing adapter is forbidden from receiving `quote()` for the authorized transfer; a copied intent still uses the ordinary unavailable-pricing branch and is denied. Duplicate Admin approval produces one provider request log, one approval and one principal reservation. Timeout outcomes consume the grant and retain the principal hold. The uncapped-fee test records a USD 1.25 adjustment after the USD 5 principal when only USD 5 was initially available, leaving a visible negative balance, without duplicate accounting or invented fee confirmation.

SQLite used `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=` and `vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php`, selecting `NiumPayoutPilotPolicyTest`, `NiumSgdLocalExecutionTest`, `NiumPayoutDebitReconciliationTest`, `NiumSgdLocalPayoutWorkflowTest`, `NiumSgdLocalPayoutPresentationTest` and `NiumRemittanceExecutionSafetyTest` from `tests/Feature/`. PostgreSQL used the existing `tests/PayoutPostgresBootstrap.php` guard and `NiumRemittanceExecutionPostgresTest` solely against `/tmp/origin-wallet-p0-pg`, port 55439, database `nium_p0_execution_test`; the disposable cluster had TCP disabled and was stopped after testing. Both bootstraps reject unfaked HTTP. Frontend used `npm test -- src/pages/account/SgdLocalPayoutWorkflow.test.tsx src/pages/account/SgdLocalAmountBreakdown.test.tsx` and `npm run build`.

This is a focused regression result, not a full-suite claim. No fixture is live NIUM acceptance, real charge evidence or confirmation of the Production account balance. Earlier candidate changes remain preserved. Remaining operational inputs are the real host/Pages project, exact saved transfer ID and grant UUID, actual active authorizer/checker identities, current authenticated principal balance, truthful purpose and fresh NIUM FX lock. A fee ceiling, separately funded reserve and confirmed ordinary pricing are **not remaining prerequisites** for this explicitly authorized mode.
