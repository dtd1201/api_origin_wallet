# Nium VA Phase 3B: ledger-aware webhook integration

Implemented locally on `main`, 2026-10-27, for technical-lead review. Existing
assignment activation gates are unchanged: disabled by default and testing-only.
No GREENTECH or other live account was accessed or activated. No assignment POST,
Nium API request, Production access, deployment, commit or push was performed.
Only public documentation was fetched. No Phase 3C/3D work was started.

## Phase 3A safety preconditions

The existing implementation was inspected before Phase 3B implementation; the
isolated enrolled reconciliation suite passed **55 tests / 543 assertions**.
No additional implementation-blocking Phase 3A issue was identified. The known
H2 and cutover limitations remain Production blockers.

1. The ledger verifies the exact persisted POST origin, API version and endpoint,
   frozen provider/user/client/customer/wallet, operation key, request UUID,
   effective payload and body hashes. Shared response-dimension and Payment ID
   validation apply. Public redacted URLs alone are insufficient evidence.
2. GET 404, incomplete pagination, malformed responses and missing correlation
   retain unresolved scope. GET listings cannot assign an enrolled operation.
3. Reconciliation has no assignment POST path. The enrolled GET transport also
   rejects non-GET methods, redirects and token-acquisition authentication modes.
4. Account/scope/request/operation locks, expected state versions, dispatch-attempt
   checks and database guards prevent stale workers from rewriting terminal state.
5. Existing VA/operation ownership conflicts are quarantined; participating
   PostgreSQL resolvers serialize literal-ID checks under READ COMMITTED.
6. The old VA webhook used VA-before-operation locking and direct operation
   updates. That was understood as the integration gap addressed here. Enrolled
   notifications now use the established ledger order. The current legacy webhook
   writer takes the account lock first and joins the same collision lock before
   touching a VA; it cannot update enrolled operations directly.

The final PostgreSQL suite reruns the existing Phase 1/2/3A cases as well as the
new webhook races. This does not prove safety for old deployed/raw writers.

## Exact files changed in this phase

These nine files are the Phase 3B delta. Prior Phase 1/2/3A local work is preserved.

- `app/Models/WebhookEvent.php`
- `app/Services/Nium/NiumWebhookService.php`
- `app/Services/Nium/NiumVirtualAccountWebhookContract.php` (new)
- `app/Services/Nium/NiumVirtualAccountReconciliationService.php`
- `app/Services/Nium/NiumVirtualAccountSafetyLedger.php`
- `database/migrations/2026_10_27_000003_preserve_nium_va_webhook_evidence.php` (new)
- `tests/Feature/NiumVirtualAccountLedgerWebhookTest.php` (new)
- `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php`
- `docs/nium-va-ledger-webhooks-phase3b.md` (new)

Controllers, routes, HTTP transport, API exchange storage, POST V1/V2 payloads,
rollout settings, frontend, KYB/KYC, RFI and transfer behavior are unchanged by
this phase. The existing webhook ingress and reconciliation/ledger resolver are
reused; the new contract class only validates/captures evidence. There is no
second webhook endpoint, allocation engine or outbound assignment dispatcher.

## Documented event contracts

Public Nium documentation retrieved on 2026-10-27:

