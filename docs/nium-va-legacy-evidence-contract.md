# Legacy Payment ID evidence contract — technical-lead review

This change implements the legacy evidence and writer contract missing from the
earlier `nium-va-legacy-ownership-blocker.md` inspection. It does not enable
Production admission. Earlier reports describe the pre-change source.

## Changed-file manifest for this task

Application and schema:

- `app/Models/NiumVirtualAccountOperation.php`
- `app/Services/Nium/NiumLegacyVirtualAccountEvidence.php` (new)
- `app/Services/Nium/NiumPaymentIdService.php` (preserves earlier corridor edits)
- `app/Services/Nium/NiumVirtualAccountReconciliationService.php`
- `app/Services/Nium/NiumWebhookService.php` (legacy VA branch only)
- `database/migrations/2026_10_28_000002_preserve_nium_legacy_va_targets.php` (new)

Tests and documentation:

- `tests/Concerns/CreatesNiumLegacyVirtualAccountEvidence.php` (new)
- `tests/Feature/NiumLegacyVirtualAccountEvidenceTest.php` (new)
- `tests/Feature/NiumPaymentIdServiceTest.php`
- `tests/Feature/NiumVirtualAccountSafetyAuditTest.php`
- `tests/Feature/NiumVirtualAccountSafetyLedgerIsolationTest.php`
- `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php`
- `tests/Feature/NiumOfflineReadinessTest.php` (only the canonical VA assignment
  test fixture; unrelated readiness tests keep their original transaction)
- `docs/nium-va-legacy-evidence-contract.md` (this file)

Other pre-existing workspace changes are outside this task. Configuration,
Production environment settings, V1 routing, enrolled ledger implementation,
webhook authentication/receipt/recovery machinery and allocation defaults were
not changed. No tests/builds, database migrations or NIUM calls were executed.
Only PHP syntax checks and source/diff inspection were performed. Nothing was
committed, pushed or deployed.

## Evidence precedence and conflict rules

1. **Frozen original identity:** future legacy operations capture the existing
   canonical `NiumVirtualAccountRequestIdentity` under the provider-account lock
   when claimed, before baseline GET and POST. This encrypted snapshot includes
   user/provider/account, origin/client/customer/wallet, V2 endpoint and payload.
   A unique provider request UUID is frozen with it. Verified customer/wallet IDs
   are required at capture, and current eligibility is rechecked at dispatch.
   Neither field can be replaced or removed. Snapshot precedence never permits
   ignoring contradictory exchange or authenticated webhook evidence.
2. **Historical exact exchange:** without a snapshot, only an explicitly linked
   `api_request_log_id` can select a historical POST. It must be linked to just
   that operation. The exact assignment URL must survive in encrypted
   `assignment_target` or an unredacted captured exchange `request_url`; identifiers
   are parsed from that evidence, not supplied by today's account mapping. Require
   the original log user/provider, operation key, client, POST method, capture
   version, matching UUID headers, operation submission/request timestamps, raw
   payload, raw response and verified body hashes. The payload must agree with the
   stored operation. Inspect all POST logs for the same provider/user/key: more
   than one is ambiguous, even if the reported Payment IDs happen to agree.
3. **Assignment outcome:** target proof alone does not assign an ID. Require one
   correlated HTTP 200 POST exchange with a valid `uniquePaymentId`, no errors and
   no contradictory response dimensions. Authenticated receipts mentioning that
   ID must agree with the original target and supported webhook contract. Receipt
   delivery IDs are not outbound request IDs or assignment correlation keys.
4. **Current mapping:** compare it with the proven original identity inside the
   write transaction. A change in account owner, provider, origin/client, customer
   or wallet blocks mutation; it never rebases the historical identity.

Redacted URLs, safe API summaries, current mapping, customer/wallet verification
timestamps, baseline/listing differences and webhook timing cannot independently
prove the original POST target and assignment. Verification records establish
eligibility when a new snapshot is captured; they cannot reconstruct an older
request. Sanitized historical webhook payloads without authenticated immutable
receipts are insufficient. An authenticated exact-target webhook alone also lacks
the operation-to-POST correlation required here.

**Historical availability is limited:** current generic legacy transport redacts
the stored request URL and did not capture `assignment_target`. Those operations
will usually fail the historical contract. The implementation does not infer,
backfill or relabel them. No Production data inventory was performed; positive
historical tests use explicitly synthetic exact-capture evidence.

## Ownership invariant and transaction

All upgraded legacy assignment entry points converge on `assign()`. It locks the
provider account, then reloads/locks the operation, rejects enrolled histories,
revalidates original target and POST evidence, and acquires the existing ledger
`lockPaymentIdForWriter()` lock. Under that lock it checks global VA and operation
ownership before creating or attaching anything. PostgreSQL READ COMMITTED is
required by that existing shared lock. SQLite checks do not establish concurrent
writer safety.

An existing Payment ID cannot be moved between accounts or operations. A linked
pending/assigned VA must have the same account and dimensions, a consistent
reference/Payment ID and no competing operation. A missing or foreign existing VA
reference is a conflict, not permission to create another VA. Invalid evidence,
duplicate owners and conflicting associations return manual review without any
ownership write or alternative fallback. Already terminal historical assignments
are preserved; a different Payment ID on a terminal direct call is rejected.

Future legacy dispatch uses the frozen absolute endpoint, frozen body values and
request UUID with the existing strict assignment transport (exact encrypted
target capture, no redirects and one HTTP attempt). It does not enroll legacy
operations in the new ledger. The existing legacy submission timestamp is claimed
under the account/operation locks before HTTP and cannot be cleared afterward.
Only operations never submitted may retry preflight. A consumed or uncertain
submission never automatically POSTs again. The independent allocation pause now
also blocks new legacy allocation, with a second check at dispatch; receipt and
recovery remain available.

