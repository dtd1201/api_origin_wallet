# Nium VA Phase 3A: ledger-aware reconciliation

Review status: implemented locally on `main`, 2026-10-27. Assignment remains
inactive by default, with its existing testing-only gate and account/fence
interlocks unchanged. No scheduler, feature activation, live provider request,
Production access, deployment, commit or push was performed. Phase 3B was not
started. H2 remains blocked.

## Files changed in Phase 3A

The worktree already contained Phase 1/2 and V1 adapter work. This phase changes
only these six files; the rest of that work is preserved:

- `app/Services/Nium/NiumVirtualAccountReconciliationService.php`
- `app/Services/Nium/NiumVirtualAccountSafetyLedger.php`
- `app/Services/Integrations/ProviderHttpClient.php`
- `tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php` (new)
- `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php`
- `docs/nium-va-ledger-reconciliation-phase3a.md` (new)

No schema change is required. The existing forward-only Phase 1/2 migrations and
all database guards remain unchanged. Controllers, routes, POST V1/V2 payloads,
frontend, webhook, KYC/KYB, RFI and transfers are unchanged by this phase.
`tatus --short` was neither modified nor staged.

## Safety review and reused components

The companion request is the enrollment discriminator. Legacy reconciliation
must not write directly to enrolled operations: their identity/projection
triggers intentionally reject those writes. The existing service now routes
those operations through the ledger, makes baseline capture a no-op for them,
and rejects calls to its legacy attachment primitive for enrolled operations.
There is no second reconciliation engine, redispatch path or recovery token.

The existing immutable dispatch attempt, scope ownership, request identity,
state machine, POST response checker, Payment ID validator, encrypted exchange
store, provider HTTP client and atomic POST evidence resolver are reused. Exact
POST transport verification was extracted into one shared checker used both by
read-only inspection and by resolution under locks. The resolver additionally
revalidates current account ownership and target against the frozen snapshot
inside the resolution transaction. Eligibility revocation alone does not prevent
recording an already-authoritative assignment; reconciliation never dispatches.

The pre-existing H2 limitation and legacy/webhook nonparticipation remain cutover
blockers. Database guards protect enrolled operations from legacy direct writes;
this is not approval to run mixed live writers or to activate the workflow.

## Enrolled reconciliation sequence

1. Reload the operation and locate its companion request. Return terminal
   assigned/failed state without HTTP. A claimed request with no consumed attempt
   remains claimed, with its scope held and no HTTP or invented consumption.
2. Restore the frozen environment/origin, provider/user/account identity,
   client/customer/wallet, POST API version, payload hash and provider request UUID.
   Reject changed current target or ownership. Never substitute current dimensions
   or bank defaults for the saved request.
3. Inspect every potentially correlated persisted Assign Payment ID exchange,
   rather than selecting a first/latest response. Validate exact POST target,
   version, operation key, provider/user/client bindings, request UUID, payload,
   response-received flag and request/response body hashes through the ledger.
4. Resolve only one consistent, usable Payment ID from authenticated direct
   HTTP 200 POST evidence. Explicit dimension contradictions, malformed IDs,
   response errors or multiple distinct correlated success IDs require review.
   Repeated consistent evidence for the same ID does not create ambiguity.
   Non-200 POST results do not establish a failure in this reconciliation path.
5. Without sufficient direct evidence, use the existing HTTP client and V2
   `paymentIds` GET contract. V1 assignments also use this existing V2 read
   contract; a V1 listing contract is not inferred. The configured GET template
   must be the supported existing V2 template. The absolute URL uses the saved
   origin/client/customer/wallet and frozen currency. The POST version remains
   frozen and is used for POST evidence validation.
6. GET runs outside database transactions, without redirects or retries. The
   narrowly scoped transport mode rejects every non-GET request and auth modes
   that could acquire tokens by POST. Existing transport defaults are preserved
   for other callers. Provider HTTP logging/encrypted evidence capture is reused.
