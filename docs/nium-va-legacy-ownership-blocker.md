# Legacy VA ownership collision — NO-GO for technical-lead review

Stopped at the requested missing-contract boundary. This task changes only this
report. No application, migration, configuration or test files were changed.
Existing workspace changes were preserved. No tests, builds, NIUM requests,
Production changes, commits, pushes or deployments were performed.

## Confirmed writer and reachable entry points

`NiumVirtualAccountReconciliationService::assign()` contains all direct VA
ownership writes in that service:

- Lines 162–170: attaches a Payment ID to a referenced pending VA. The referenced
  VA's provider account and dimensions are not checked against the operation.
- Lines 172–182: creates an assigned VA when the pending-VA branch does not apply.
  This is an alternative write path, including when an existing association is
  unsuitable.
- Lines 192–199: attaches the selected VA and Payment ID to the operation and
  marks it assigned. Direct calls do not enforce terminal assignment immutability.

The transaction locks the operation first and checks existing Payment IDs only
within that operation's provider account. It does not acquire the ledger's shared
Payment ID advisory lock or check global VA/operation ownership. The checks in
`reconcile()` run before this transaction and cannot serialize a competing writer.

Reachable callers:

1. `NiumPaymentIdService::assign()` calls `assign()` after a successful legacy
   POST response (`NiumPaymentIdService.php:188`). The HTTP controller's
   `assignVirtualAccount()` and `nium:assign-payment-id` reach this service.
2. `NiumVirtualAccountReconciliationService::reconcile()` calls `assign()` for a
   unique new listing candidate (line 130). This is reachable from
   `nium:reconcile-payment-id-operation` and from `NiumPaymentIdService::resume()`
   when replaying unresolved legacy operations. GET-only provider transport still
   leads to local ownership writes.
3. Public direct calls to `assign()` reach the same unsafe writer; protecting only
   the CLI or one caller would leave another path open.

The enrolled branch of `reconcile()` and `reconcileWebhook()` delegate ownership
mutation to `NiumVirtualAccountSafetyLedger::resolveFromPostEvidence()`. They do
not use the direct legacy writer. The separate legacy webhook writer in
`NiumWebhookService::processVaAssigned()` already acquires the account lock and
the shared Payment ID lock and checks global collisions; this does not protect
the reconciliation writer.

No additional entry points have been protected by this task. The defect remains.

## Required invariant and the missing contract

An assignment must bind the Payment ID to one established owner: user, provider,
provider account, provider origin/client, customer and wallet. Every participating
writer must lock its provider account first, acquire the existing
`NiumVirtualAccountSafetyLedger::lockPaymentIdForWriter()` lock, then query VA and
operation ownership and validate any proposed attachment inside that transaction
before writing. PostgreSQL must use READ COMMITTED, as the shared lock requires.
Duplicate or conflicting ownership must retain evidence for manual review and
must not proceed to a create/attachment fallback. The global literal Payment ID
check is a conservative collision quarantine, not proof of NIUM's uniqueness domain.

The shared lock is reusable for serialization. It is insufficient to establish
the original legacy target:

- Legacy `NiumVirtualAccountOperation` and `NiumVirtualAccount` rows record the
  local provider-account relationship but have no frozen original user/provider/
  origin/client/customer/wallet tuple. `UserProviderAccount` holds today's mapping.
- The legacy `fetch()` branch constructs GETs from today's account IDs and client
  configuration (`NiumVirtualAccountReconciliationService.php:429`). An absent ID
  in the stored baseline and one new ID in today's listing do not establish that
  the original operation created that ID for the original target.
- `ProviderHttpClient.php:117` captures the exact outgoing `assignment_target`
  only for single-dispatch transport. Legacy calls do not enable that transport.
  At line 594, the evidence store receives `safeNiumUrl($url)`, which redacts the
  client/customer/wallet path segments. Raw response bodies are retained where
  configured, but do not guarantee those original target fields are present.
- The ledger resolver requires an immutable request snapshot and exact causal
  POST evidence (`NiumVirtualAccountSafetyLedger.php:165`). Its `prepare()` method
  explicitly rejects enrolling an existing legacy operation (line 43). Creating
  a snapshot from today's account would invent the missing historical binding.