## Covered entry points and recovery behavior

- HTTP `ProviderAccountController::assignVirtualAccount()` and
  `nium:assign-payment-id`, through `NiumPaymentIdService`: capture before POST,
  serialize dispatch, persist its outcome and guard response-driven attachment.
- Same-operation replay through `NiumPaymentIdService::resume()`: recovery only
  after a submitted request; no repeat POST.
- `nium:reconcile-payment-id-operation` and direct legacy `reconcile()` calls:
  resolve only from satisfactory original POST evidence. GET V2 can still collect
  evidence from a proven original target, outside the transaction, even paused.
  It cannot turn a unique listing candidate into a proven assignment. Missing
  original target proof prevents even a GET constructed from today's mapping.
- Direct legacy `assign()` calls: the same in-transaction guard, including global
  collisions and rejection of foreign pending-VA references.
- Authenticated legacy VA webhook delivery/replay: only one operation with
  matching original POST proof and a supported exact-target receipt may enter
  `assign()`, which rechecks evidence and the webhook recovery claim inside its
  transaction. No match or ambiguity retains the receipt as observed evidence;
  the old independent create/attach fallback is removed. Invalid Payment IDs
  remain failed receipts.

Missing/conflicting evidence leaves operation fields and encrypted logs available
for manual review, with no owner inferred. A timeout, `INITIALIZED` response or
GET-only observation may therefore remain unresolved despite a real provider
assignment. This is intentional: no manual binding/import tool or new provider
correlation contract is invented. Enrolled V1 webhook and GET V2 recovery paths
retain their existing behavior.

## Migration requirements

The additive migration must be reviewed and applied before running the upgraded
writers. It adds nullable encrypted `legacy_target_snapshot` and nullable unique
`legacy_provider_request_id` columns; existing rows are not backfilled.
PostgreSQL/SQLite triggers enforce paired fields, initial pre-submission capture,
immutable snapshot/request identity, immutable consumed submission timestamps and
preservation of snapshot-bearing operations. Submitted historical rows cannot
acquire a new snapshot. The operation's existing historical API-log link cannot
be switched or cleared once set.

Legacy POST logs/exchanges selected by a frozen request UUID or existing
operation-to-log link become append-only. These guards do not change the enrolled
ledger's evidence lifecycle or unrelated NIUM logs. Recovery is forward-only;
rollback is deliberately refused. Retain the encryption key needed to read the
snapshots/exchanges. The migration does not manufacture provenance for historical
records or install a global raw-writer ownership fence.

## User-run validation

From the repository root, mock HTTP only:

```sh
vendor/bin/phpunit tests/Feature/NiumLegacyVirtualAccountEvidenceTest.php
vendor/bin/phpunit tests/Feature/NiumPaymentIdServiceTest.php tests/Feature/NiumVirtualAccountSafetyAuditTest.php tests/Feature/NiumVirtualAccountSafetyLedgerIsolationTest.php
vendor/bin/phpunit tests/Feature/NiumOfflineReadinessTest.php --filter test_assign_payment_id_uses_canonical_operation
vendor/bin/phpunit tests/Feature/NiumVirtualAccountCorridorTest.php tests/Feature/NiumVirtualAccountV1AdapterTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php
```

For actual lock races, select the existing pre-migrated disposable PostgreSQL test
configuration and apply the new migration there first. The suite requires pgsql,
pcntl, database `nium_va_ledger_test` or `nium_va_ledger_test_hardened`, and a Unix
socket under `/tmp/nium-va-ledger-pg.`. Do not point it at Production:

```sh
NIUM_VA_LEDGER_PG_TESTS=1 vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php --filter 'legacy_and_ledger_reconciliation|two_legacy_resolvers|mixed_legacy_and_ledger_webhook|collision_waiter'
```

The new cases cover exact historical evidence, missing/redacted/unlinked and
contradictory evidence, snapshot immutability, global VA/operation collisions,
terminal history, safe pending attachment, distinct identities, one POST,
timeout recovery without repost, pause, authenticated webhook correlation and
legacy/legacy plus legacy/ledger PostgreSQL races. Existing unsafe listing-only
and uncorrelated-webhook expectations now assert blocked mutation. Assignment
tests that previously used an outer test transaction now use an isolated database
so target/dispatch claims can commit before HTTP.

## Cutover and recommendation

**NO-GO for Production activation.** This patch is ready for technical-lead review,
not validated by execution. Required steps remain:

1. Review the contract and migration, then execute mock regressions and the
   disposable PostgreSQL concurrency cases. Inventory actual historical evidence
   and quarantine duplicate/ambiguous ownership without rewriting it.
2. Keep allocation disabled and paused while stopping new legacy entry and
   draining in-flight web/CLI/queue/scheduler work. Preserve uncertain operations;
   do not replay their POSTs.
3. Prevent old runtimes from restarting, including Supervisor autorestarts; apply
   the reviewed migration and deploy/restart every participating runtime on the
   reviewed revision. Verify old binaries cannot resume. Keep authenticated
   webhook receipt/recovery available during cutover using upgraded processes.
4. Establish authoritative operational cutover evidence and review the separate
   default-OFF Production admission gate. The testing-only admission restriction
   remains unchanged by this task.

Code guards and this migration do not fence already-running old writers or revoke
already-consumed HTTP authorization. A flag, empty history query or operator
boolean is not proof of a completed cutover. No Production activation is performed
or recommended here.