7. Accept only HTTP 200 with an object containing a `content` list of objects and
   explicit integer `totalPages` and/or boolean `last`. Validate page identity,
   optional size/item-count/first/empty metadata, stable totals, optional complete
   element count and consistency between totals/last. Reject alternate/nested
   envelopes, malformed items/IDs, repeated IDs/pages, partial non-final pages,
   missing metadata and changing totals. Fetch at most five pages of twenty; a
   listing beyond the limit remains incomplete. Pagination is not a guarantee of
   an atomic provider inventory snapshot, so even a complete GET cannot assign.
8. A complete nonempty listing requires manual review because operation-level
   creation correlation is unproven. Multiple IDs never select a winner. An empty
   listing, 404, timeout, 429, 5xx, redirect, malformed schema or incomplete
   pagination preserves the existing unresolved state and scope. None establishes
   definitive POST failure. Safe reason codes are logged without provider IDs or
   sensitive targets.

## Evidence rules and manual review

Only the existing authenticated, operation-bound direct POST evidence is currently
automatically authoritative. Tags in GET data, local GET log operation labels,
currency/bank/type matches, creation order and absence from a guessed baseline
are not proof that this operation created an ID. No verified GET-tag contract or
other authoritative exact-correlation source is available in the repository;
those evidence tiers remain unavailable pending separately reviewed contracts.

Wrong request UUID, target, operation key or body hash cannot resolve an operation.
Explicit contradictory fields on a verified direct response retain unresolved
scope. All applicable optional response dimensions use the shared V1/V2 response
checker, including client/customer/wallet and bank. The shared Payment ID validator
rejects malformed identifiers and the INITIALIZED sentinel as assigned IDs.
INITIALIZED evidence alone remains unresolved and may proceed to read-only GET.

Manual-review cases include insufficient GET correlation, multiple GET candidates,
contradictory listing dimensions, malformed/contradictory correlated POST results,
conflicting POST success IDs and suspected existing Payment ID ownership. An
existing manual-review operation can resolve later from sufficient verified POST
evidence; otherwise it remains in review and retains scope. Prepared-but-never-
consumed requests and unavailable/missing provider evidence require investigation;
no recovery POST or manual override endpoint is supplied.

Existing API logs and encrypted exchanges retain unresolved evidence under the
provider/user/operation key. No evidence is deleted or rewritten by reconciliation.
Only terminal evidence acquires the existing ledger reference/fingerprint and
immutability protections. Unresolved evidence retention still depends on existing
retention policies; this phase does not introduce a new retention/archival system.

## Resolution transaction and H2

HTTP and advisory evidence inspection happen before the transaction. The existing
resolver then locks provider account, Wallet/Currency scope, companion request,
operation, selected API log and exchange, followed by collision/VA work. It repeats
exact evidence verification and frozen ownership/target checks under those locks,
checks expected state version and the existing transition graph, and requires the
consumed dispatch attempt. Stale workers cannot rewrite a winner.

For assignment, the existing PostgreSQL transaction-level advisory lock serializes
participating resolvers for the literal Payment ID under READ COMMITTED. Existing
VA/operation ownership is checked after acquiring that lock. Any suspected
collision retains scope and moves to manual review without adopting or
rewriting the existing VA. This conservative quarantine does not define Nium's
identifier ownership domain.

The same transaction records the evidence reference/fingerprint and assigned
outcome, mirrors the operation, creates and attaches the VA, and finally releases
scope. The existing attachment guard verifies local account, ID and dimensions.
Any failed insert/attachment or stale/contradictory resolution rolls back the
transaction; no false assigned outcome or released scope survives. Terminal
states and evidence remain immutable; duplicate reconciliation returns the saved
state without HTTP or another VA.

