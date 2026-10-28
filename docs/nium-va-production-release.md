# NIUM VA Production release and runtime cutover

**NO-GO for activation until tests pass and operators verify the runtime cutover.**
No tests, deployments, migrations, Production changes or NIUM requests were run
while preparing this release. Baseline `45c1f75` already contains the legacy
evidence/ownership fixes. This release adds admission, not a claim that old
Production runtimes have stopped.

## Admission and ownership

| Environment key | Default | Purpose |
| --- | --- | --- |
| `NIUM_VA_PRODUCTION_ADMISSION_ENABLED` | `false` | Admits fresh requests in Production |
| `NIUM_VA_ALLOCATION_PAUSED` | `true` | Independent emergency stop for fresh allocation |

Production requires admission=true and pause=false. Changing APP_ENV is not an
activation procedure. Historical `enabled`, `account_ids` and `old_workers_fenced`
config entries remain isolated test controls; none authorizes Production or
proves cutover. There is no Production account allowlist.

The existing read-only detail continuation now accepts Production independently
of both allocation gates. The existing Supervisor worker consumes its existing
`nium-va-details` queue as well as `default`, using `/usr/bin/php8.3` as www-data.
No new queue or scheduler is added. Lost deliveries can be recovered with the
existing `nium:recover-va-details` command after the runtime cutover.

Only new USD / SELF_FUNDING_ACCOUNT / JPM_SG / WIRES requests are admitted. Current
account ownership, verified customer/wallet IDs, clear provider status and all
restriction/security checks still apply. New unsupported Production requests,
including explicit V2 requests, fail before operation creation or HTTP. Disabled
admission cannot fall back to legacy V2. Historical preflight failures cannot
become new Production POSTs. Other saved operations retain frozen identities,
versions, evidence and recovery behavior.

```text
POST /api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId
{"accountCategory":"SELF_FUNDING_ACCOUNT","currencyCode":"USD","bankName":"JPM_SG"}
```

WIRES is local intent; there is no `accountType` in the V1 body and no prerequisite
GET. Committed dispatch authorization still permits one transport attempt.
Timeout/UNKNOWN/delayed acceptance never automatically reposts. Admission and
pause do not disable authenticated webhook receipt, saved state reads or GET V2
reconciliation/details recovery. Pause cannot revoke already-consumed dispatch
authorization or cancel a POST already in flight.

Legacy reconciliation, correlated legacy webhooks and ledger resolvers retain
account-first locking, the shared Payment ID transaction advisory lock and global
ownership conflict checks. Original-target evidence must satisfy the immutable
legacy evidence contract; current mapping alone is insufficient. Ambiguous or
conflicting history remains visible for manual review, without reassignment.

## User-run validation

Use local/disposable databases without cached Production config. These cases use
fake NIUM HTTP; SQLite admission tests do not prove PostgreSQL concurrency.

```bash
cd /home/dtd1201/projects/api_origin_wallet
vendor/bin/phpunit tests/Feature/NiumVirtualAccountCorridorTest.php tests/Feature/NiumVirtualAccountV1AdapterTest.php tests/Feature/NiumVirtualAccountSingleDispatchTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php tests/Feature/NiumVirtualAccountDetailEnrichmentTest.php tests/Feature/NiumLegacyVirtualAccountEvidenceTest.php tests/Feature/NiumPaymentIdServiceTest.php tests/Feature/NiumVirtualAccountEligibilityProjectionTest.php

cd /home/dtd1201/projects/origin-wallet-platform
npm ci
npm run test -- src/lib/virtualAccountLifecycle.test.ts src/pages/account/AccountVirtualAccountRequest.test.tsx
npm run test:va:browser
```

For the existing pre-migrated disposable PostgreSQL cluster, enter its actual
socket and role. Do not use Production. `phpunit.xml` otherwise defaults to SQLite.