- [Virtual Account Assigned](https://docs.nium.com/docs/developers/notifications-and-webhooks/platform-events/virtual-account-assigned)
- [Virtual Account Assignment Failed](https://docs.nium.com/docs/developers/notifications-and-webhooks/platform-events/virtual-account-assignment-failed)

The assigned event is a flat JSON object with template
`VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK`. Its documented fields are:

| Fields | Meaning |
| --- | --- |
| `clientHashId`, `customerHashId`, `walletHashId` | Provider target identifiers |
| `currencyCode`, `uniquePaymentId`, `uniquePayerId` | Currency and assigned provider identifiers |
| `fullBankName`, `accountName`, `bankAddress` | Display/bank details |
| `routingCodeType1`, `routingCodeValue1`, `routingCodeType2`, `routingCodeValue2` | Routing details |
| `accountType` | `LOCAL`, `GLOBAL`, or `LOCAL+GLOBAL` |
| `template` | `VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK` |

The failed event is a flat object with `clientHashId`, `customerHashId`,
`walletHashId`, `currencyCode`, `bankName`, `customMessage`, and template
`VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK`. The documentation's bank example is
`DBS HONGKONG`, a display name. It contains no Payment ID or assignment operation
identifier.

Neither contract documents an assignment-key field, POST request-ID echo or other
reliable creation-causality link. The general delivery ID/header and body
fingerprint are deduplication identities only. Undocumented `operationId`, tags
and `requestId` fields cannot establish assignment or rejection.

Existing assigned aliases `VA_ASSIGNED` and `VIRTUAL_ACCOUNT_ASSIGNED` remain
recognized for legacy compatibility and evidence retention. They are not promoted
to documented ledger terminal evidence.

## Authentication, correlation and automatic outcomes

The existing `x-partner-key` verifier runs before receipt storage or processing.
Delivery identity is validated; conflicting explicit identity aliases and reused
IDs with different raw bodies are rejected, including the concurrent unique-key
race. When the documented body has no explicit event ID, existing ingress uses a
delivery header or authenticated body fingerprint. None is interpreted as an
assignment key.

For enrolled operations, lookup considers frozen histories and wallet aliases;
wrong or insufficient target evidence cannot fall through to the legacy writer.
No latest/only-pending/currency/time heuristic chooses an operation.

Automatic assignment requires all of the following:

1. Authenticated immutable receipt with the documented assigned template.
2. Exact client/customer/wallet and receiving integration origin/client context
   matching the frozen identity, with a valid non-INITIALIZED Payment ID and exact
   frozen currency. Shared validation rejects contradictory currency aliases.
3. Exactly one operation with usable, exact-target, request-UUID-bound direct
   HTTP 200 POST evidence for the same Payment ID. The existing Phase 3A POST
   evidence arbitration is reused, including conflicting/malformed POST evidence.
4. Every explicitly present dimension must be verifiable. There is no reviewed
   equivalence mapping from webhook account types to POST funding rails, from
   webhook display-bank names to POST bank codes, or for undocumented webhook
   account categories. Events containing `accountType`, `accountCategory`,
   `bankName` or `fullBankName` therefore cannot trigger automatic assignment.
   Equal spellings do not establish semantic equivalence.
5. Current ownership/target, version, consumed attempt and collision checks pass
   again under the existing ledger locks.

The direct POST log remains the terminal causal evidence reference/fingerprint.
The selected webhook is additionally locked and verified in that transaction.
Its immutable receipt provides a separate durable audit reference by event ID;
no unsupported webhook-only terminal proof is manufactured.

A same-target/same-ID observed assigned receipt with contradictory or unmapped
information also blocks a later nonterminal resolution through the shared ledger.
Such a later attempt moves to manual review and retains scope; polling cannot
silently discard that receipt. Already-terminal assigned/failed outcomes remain
immutable when a late contradictory event arrives.

For GREENTECH's intended V1/USD/JPM_SG/SELF_FUNDING_ACCOUNT/Wires request, the V1
payload/frozen `WIRES` rail remain unchanged. In particular, `GLOBAL` is not mapped
to `WIRES`, and a JPMorgan display name is not mapped to `JPM_SG`. The official
assigned example includes both display-bank and account-type information; that
shape remains observed/manual until reviewed mappings are available. Passing the
minimal agreeing webhook fixture does not authorize such a mapping or activation.

## Observed/manual cases and delayed processing

Authenticated events without sufficient evidence receive `processing_status =
observed`, with a safe reason in the existing error field. Operations retain their
current unresolved state and scope unless a verified collision/history check moves
them to manual review. Cases include absent or nonmatching POST evidence,
ambiguous causality, wrong/missing target, malformed/missing/INITIALIZED ID,
contradictory currency, and unverified bank/type/category mappings.

Failure events are retained as observed evidence. Even a failure body decorated
with an apparent operation key or delivery UUID matching a POST UUID cannot prove
that operation failed. No webhook failure releases scope or marks a ledger
operation failed. A truly authoritative correlated failure fixture is unavailable
under the documented contract; it is deliberately not invented for the test suite.

A webhook before POST response persistence stays observed, with no placeholder VA
or Payment ID attached. Later read-only reconciliation or existing receipt
reprocessing can use newly persisted authoritative evidence, subject to all
receipt/dimension checks. Reprocessing restores the exact authenticated encrypted
body; historical sanitized receipts without provenance cannot resolve a ledger.
Duplicates do not redispatch or allocate. Both arrival orders and stale failure
notifications preserve a terminal winner's exact ID and VA timestamp.

## Persistence, transactions and locking

The minimal forward-only migration adds one nullable encrypted/hidden
`webhook_events.nium_va_evidence` column. It records exact raw body and SHA-256,
authentication outcome, provider ID, receiving configured origin/client, and
receipt event identity/type. It stores no authentication key or authorization
header. Receiving configuration binds the receipt to an integration context; it
is not a cryptographic assertion of the sender's outbound API origin. Environment
routing and secret separation remain cutover requirements.

Database guards make new receipt evidence, its event identity, provider, payload
projection and received timestamp immutable and prevent deletion. Processing
status/timestamps/error fields remain writable. Existing webhook rows remain
null and are not backfilled with invented authentication proof. Non-VA webhook
rows retain their existing behavior. Retain decryption keys and protected receipts;
key rotation/ciphertext rewrites require reviewed forward maintenance.

Apply this additive migration before running the new code for enrolled work.
There is no Production migration in this task. Application rollback must retain
the schema/evidence and keep old assignment writers fenced; `down()` refuses to
drop safety history. Prior migrations, uniqueness constraints and attachment/state
guards are unchanged.

The existing receipt insert commits first, independently of processing. This
preserves authenticated evidence when processing fails. Resolution is a separate
top-level transaction with lock order:

`provider account -> assignment scope -> request -> operation -> API log/exchange
-> selected webhook evidence -> Payment ID collision lock/VA`.

Immutable previously captured receipts are also checked before nonterminal
assignment. The resolver revalidates frozen/current ownership and target, expected
version, dispatch attempt, direct evidence and webhook agreement. It atomically
records the outcome, creates/attaches the VA and releases scope. A PostgreSQL
constraint failure rolls back every resolution write while leaving the prior
receipt durable. Receipt processing status is updated afterward; failure of this
acknowledgement cannot authorize a POST or undo the terminal causal evidence.

Current legacy VA webhook writes take the account lock, reject enrolled histories,
validate explicitly provided target identifiers, acquire the same literal-ID
PostgreSQL advisory lock, then inspect VA/operation ownership before mutation.
They reject malformed/sentinel IDs and do not overwrite an existing real ID on a
pending VA. Legacy operation updates explicitly exclude enrolled rows. Normal
legacy automatic VA updates and detail GET synchronization remain intact.

## PostgreSQL results, H2 and validation

Final validation, 2026-10-27:

- Phase 3A precondition suite before implementation: **55 tests / 543 assertions**.
- Final relevant SQLite/unit/API/evidence/legacy/webhook/transfer/RFI regression
  selection: **534 tests / 4,106 assertions**, all passed.
- Focused webhook class: **51 tests / 387 assertions**, all passed.
- Final PostgreSQL suite: **44 tests / 560 assertions**, all passed. Six new cases
  cover webhook/reconciliation competition, concurrent duplicate receipts,
  cross-account ledger webhook collisions, mixed current legacy/ledger webhook
  writers, a controlled real account-lock wait proving later locks remain free,
  and a real PostgreSQL CHECK failure with rollback, receipt immutability and
  successful later reprocessing.
- All enrolled webhook tests assert zero outbound provider requests. Legacy
  concurrency/detail traffic is mocked GET only. No assignment POST originates
  from any VA webhook handler.
- Targeted formatting, PHP syntax and `git diff --check` pass. Existing unrelated
  formatting in the shared webhook service is preserved.

PostgreSQL 18 used a fresh `/tmp/nium-va-ledger-pg.zyI33J` Unix-socket cluster with
TCP disabled and independent forked connections. Final tests used the separately
created empty `nium_va_ledger_test_hardened` database, migrated only for these
tests. The cluster was stopped afterward. Logs are
`/tmp/nium-va-phase3b-precondition-tests.log`,
`/tmp/nium-va-phase3b-webhook-final.log`,
`/tmp/nium-va-phase3b-regressions-final.log`, and
`/tmp/nium-va-ledger-pg.zyI33J/tests-final.log`.

H2 remains unresolved. Participating ledger/current legacy webhook writers
serialize conservative collisions for a literal ID and never adopt an ambiguous
existing VA. This does not establish whether Nium IDs are unique per wallet,
customer, client or provider environment. Existing VA rows lack frozen
origin/client mappings; old deployed assignment/reconciliation/webhook versions
and raw database writers do not all participate. No guessed global constraint or
historical ownership rewrite is added. Conflicting historical ownership requires
review and remains a Production blocker.

Other Production blockers remain: authoritative standalone success/failure
causality, reviewed webhook-to-POST bank/type/category mappings, environment/secret
routing, evidence/decryption-key retention, historical wallet/operation inventory,
old-writer fencing and approved cutover. The current encrypted-history scans are
appropriate for isolated review; live volume/query strategy also requires review.
Concurrency tests demonstrate application interleavings and rollback, not provider
acceptance with lost evidence, physical commit/WAL loss or replica failover.

Git remains on `main`; the index is empty and prior local work remains unstaged.
`tatus --short` was neither altered nor staged. Stop here for technical-lead
review; these results do not authorize deployment or activation.
