# Nium VA Phase 3B webhook recovery hardening

Local implementation on `main`, 2026-10-27, for technical-lead review. This addresses
the final audit's H1 admin-retry and H2 redelivery-recovery findings. The separately
named H2 Payment ID ownership issue remains unresolved and is a Production blocker.

## Exact files changed for this hardening

Prior Phase 1/2/3A/3B changes remain local and unstaged. This task changed only:

1. `app/Http/Controllers/Api/Admin/ProviderWebhookEventController.php`
2. `app/Models/WebhookEvent.php`
3. `app/Services/Nium/NiumWebhookService.php`
4. `app/Services/Nium/NiumVirtualAccountWebhookRecovery.php` (new)
5. `app/Services/Nium/NiumVirtualAccountSafetyLedger.php`
6. `app/Services/Nium/NiumVirtualAccountReconciliationService.php`
7. `database/migrations/2026_10_27_000004_add_nium_va_webhook_recovery.php` (new)
8. `tests/Feature/NiumVirtualAccountLedgerWebhookTest.php`
9. `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php`
10. `tests/Feature/NiumVirtualAccountSafetyAuditTest.php`
11. `docs/nium-va-webhook-recovery-hardening.md` (new, this report)

No original evidence guard, assignment contract, activation setting, frontend or
VA-detail service was changed. `tatus --short` was neither changed nor staged.

## Root causes and admin recovery

The admin endpoint previously inserted `_origin_retry` inside every receipt's
payload. The new VA evidence guard rejects that mutation. Protected VA retries now
store their count and request timestamp/admin ID in separate nullable
`webhook_events.nium_va_recovery` JSON. The column also holds processing attempt
count, claim timestamp and fencing token; it is hidden from model serialization.
The original payload projection and encrypted evidence remain byte-for-byte
unchanged. The existing audit log records the admin request.

One additive nullable column is sufficient. The migration is forward-only and
must precede application use. Existing records remain null; unrelated webhook
types keep their existing `_origin_retry` representation and processing path.
No Production migration was performed.

Existing route authentication, admin access and `providers.sync` permission checks
are unchanged. Unauthorized requests cannot increment counters or start recovery.
The retry metadata transaction now sits inside the controller's error handling.
Protected retries use the same processor as initial delivery and redelivery:

- `processed`: existing admin rejection remains HTTP 422.
- Another active processor: HTTP 422 with an in-progress message and actual status.
- Insufficient evidence: HTTP 200, status `observed`, with an explicit unresolved message.
- Processing/database error: HTTP 422, safe error text and actual receipt status.
- Successful recovery: HTTP 200 and status `processed`; repeated delivery adds no VA.

## Durable claim and redelivery state machine

Authenticated initial delivery and both duplicate-detection paths converge on the
same recovery coordinator for receipts with immutable VA evidence. Reused event
identity with a different raw-body hash is still rejected. Historical receipts
without authenticated provenance are not upgraded into ledger evidence.

```text
received / observed / failed / abandoned processing
    -> exclusive session lock
    -> committed processing claim with fresh token and incremented attempt count
    -> original authenticated evidence re-evaluated
       -> processed: processing completed
       -> observed: insufficient proof; assignment and scope remain unresolved
       -> failed: processing exception; retry remains eligible

processed -> idempotent replay, no new processing attempt
active session owner -> acknowledge receipt with actual processing status;
                        do not enter another processor
```

For PostgreSQL, a nonblocking session advisory lock excludes other processors
across the independent claim, ledger and completion transactions. A process death
or lost database session releases that lock. The next authenticated redelivery or
authorized admin request replaces the abandoned claim. There is no time-based
takeover of a live owner and no new background worker or assignment dispatcher.
An indefinitely live, hung owner requires session termination before takeover.
SQLite uses a local OS file lock for test/development exclusion; this does not
claim distributed SQLite support.

Each claim has a durable UUID token. Before an enrolled resolution or review
transition, the ledger rechecks the selected receipt's token, `processing` status
and session-lock ownership after taking its existing account/scope/request/
operation locks. The current legacy VA writer checks after its account lock.
Receipt completion/failure also checks the claim under a row lock. Losing a
session or token prevents an old processor from publishing status or assignment
writes, including after Laravel reconnects. Observation is held in memory until
the fenced receipt completion, rather than written by an unfenced worker.

The short receipt claim and admin metadata transactions release their row locks
before acquiring ledger account locks. The session lock is specific to receipt
processing; ordinary POST response and reconciliation workers never acquire it.
Ledger resolution retains account -> scope -> request -> operation -> evidence/VA
row ordering. VA attachment, operation resolution and scope release remain atomic.
Receipt completion commits afterward. A crash between these commits is recovered
without another VA attachment or timestamp change. Legacy replay also preserves
an existing VA assignment timestamp.

