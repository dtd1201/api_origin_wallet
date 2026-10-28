# Phase 3C.2 — automatic VA details continuation

Implemented locally for technical-lead review. Tests, syntax checks, formatters,
migrations and Git commands were not run by Codex. No passing result is claimed
for this patch. The earlier Phase 3C results belong to the accepted baseline.

This document supersedes the automatic-trigger dependency recorded in
`nium-va-details-phase3c.md`. Assignment gates are unchanged. Enrichment queue
publication, workers and recovery explicitly require the testing environment;
there is no rollout switch, scheduler registration or Production activation.

## Exact files changed by Phase 3C.2

New:

- `app/Console/Commands/RecoverNiumVirtualAccountDetails.php`
- `app/Jobs/Nium/EnrichNiumVirtualAccountDetails.php`
- `app/Models/NiumVirtualAccountDetailEnrichment.php`
- `app/Services/Nium/NiumVirtualAccountDetailEnrichmentService.php`
- `app/Services/Nium/NiumVirtualAccountDetailRetry.php`
- `config/nium_va_enrichment.php`
- `database/migrations/2026_10_28_000001_create_nium_va_detail_enrichments.php`
- `tests/Feature/NiumVirtualAccountDetailEnrichmentTest.php`
- `docs/nium-va-details-phase3c2.md`

Modified:

- `app/Services/Nium/NiumVirtualAccountSafetyLedger.php`
- `app/Services/Nium/NiumVirtualAccountDetailService.php`
- `tests/Feature/NiumVirtualAccountSingleDispatchTest.php`
- `tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php`
- `tests/Feature/NiumVirtualAccountLedgerWebhookTest.php`
- `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php`

These are this task's edits, not an assertion about pre-existing local changes.
The file named `tatus --short` was not accessed or modified.

## Shared after-commit lifecycle

All four enrolled paths call the same existing ledger primitive:

| Resolution path | Existing route to terminal attachment |
| --- | --- |
| Direct successful POST | Assignment service -> `resolveFromPostEvidence(..., attachVirtualAccount: true)` |
| Ledger reconciliation | Reconciliation service -> the same primitive with original correlated POST evidence |
| Authenticated webhook | Existing webhook processor -> enrolled reconciliation -> the same primitive |
| Recovery of observed webhook | Existing authenticated redelivery/admin retry -> original protected receipt -> the same primitive |

The hook follows the VA insert and operation attachment in the terminal ledger
transaction. It records a unique continuation per operation and VA, then
registers `DB::afterCommit`. Unknown, pending, manual-review and failed outcomes,
and terminal resolutions without VA attachment, never enter this hook.
Duplicate terminal resolution retains the original continuation.

The callback only publishes a job; it does not fetch details. It explicitly
uses Laravel's database queue on the same database connection as the ledger,
with queue `nium-va-details` and table `jobs`. This bypasses a global `sync`
queue setting. Publication and its durable delivery marker are one transaction.
Uncommitted assignment or queue rows cannot be observed by independent workers.
The worker and detail service reject open transactions before provider HTTP.

Continuation intent insertion uses a savepoint so its failure does not undo
verified assignment. A failed publication is contained in the callback. Both
gaps remain recoverable. No enrichment result changes assignment state,
ownership, frozen evidence, dispatch authorization or active assignment scope.

## Read-only recovery and durable state

`nium_va_detail_enrichments` holds operation/VA IDs, state, attempt count,
availability time, claim token, lease/delivery deadlines and a generic result
code. The unique operation and VA constraints prevent multiple continuations
for one attachment. Queue payloads carry only the continuation ID.

The state flow is `pending -> running -> complete`, or `running -> pending`
with backoff, eventually `exhausted`. Attempt count is committed before HTTP.
Repeated deliveries and recovery do not reset it.

The bounded console recovery entry point is:

```text
nium:recover-va-details --limit=100 [--operation=<enrolled operation ID>]
```

It uses existing console and queue infrastructure. It repairs missing intents
only for already committed enrolled assigned attachments, and republishes due
pending tasks or expired running tasks whose delivery window has elapsed. It
does not resolve assignments. Original terminal proof is checked again by the
detail service before HTTP, including for reconstructed intents. There is no
assignment service call or POST transport in the continuation/recovery path.

Recovering a lost callback, lost delivery or abandoned exhausted queue delivery
requires running this command. Normal handled read failures automatically queue
their delayed continuation. Normal database queue redelivery can also resume
after a worker dies; an early delivery during an active lease is safely ignored
and the recovery command can republish after expiry. No new periodic scheduler
is registered. No public or admin HTTP authorization boundary was changed.

## Retry, concurrency and provider limits

- Six durable attempts maximum, including worker crashes and rate deferrals.
  Backoff is 60, 300, 900, 3,600 and 14,400 seconds between attempts. A final
  unsuccessful attempt becomes exhausted and is not automatically restarted.
- Worker timeout is 60 seconds. Database queue reservation expiry is 90 seconds;
  the continuation lease is 120 seconds. A future worker must consume the same
  database/table/queue used for publication and keep timeout below reservation
  expiry. No worker was started by this patch.