```bash
cd /home/dtd1201/projects/api_origin_wallet
read -r -p 'Disposable socket directory (/tmp/nium-va-ledger-pg.*): ' NIUM_RELEASE_PG_SOCKET
read -r -p 'Disposable PostgreSQL test role: ' NIUM_RELEASE_PG_ROLE
case "$NIUM_RELEASE_PG_SOCKET" in /tmp/nium-va-ledger-pg.*) ;; *) exit 1 ;; esac
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST="$NIUM_RELEASE_PG_SOCKET" DB_PORT=5432 \
DB_DATABASE=nium_va_ledger_test DB_USERNAME="$NIUM_RELEASE_PG_ROLE" DB_PASSWORD= DB_URL= \
NIUM_VA_LEDGER_PG_TESTS=1 vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php \
  --filter 'legacy_and_ledger_reconciliation|two_legacy_resolvers|mixed_legacy_and_ledger_webhook|collision_waiter'
```

## Required operational fence

The documented layout is a dedicated Ubuntu VPS, `/var/www/origin_wallet`, PHP
8.3 FPM, Supervisor program `origin_wallet_worker`, deployment owner `deploy` and
FPM/worker user `www-data`. Confirm the real layout first. Inventory every host,
container, CLI session, cron/timer, queue worker and deployment launcher with
access to this app/NIUM credentials. If another writer cannot be stopped and its
restart prevented, leave admission OFF. An incomplete inventory is a blocker.

The enforceable condition is that old runtimes and their launchers are stopped,
and only the reviewed participating release can execute afterward. A boolean,
SHA on disk, empty queue, queue:restart signal, graceful FPM reload or empty
database-session list cannot prove this. Already-loaded PHP and detached/remote
CLI jobs may survive them. Disable old release paths and deployment automation
on every inventoried host. Do not claim a new application flag fences old code.

Coordinate a short maintenance window. Stopping FPM temporarily interrupts API
and webhook receipt; arrange delivery/recovery handling for the window rather
than assume NIUM redelivers everything. Once upgraded FPM restarts with allocation
OFF, webhook/GET recovery remains available. Never replay POSTs to compensate for
interrupted work. Preserve consumed/uncertain operations for evidence review.

## Exact VPS commands, keep allocation OFF

These are operator instructions, not commands executed by the engineer. Use a
reviewed release SHA including this change, committed/pushed separately by the
owner; baseline `45c1f75` alone does not include admission. Take the normal DB
backup first. Execute in one persistent Bash session on the documented dedicated
host. If other applications share these services, coordinate their stop first.
Retain inventory, stop, process-start and revision output in the change record.

```bash
set -euo pipefail
cd /var/www/origin_wallet
read -r -p 'Reviewed backend release commit (full SHA): ' NIUM_RELEASE_REVISION
[[ "$NIUM_RELEASE_REVISION" =~ ^[0-9a-f]{40}$ ]]
sudo -u deploy git fetch --prune
test -z "$(sudo -u deploy git status --porcelain)"
test "$(sudo -u deploy git cat-file -t "$NIUM_RELEASE_REVISION")" = commit
sudo -u deploy git merge-base --is-ancestor 45c1f75 "$NIUM_RELEASE_REVISION"
sudo supervisorctl status
sudo systemctl list-timers --all
sudo systemctl list-units --type=service --all
sudo rg -n 'artisan|origin_wallet|php' /etc/cron.d /etc/crontab /etc/systemd/system /etc/supervisor /var/spool/cron/crontabs || true
ps -eo user,pid,ppid,lstart,args
```

Review before continuing. Stop/mask additional services, remote jobs and automated
launchers found by the inventory. The following masks known restart sources,
waits for the known worker and stops FPM completely, rather than reloads it.

```bash
sudo -u www-data /usr/bin/php8.3 artisan down --retry=60
sudo systemctl mask --runtime --now cron.service
sudo supervisorctl stop 'origin_wallet_worker:*'
sudo systemctl mask --runtime --now supervisor.service php8.3-fpm.service
if pgrep -a -x 'php([0-9.]+)?|php-fpm([0-9.]+)?|frankenphp|rr'; then
  echo 'STOP: identify and drain remaining app runtimes.' >&2
  exit 1
fi
sudo -u postgres psql -X -d origin_wallet -c "SELECT pid, client_addr, application_name, state, backend_start FROM pg_stat_activity WHERE usename = 'origin_wallet_user';"
sudo systemctl is-enabled cron.service supervisor.service php8.3-fpm.service || true
```