H2 is unresolved: authoritative provider documentation must establish whether
`uniquePaymentId` ownership is scoped by wallet, customer, client or provider
origin/environment, followed by reviewed historical-row mapping and cutover.
Existing rows lack frozen origin/client metadata, and legacy/webhook/raw writers
do not take the participating advisory lock. Passing the participating PostgreSQL
collision race neither proves global uniqueness nor closes H2. No guessed global
constraint or existing-row overwrite is introduced.

## Legacy compatibility and Phase 3D status contract

Operations without companion requests retain their existing baseline/delta GET
behavior, transport, resolution and command entry point. No enrollment, migration,
backfill or replay is performed. The existing baseline-fetch and legacy
attachment paths remain available only to legacy operations. Existing legacy
regression tests pass, including baseline failure/incompleteness, timeout recovery,
pending recovery and ownership collision behavior.

The existing authenticated backend GET
`/api/users/{user}/provider-accounts/{provider}` is the smallest suitable future
Phase 3D surface: `ProviderAccountController::show` already resolves an account
within the requested user/provider relationship. The virtual-accounts GET alone
cannot show unresolved requests because the ledger creates no placeholder VA.

A future additive `virtual_account_operations` projection on the account GET needs
only caller operation key (`operation_id`), `status`, `currency`, `updated_at`,
nullable `provider_payment_id`, and nullable `nium_virtual_account_id`. Queries
must be scoped through that user's provider account and existing ownership
middleware. Use only mirrored operation columns; keep request snapshots,
credentials, exact targets, raw exchanges and internal evidence references
private. Polling must be database-only, without reconciliation, provider HTTP or
POST replay. The current assignment response already exposes an unresolved
operation subset with HTTP 202, but POST replay is not a read-only polling API.
This phase identifies the contract only and does not add an API or frontend.

## Validation and remaining blockers

All provider responses are mocked. SQLite tests create isolated in-memory schemas
without outer transactions. The required 17 test categories are covered by the
new reconciliation class, the existing legacy/safety/adapter tests, and the
PostgreSQL cases. The new class asserts no non-GET provider requests after every
test, including errors, claimed/terminal operations and token-acquisition auth.

Validation on 2026-10-27:

- Relevant SQLite/unit/API/transport/webhook/transfer/RFI regression selection:
  **481 tests, 3,675 assertions, all passed**.
- Final focused reconciliation run after additional strict page-metadata checks:
  **55 tests, 543 assertions, all passed**.
- Opt-in PostgreSQL suite: **38 tests, 481 assertions, all passed**. Four new cases
  exercise concurrent reconciliation of one operation, participating cross-account
  duplicate-ID quarantine, a real account-lock wait followed by changed-wallet
  revalidation, and rollback/recovery after injected VA insertion failure.
- PostgreSQL 18 used a newly initialized disposable
  `/tmp/nium-va-ledger-pg.xMmBG6` cluster and `nium_va_ledger_test`, with TCP
  disabled and independent forked worker connections. Repository migrations ran
  only in that empty disposable database. The test cluster was stopped afterward.
- Targeted PHP formatting and syntax checks pass. `git diff --check` passes; the
  index remains empty. Branch remains `main`, with prior local work preserved.

Logs for local review are `/tmp/nium-va-phase3a-regressions.log`,
`/tmp/nium-va-reconciliation-final.log` and
`/tmp/nium-va-ledger-pg.xMmBG6/tests.log`. Concurrency tests cover application
interleavings and injected failures, not provider acceptance after a lost response,
physical COMMIT packet loss, WAL loss, host power loss or replica promotion.

Remaining activation blockers: H2 ownership-domain proof and historical mapping;
reviewed authoritative GET correlation/schema contracts; recovery for absent or
unusable direct evidence; unresolved evidence retention; legacy/webhook writer
fencing and cutover inventory; separately approved later Phase 3 work, including
status/UI integration. No Phase 3B or webhook work was started. Stop here for
technical-lead review; passing tests do not authorize live activation.