- Claims use row locks and random tokens. Duplicate workers cannot claim a live
  lease. The claim is rechecked before each GET and under the existing final
  banking-persistence transaction. An expired worker cannot write after a new
  claimant takes over. HTTP holds no database transaction or row locks.
- Existing detail binding and `enrichment_version` checks remain in place for
  races with direct/legacy detail-service callers. No conflicting nonempty
  facts are overwritten, even by sequential GETs.
- Every page consumes a shared database-backed allowance for the frozen
  origin/client. The initial local budget is 30 GETs per fixed minute, configured
  in `nium_va_enrichment.php`. The limiter does not sleep a worker. Denied reads
  defer within the same bounded attempt budget. A pagination run exceeding the
  remaining budget saves no partial page selection.
- GET 404, timeout, absence from the listing, malformed/incomplete responses,
  conflicts and unexpected service failures preserve assignment and existing
  details. Retries are GET-only; redirects, hidden transport retries and
  authentication token-acquisition POSTs remain forbidden by existing transport.
- `Retry-After` delta seconds and HTTP dates extend local backoff. A 429 also
  establishes a shared origin/client cooldown. Only a hash identifies the rate
  bucket; queue/task operational state includes no credentials, frozen target
  identifiers, bank facts or raw error messages.

## Frozen identity and banking facts

The new `syncEnrolled` entry point delegates to the existing detail implementation
and requires its ledger operation ID to match the continuation. It cannot fall
back to the legacy identity path. The request's encrypted frozen origin,
client/customer/wallet and account ownership are revalidated against trusted
configuration and the original terminal evidence. Changed current identity is
rejected, never substituted. GET data cannot establish VA ownership.

Legacy `sync` remains available with its existing behavior and return type.
No legacy assignment is enrolled automatically.

Both routing pairs keep their provider types and values; pair 2 is not assumed
to represent an intermediary. Raw provider bank/type/category facts are retained.
GLOBAL is not mapped to WIRES. Partial facts can fill absent columns but cannot
clear or contradict verified nonempty facts. Incomplete routing pairs are
rejected atomically. Existing version and binding checks remain authoritative.

For automatic retry purposes, locally sufficient details mean an account name,
a raw bank name/identifier and a complete primary routing pair. Optional bank
address, display name and routing pair 2 are not required. This is a conservative
local stopping rule, not a verified provider completeness contract. A provider
that never supplies these minimum facts safely exhausts the bounded budget.

## Tests authored, not executed

The new SQLite feature class exercises actual database queue payload delivery,
commit visibility and rollback, failed/unresolved assignments, 404/timeout/
missing listing retries, partial completion, routing-pair and banking conflicts,
overlapping/duplicate jobs, publication failure, missing-intent recovery,
expired leases and stale-worker fencing, exact backoff and exhaustion, arbitrary
service failure isolation, frozen identity and original-proof checks, legacy
compatibility, testing-only gates, per-page budgets, shared 429 cooldown and
payload/state secrecy. All provider requests in this class must be GETs.

Existing direct POST, reconciliation, webhook, admin-recovered webhook and
redelivery-recovered webhook tests now assert one durable continuation and one
queued delivery. Unresolved POST outcomes assert no continuation. Existing
authorization and Phase 3C detail-service tests remain part of regression scope.

The PostgreSQL suite adds independent-process duplicate-job races and SIGKILL
recovery at three boundaries: immediately after physical assignment commit before
publication, during GET after a durable worker claim, and after banking facts
commit before continuation completion is acknowledged. The last case repeats GET
without changing the saved banking version. The existing webhook
crash test now ignores savepoint commit events so its resolved boundary still
means a top-level commit. Existing version-race tests remain included.

These cases cover all fifteen requested categories. No test count, assertion
count or passing result is claimed before the user's manual runs.

## User CLI validation

Run from the repository root. Codex has not executed these commands.

Formatting assessment:

```bash
vendor/bin/pint --test \
  app/Console/Commands/RecoverNiumVirtualAccountDetails.php \
  app/Jobs/Nium/EnrichNiumVirtualAccountDetails.php \
  app/Models/NiumVirtualAccountDetailEnrichment.php \
  app/Services/Nium/NiumVirtualAccountDetailEnrichmentService.php \
  app/Services/Nium/NiumVirtualAccountDetailRetry.php \
  app/Services/Nium/NiumVirtualAccountDetailService.php \
  app/Services/Nium/NiumVirtualAccountSafetyLedger.php \
  config/nium_va_enrichment.php \
  database/migrations/2026_10_28_000001_create_nium_va_detail_enrichments.php \
  tests/Feature/NiumVirtualAccountDetailEnrichmentTest.php \
  tests/Feature/NiumVirtualAccountSingleDispatchTest.php \
  tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php \
  tests/Feature/NiumVirtualAccountLedgerWebhookTest.php \
  tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php
```

Focused tests with isolated cache paths and in-memory SQLite. The feature test
fixtures run their own migrations only against their isolated SQLite connections.