Verify all three units are masked and there are no unexplained app processes or
DB sessions, including other hosts. A process not yet connected to PostgreSQL
will not appear in that SQL output; launcher/process inventory is still required.
If shutdown kills/times out a worker, preserve its operation as potentially
accepted by NIUM. Killing a process/session is not evidence that no POST arrived.

```bash
sudo -u deploy git switch --detach "$NIUM_RELEASE_REVISION"
sudo -u deploy composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
sudo chgrp -R www-data app bootstrap config database public resources routes vendor
sudo find app bootstrap config database public resources routes vendor -type d -exec chmod g+rx {} +
sudo find app bootstrap config database public resources routes vendor -type f -exec chmod g+r {} +
sudo chown deploy:www-data .env
sudo chmod 0640 .env
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} +
sudo find storage bootstrap/cache -type f -exec chmod 0660 {} +

# Define for this session; edit only allocation keys, without printing secrets.
nium_va_set_allocation() {
  sudo -u deploy python3 - "$1" "$2" <<'PY'
from pathlib import Path
import re, sys
p = Path('.env')
s = p.read_text()
for key, value in zip(('NIUM_VA_PRODUCTION_ADMISSION_ENABLED', 'NIUM_VA_ALLOCATION_PAUSED'), sys.argv[1:]):
    assert value in ('true', 'false')
    s = re.sub(r'^' + key + r'=.*\n?', '', s, flags=re.M)
    s = s.rstrip('\n') + '\n' + key + '=' + value + '\n'
p.write_text(s)
PY
}
nium_va_set_allocation false true

# Laravel cache writers run as FPM/worker user, never root or deploy.
sudo -u www-data /usr/bin/php8.3 artisan config:clear
sudo -u www-data /usr/bin/php8.3 artisan package:discover --ansi
sudo -u www-data /usr/bin/php8.3 artisan config:cache
sudo -u www-data /usr/bin/php8.3 artisan migrate:status
sudo -u www-data /usr/bin/php8.3 artisan tinker --execute='dump(["env" => app()->environment(), "admission" => config("services.nium.va_single_dispatch.production_admission_enabled"), "paused" => config("services.nium.va_single_dispatch.allocation_paused"), "driver" => DB::getDriverName(), "isolation" => DB::selectOne("SHOW transaction_isolation")->transaction_isolation]);'
sudo -u www-data /usr/bin/php8.3 artisan tinker --execute='dump(["queue" => config("queue.default"), "queue_db" => config("queue.connections.database.connection"), "queue_table" => config("queue.connections.database.table"), "queue_name" => config("queue.connections.database.queue"), "retry_after" => config("queue.connections.database.retry_after")]);'
```

Expected: env=production, admission=false, paused=true, driver=pgsql,
isolation=read committed. Existing ledger and immutable exchange/webhook evidence
migrations, including `2026_10_28_000002_preserve_nium_legacy_va_targets`, must be
Ran. This release adds no migration. If prior migrations are missing, stop and
review them; do not blindly migrate the whole app. Review unresolved/ambiguous
operations and global duplicate ownership without rewriting frozen history.
Do not use `config:show services`, which exposes secrets.
The documented worker setup also requires queue=database, queue_db=null (the
default app DB) or its exact configured default DB connection, queue_table=jobs,
queue_name=default and retry_after=90, greater than the 60-second worker timeout.
If the real deployment uses another broker/database, stop and adapt the worker
to the existing DB-backed continuation; do not claim a Redis/default-only worker
consumes its jobs.