Use a direct PostgreSQL connection or session-affine pooling. Transaction pooling
cannot provide the required session-lock ownership and is not an approved setup.
Recovery requires a top-level connection so its claim can durably commit. The
legacy VA regression class now uses an isolated in-memory database instead of a
test-wide transaction, allowing this real commit boundary to be exercised.

## Causality and acknowledgment

Recovery always reconstructs the original encrypted raw event. A webhook observed
before POST evidence arrives can resolve later only with exactly matching,
operation-bound successful POST evidence and all existing target, identifier,
dimension, version, dispatch and collision checks. No Wallet/Currency/timestamp
heuristic is introduced. Missing causality, wrong targets, malformed/INITIALIZED
IDs, unsupported mappings and assignment-failed events remain observed/unresolved.
Terminal ownership cannot be downgraded. Consumed POST authorization is untouched.

The existing webhook controller contract is retained: normal receipt acknowledgment
is HTTP 200, invalid authentication is HTTP 403, and processing RuntimeException
is HTTP 422. Protected responses add `processing_status`, separating transport
acknowledgment from processing completion. A busy or observed receipt is not
reported as processed. Webhook-triggered resolution exceptions are now propagated
to recovery rather than swallowed as an apparently successful retry. A concurrent
terminal winner is preserved. Neither recovery nor webhook reconciliation issues
an assignment POST; enrolled recovery issues no provider requests.

## Validation actually run

- Focused webhook/admin run: **63 tests / 544 assertions**, passed.
- Final relevant regression selection: **525 tests / 4,164 assertions**, passed.
  This includes protected and legacy webhook handling, admin authorization/retry,
  transfer/payout, transaction RFI, corporate RFI, Phase 3A reconciliation, ledger
  state/isolation/migration, single dispatch, V1 adapter, exchange evidence and
  shared validation/contracts.
- Final PostgreSQL suite: **50 tests / 663 assertions**, passed. It includes all
  existing ledger races, collision quarantine and lock-order checks, plus actual
  HTTP-entry recovery after real SIGKILL at four boundaries: receipt commit,
  claim commit, uncommitted attachment, and committed assignment before receipt
  completion. It also covers duplicate/admin exclusion while a processor is
  paused, and rejection of a stale processor after its backend is terminated and
  a new processor completes. The real CHECK-failure rollback test now recovers
  through webhook redelivery rather than a direct service call.
- All changed PHP files passed syntax checks. Scoped formatting passed for the
  controller/model/recovery/ledger/reconciliation/migration and changed test files.
  Existing unrelated formatting in the shared webhook service was preserved.
- `git diff --check` passed; the index remains empty.

The full KYB/onboarding class was also exercised. Its broader failures must not
be described as green: **232 tests, 6 errors and 25 failures** were reproduced
using the committed webhook implementation through a temporary bootstrap, with
the same 31 failing test/data-set identities as the recovery implementation.
Examples concern customer payload/document/position rules and lifecycle envelope
expectations. Those unrelated rules were not changed in this task. A separate
selection of core KYB lifecycle webhook tests passed: **10 tests / 81 assertions**.

An intermediate regression run overlapped the KYB baseline run and encountered
shared fake-storage interference in RFI tests. The final 525-test run was executed
without another file-storage test suite running and passed. Initial test-barrier
and timestamp-object assertions were corrected before the final PostgreSQL run.

Logs:

- `/tmp/nium-va-recovery-focused.log`
- `/tmp/nium-va-recovery-regressions-final.log`
- `/tmp/nium-va-recovery-kyb-baseline.log`
- `/tmp/nium-va-recovery-kyb-webhooks.log`
- `/tmp/nium-va-ledger-pg.eOvHOj/recovery-tests-final.log`

PostgreSQL 18 used a fresh disposable `/tmp/nium-va-ledger-pg.eOvHOj` cluster,
Unix socket only, TCP disabled, database `nium_va_ledger_test_hardened`. Only that
disposable database was migrated/recreated. Independent forked connections were
used; the test cluster was stopped afterward. All provider traffic was mocked.

## Remaining blockers and handoff

The two webhook-recovery audit findings are addressed locally for review. The
separate cross-account Payment ID ownership H2 remains unresolved: participating
writer collision quarantine does not establish provider uniqueness or enforce
ownership across every writer. No uniqueness-domain decision or new ownership
constraint was invented.

Other existing Production blockers remain: authoritative standalone asynchronous
success/failure causality, reviewed webhook-to-POST mappings, historical ownership
inventory, old-writer fencing, environment/secret routing, evidence/key retention,
session-affine database operation, volume-safe evidence lookup and approved cutover.
The full KYB/onboarding test baseline also remains non-green as reported above.

The workflow remains disabled and testing-only. No real Nium request, Production
access, deployment, commit, push, activation, frontend/VA-detail change, POST
contract change or Phase 3C implementation was performed. Stop for technical-lead
review; this report does not authorize deployment or activation.