For example, a legacy baseline taken for wallet A can be followed by a mapping or
configuration change to wallet B. A unique ID from a GET of B can then be attached
to A's historical operation. Even with the shared Payment ID lock and no existing
duplicate row, this ownership claim would pass a collision-only check. The lock
cannot recover the missing original identity or causal evidence.

Consequently, a lock-only patch can address the known concurrent duplicate-ID
race, but cannot satisfy the full requested identity invariant while preserving
automatic legitimate historical resolution. Rejecting every unresolved legacy
assignment would change that behavior; silently accepting today's mapping would
weaken the invariant. Neither choice is implemented here.

## Smallest contract needed to proceed

Technical-lead review must establish how each legacy ownership mutation obtains
an authoritative original target binding and assignment evidence. Use retained
provider evidence where it actually proves that binding; otherwise retain the
operation, baseline and exchanges for manual review without ownership mutation.
Do not enroll legacy operations retrospectively or manufacture dispatch evidence.
Already assigned historical rows should remain unchanged.

The repository does not currently provide this legacy binding/review-resolution
contract. Supporting ongoing legacy mutation needs reviewed target/evidence
capture and validation across its dispatch and reconciliation entry points, or
an explicit cutover that retires those mutations and defines historical recovery.
This is broader writer coordination than adding the shared lock to this service.
If new durable storage is needed, its schema/backfill contract must be reviewed
separately; this task adds none.

Once that contract exists, the narrow writer correction must cover BOTH pending
attachment and new-VA creation under account-first/shared-ID locking, recheck
enrollment and terminal state inside the transaction, reject foreign/mismatched
VA references and global duplicate owners, and prevent any alternative write
after rejection. Add legacy/ledger and legacy/legacy collision tests, duplicate
VA/operation-ID tests, valid historical replay/reconciliation tests, and ambiguous
target tests. Exercise actual advisory-lock waits in the disposable PostgreSQL
suite; SQLite mock tests cannot prove cross-process serialization.

## Operational cutover remains required

No source edit fences processes already running old code. Before controlled
admission:

1. Keep allocation disabled and paused. Stop new legacy assignment entry and
   prevent old web, CLI, scheduler and queue runtimes from restarting. Supervisor
   currently has both autostart and autorestart enabled for the queue worker.
2. Drain in-flight requests and jobs, including workers already past dispatch
   authorization. Preserve uncertain operations; do not replay their POSTs.
3. Inventory duplicate Payment IDs, unresolved operations and historical target
   evidence. Quarantine ambiguous ownership without deleting history.
4. Deploy reviewed writer protection, restart every participating runtime on the
   reviewed revision, and verify old processes cannot resume. Record authoritative
   cutover evidence; a feature flag or operator boolean alone is insufficient.
5. Preserve authenticated webhook receipt and GET V2 recovery during the pause.
   Review admission separately; do not activate Production as part of this task.

## User-run PHPUnit commands

No new tests were authored because implementation stopped at the missing contract.
These existing mock suites provide regression coverage, not proof that the
unfixed legacy/new writer collision is safe:

```sh
cd /home/dtd1201/projects/api_origin_wallet
vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyAuditTest.php tests/Feature/NiumPaymentIdServiceTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountCorridorTest.php
```

With the existing pre-migrated disposable PostgreSQL test configuration selected
(`DB_CONNECTION=pgsql`, database `nium_va_ledger_test` or
`nium_va_ledger_test_hardened`, Unix socket under `/tmp/nium-va-ledger-pg.`, and
pcntl available):

```sh
NIUM_VA_LEDGER_PG_TESTS=1 vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php --filter 'collision|legacy_and_ledger_webhook|waiter'
```

The existing PostgreSQL cases cover participating ledger/webhook writers; they
do not establish protection of the legacy reconciliation writer described above.

**NO-GO:** the ownership collision remains, the original legacy target/evidence
contract is unresolved, and operational cutover has not been verified. V1 routing,
webhook processing, GET V2 recovery and the allocation gates are unchanged.