```bash
# This release pins the existing worker to PHP 8.3 and both existing queues.
test -x /usr/bin/php8.3
sudo install -o root -g root -m 0644 deploy/supervisor/api.originwallet.asia-worker.conf /etc/supervisor/conf.d/origin_wallet-worker.conf
sudo systemctl unmask --runtime php8.3-fpm.service supervisor.service
sudo systemctl start php8.3-fpm.service supervisor.service
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status 'origin_wallet_worker:*'
sudo systemctl show php8.3-fpm.service supervisor.service -p ActiveState -p MainPID -p ExecMainStartTimestamp
ps -eo user,pid,ppid,lstart,args
sudo -u deploy git rev-parse HEAD
namei -l /var/www/origin_wallet/bootstrap/cache/config.php
sudo -u www-data /usr/bin/php8.3 artisan up
```

Confirm FPM pool and worker user=www-data, process start times after deployment,
and all launchers pointing only to the reviewed release. Restart cron and any
additional inventoried services only after reviewing their launch paths:

```bash
sudo systemctl unmask --runtime cron.service
sudo systemctl start cron.service
```

Allocation stays OFF/paused. Inspect existing authenticated account/status pages
and webhook ingress configuration without issuing allocation commands. No config
field automatically verifies the external cutover facts.
After verifying the new workers and historical ownership, the existing command
can restore missed banking-detail continuations while allocation stays OFF:

```bash
sudo -u www-data /usr/bin/php8.3 artisan nium:recover-va-details --limit=100
```

It enqueues existing committed attachments for GET V2 only; it cannot allocate or
repost. The worker now consumes `default,nium-va-details`. Default-queue work has
priority; if it is continuously saturated, details can be delayed. No new worker
topology is assumed by this release.

## Exact Cloudflare Pages commands

No Cloudflare project/Production branch is recorded in this repository. Select
the existing project from the list and verify its Production branch in that
project's Cloudflare Pages build settings. On the build machine, check out the reviewed frontend
revision including the existing corridor UI changes. Authenticate Wrangler or
use an operator-provided scoped token without storing it in the repository.
Deploy while backend allocation remains OFF.

```bash
cd /home/dtd1201/projects/origin-wallet-platform
set -euo pipefail
git status --short
git rev-parse HEAD
npm ci
npm run test -- src/lib/virtualAccountLifecycle.test.ts src/pages/account/AccountVirtualAccountRequest.test.tsx
VITE_API_BASE_URL=https://api.originwallet.asia/api npm run build
# If not already authenticated with a scoped token: npx --yes wrangler@4 login
npx --yes wrangler@4 pages project list
read -r -p 'Existing Cloudflare Pages project: ' NIUM_RELEASE_PAGES_PROJECT
read -r -p 'Its Production branch: ' NIUM_RELEASE_PAGES_BRANCH
test -n "$NIUM_RELEASE_PAGES_PROJECT"
test -n "$NIUM_RELEASE_PAGES_BRANCH"
npx --yes wrangler@4 pages deploy dist --project-name "$NIUM_RELEASE_PAGES_PROJECT" --branch "$NIUM_RELEASE_PAGES_BRANCH"
npx --yes wrangler@4 pages deployment list --project-name "$NIUM_RELEASE_PAGES_PROJECT"
```

Verify the listed deployment is Production on the existing custom domain. Create
VA must show only USD/self-funding/Wires for new requests and the server's
disabled/paused reason. Saved requests retain original parameters and GET status
checking. This UI check requires no NIUM call. Prevent another Cloudflare build
trigger from overwriting the reviewed revision with an older frontend.

## Controlled activation, after technical-lead review of cutover evidence

Only after test results, complete process/launcher inventory, stop/restart
records, reviewed SHAs, schema/isolation and historical ownership are accepted,
the operator can switch admission. These commands are not executed by this task.
Use the same VPS Bash session/functions. Additional discovered runtime services
must be stopped/restarted too; do not treat these three units as a universal list.