```bash
NIUM_ENRICH_REVIEW_DIR="$(mktemp -d /tmp/nium-va-enrichment-review.XXXXXX)"
mkdir -p "$NIUM_ENRICH_REVIEW_DIR/views"
APP_ENV=testing \
APP_CONFIG_CACHE="$NIUM_ENRICH_REVIEW_DIR/config.php" \
APP_ROUTES_CACHE="$NIUM_ENRICH_REVIEW_DIR/routes.php" \
VIEW_COMPILED_PATH="$NIUM_ENRICH_REVIEW_DIR/views" \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
vendor/bin/phpunit \
  tests/Feature/NiumVirtualAccountDetailEnrichmentTest.php \
  tests/Feature/NiumVirtualAccountDetailServiceTest.php \
  tests/Feature/NiumVirtualAccountSingleDispatchTest.php \
  tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php \
  tests/Feature/NiumVirtualAccountLedgerWebhookTest.php \
  tests/Feature/NiumVirtualAccountSafetyAuditTest.php \
  tests/Feature/NiumPaymentIdServiceTest.php \
  tests/Feature/NiumVirtualAccountV1AdapterTest.php \
  tests/Unit/NiumVirtualAccountAssignmentContractTest.php
```

PostgreSQL concurrency/crash checks require a NEW disposable socket-only cluster,
PostgreSQL 18 binaries and PHP pcntl/posix/pdo_pgsql. Run in a separate shell.
The migration below targets only this newly created isolated database.

```bash
set -e
NIUM_ENRICH_PG_BIN=/usr/lib/postgresql/18/bin
NIUM_ENRICH_PG_RUN="$(mktemp -d /tmp/nium-va-ledger-pg.XXXXXX)"
mkdir -p "$NIUM_ENRICH_PG_RUN/views"
export APP_ENV=testing
export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export APP_CONFIG_CACHE="$NIUM_ENRICH_PG_RUN/config.php"
export APP_ROUTES_CACHE="$NIUM_ENRICH_PG_RUN/routes.php"
export VIEW_COMPILED_PATH="$NIUM_ENRICH_PG_RUN/views"
export DB_CONNECTION=pgsql DB_HOST="$NIUM_ENRICH_PG_RUN" DB_PORT=55439
export DB_DATABASE=nium_va_ledger_test_hardened DB_USERNAME="$(id -un)"
export DB_PASSWORD='' DB_URL='' CACHE_STORE=array SESSION_DRIVER=array
export LOG_CHANNEL=stderr QUEUE_CONNECTION=sync NIUM_VA_LEDGER_PG_TESTS=1
"$NIUM_ENRICH_PG_BIN/initdb" -D "$NIUM_ENRICH_PG_RUN/data" -A trust --no-locale >/dev/null
"$NIUM_ENRICH_PG_BIN/pg_ctl" -D "$NIUM_ENRICH_PG_RUN/data" \
  -l "$NIUM_ENRICH_PG_RUN/server.log" \
  -o "-k $NIUM_ENRICH_PG_RUN -p $DB_PORT -c listen_addresses=''" -w start
trap '"$NIUM_ENRICH_PG_BIN/pg_ctl" -D "$NIUM_ENRICH_PG_RUN/data" -m fast -w stop' EXIT
"$NIUM_ENRICH_PG_BIN/createdb" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE"
php artisan migrate --database=pgsql --force
vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php \
  --filter 'test_(concurrent_enrichment_job_deliveries|enrichment_recovers_after_real_process_death|overlapping_detail_reads|redelivery_recovers_after_real_processor_death)'
```

Remove the filter to run the full PostgreSQL class against a separately prepared
fresh disposable database. For the existing full regression suite, use the same
isolated SQLite environment as above and run `vendor/bin/phpunit` without file
arguments; the PostgreSQL class remains opt-in.

## Remaining blockers and review decisions

- Manual syntax/formatting, focused, PostgreSQL and regression validation remain
  pending. No migration was applied by Codex.
- H2 remains unresolved: Nium's identifier ownership/uniqueness domain and
  database enforcement across every historical, legacy and raw writer are not
  established. Existing collision detection quarantines known conflicts only.
- The local rate budget and completion threshold need technical-lead/provider
  contract review before any rollout. The automatic budget does not coordinate
  unrelated legacy/manual GET callers. A task whose pagination exceeds its
  available budget can exhaust safely without enrichment.
- Lost-callback/abandoned-delivery recovery requires the explicit recovery
  command. Any future unattended operational invocation and worker provisioning
  belong to separately approved activation, not this code-only patch.
- Conflicting facts or changed frozen identity exhaust safely; there is no
  automatic evidence rewrite, identity rebind or reset of exhausted attempts.
- Existing old-writer fencing, environment/secret routing, evidence retention
  and legacy identity-history concerns remain cutover dependencies.
- Assignment remains disabled/testing-only. No real Nium call, Production
  access, deployment, commit, push, frontend change or Phase 3D work occurred.
  KYB, RFI and transfers were not changed.

Stop for technical-lead review.