```bash
cd /var/www/origin_wallet
sudo -u www-data /usr/bin/php8.3 artisan down --retry=60
sudo systemctl mask --runtime --now cron.service
sudo supervisorctl stop 'origin_wallet_worker:*'
sudo systemctl stop supervisor.service php8.3-fpm.service
nium_va_set_allocation true false
sudo -u www-data /usr/bin/php8.3 artisan config:cache
sudo -u www-data /usr/bin/php8.3 artisan tinker --execute='dump(["env" => app()->environment(), "admission" => config("services.nium.va_single_dispatch.production_admission_enabled"), "paused" => config("services.nium.va_single_dispatch.allocation_paused"), "reason" => App\Services\Nium\NiumVirtualAccountAssignmentService::allocationBlockReason()]);'
sudo systemctl start php8.3-fpm.service supervisor.service
sudo supervisorctl status 'origin_wallet_worker:*'
sudo -u www-data /usr/bin/php8.3 artisan up
sudo systemctl unmask --runtime cron.service
sudo systemctl start cron.service
```

Expected cached admission=true, paused=false, reason=null. This deliberate restart
briefly interrupts receipt; pause by itself does not. Begin with one reviewed
GREENTECH UI request after verifying its IDs/status. Save its operation key and
evidence. Timeout/UNKNOWN/delayed acceptance requires webhook/GET recovery, never
another POST. Availability applies to all eligible accounts; GREENTECH is the
first operator-controlled request, not an application allowlist.

Emergency rollback uses the same stop/cache/restart sequence with
`nium_va_set_allocation false true`. Keep upgraded ownership guards and
webhook/GET services. Do not restore an old backend binary or delete uncertain
operations. A config pause cannot retract already-consumed authority.

## Remaining activation blockers

Tests are authored but unexecuted here. Actual topology, deployed schema,
historical ownership/evidence, old processes/launchers and Cloudflare settings
have not been observed. The unresolved runtime invariant is exclusive execution
of the reviewed participating dispatchers/ownership writers. On the documented
single VPS, stopping/masking all launchers and replacing/restarting every runtime
enforces it once operators verify the inventory. If any old or remote writer is
uncontrolled, these commands are insufficient: leave admission OFF until its
execution or credentials are fenced by operations. No boolean attestation or
additional ownership architecture is introduced as a substitute.

## Changed-file manifest

Backend changes in this implementation task (paths relative to backend root):

- `.env.example`
- `app/Services/Nium/NiumPaymentIdService.php`
- `app/Services/Nium/NiumVirtualAccountAssignmentService.php`
- `app/Services/Nium/NiumVirtualAccountDetailEnrichmentService.php`
- `app/Console/Commands/RecoverNiumVirtualAccountDetails.php`
- `config/services.php`
- `deploy/env/api.originwallet.asia.env.example`
- `deploy/supervisor/api.originwallet.asia-worker.conf`
- `phpunit.xml`
- `tests/Feature/NiumVirtualAccountCorridorTest.php`
- `tests/Feature/NiumVirtualAccountDetailEnrichmentTest.php`
- `docs/nium-va-production-admission-blocker.md` (mark earlier report historical)
- `docs/nium-va-production-release.md` (this runbook)
- `docs/vps-deployment-api.originwallet.asia.md` (runtime user, permissions and existing queues)

Frontend changes in this implementation task (paths relative to frontend root):

- `src/services/providerAccountService.ts` (new Production-disabled reason)
- `src/lib/virtualAccountLifecycle.test.ts` (new reason blocks allocation)
- `src/pages/account/AccountVirtualAccountRequest.test.tsx` (disabled-state display)

The existing uncommitted corridor UI changes in those three files were preserved.
These six other pre-existing modified frontend files were preserved,
with no edits by this task; include them in the reviewed frontend release:

- `src/lib/virtualAccountLifecycle.ts`
- `src/pages/account/AccountVirtualAccountRequest.tsx`
- `src/test/virtualAccountFixtures.ts`
- `tests/browser/va-backend.ts`
- `tests/browser/va-fixtures.ts`
- `tests/browser/virtual-account-lifecycle.e2e.ts`

No migration or real `.env` was changed. The untracked `tatus --short` file was
left untouched. No commit, push or deployment was performed.
