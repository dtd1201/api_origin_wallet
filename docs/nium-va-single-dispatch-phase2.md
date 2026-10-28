# Nium VA Phase 2: inactive single dispatch

This implementation is for isolated tests and review. It makes no live Nium
requests and does not activate any account. POST Assign Payment ID stays V2.
Frontend, webhook, transfer and reconciliation code are unchanged.

## Preconditions verified

The shared Payment ID validator is used by direct evidence resolution and legacy
classification. The exact `VA\nINVALID` response remains unresolved, stores no
Payment ID, keeps scope ownership and prevents a different key from consuming
authorization. H1-H4 tests cover top-level durable consumption, commit exceptions,
immutable attempts and evidence, monotonic versions, terminal outcomes, trusted
target identity, effective bank freezing, and conservative legacy ambiguity.
The ledger still has one scope-release site, following verified terminal evidence
in the same transaction. Phase 2 itself treats every non-200 response as uncertain;
it does not invoke the Phase 1 narrow 400 rejection resolver for dispatch results.

## Assignment sequence and boundaries

1. The existing service verifies the currently eligible account, customer and
   wallet and validates request dimensions and the explicit operation key. The
   existing API authorization and ownership middleware stays in place.
2. Historical operations stay on their legacy path. They are never enrolled.
   In the enabled test flow a historical `preflight_failed` operation is not
   permitted to restart a legacy POST. With the gate disabled, legacy GET/POST
   behavior and GET 404 handling are unchanged.
3. The HTTP entry point requests automatic bank selection without passing today's
   resolved bank as an explicit caller choice. The new orchestrator resolves the
   effective bank before preparation. An
   existing key must match its frozen effective payload and target. When bank
   selection was omitted, replay uses the stored effective bank rather than a new
   profile default. Existing keys return current operation/VA state without HTTP,
   including previously claimed or consumed operations.
4. Preparation commits the encrypted origin, client/customer/wallet, V2 endpoint,
   canonical payload, payload hash and stable provider request UUID. It locks the
   durable Wallet/Currency scope, checks legacy ambiguity, and claims ownership.
   It creates no baseline and no placeholder VA.
5. All ledger mutations lock provider account, scope, request and operation in
   that order, followed by evidence/VA rows if needed. Consumption rechecks current
   account eligibility under the account lock, then target and legacy eligibility,
   inserts the unique immutable attempt and advances to `submitting`.
   PostgreSQL uses local `synchronous_commit=on`. A non-null return is available
   only after the top-level commit and transaction checks succeed. Commit errors
   propagate before HTTP; no automatic retry is made.
6. Outside every database transaction, the immediate successful caller sends one
   POST using the frozen absolute target/payload and persisted UUID as
   `x-request-id`. The opt-in assignment transport forces one Laravel attempt and
   disables redirects. It does not change default transport behavior for other
   operations. There is no queue, retry job, GET baseline or recovery POST.
7. Existing provider logging persists a redacted public URL and encrypted raw
   exchange. Only this transport also records the actual outgoing target in the
   encrypted, hidden `assignment_target` column. The resolver verifies that exact
   target, UUID, effective payload and body hashes, together with the log's
   provider/user/client/key bindings. Terminal evidence fingerprints include the
   target binding; fingerprints for older evidence without it remain compatible.
8. Authoritative direct success resolves the request, mirrors the operation,
   creates and attaches its VA, then releases the scope, all in one transaction.
   The additive migration permits exactly one null-to-VA attachment while that
   operation still holds the scope and has a verified assigned outcome. Dimensions,
   account ownership and Payment ID must match; other identity/projection guards
   remain intact. Rebinding, detaching and terminal rewrites remain prohibited.
   A failed VA insert/attachment rolls the whole resolution back.

Only the immediate committed consumption result permits dispatch. Loading a
historical attempt never does. Concurrent prepares can share one operation, but
the unique consumption record permits only one worker to send. Different keys
cannot bypass an unresolved scope. A crash after preparation can leave `claimed`;
after consumption it can leave `submitting`, even if no POST occurred. Repeated
calls do not recover either case automatically.

## Response classification

| Result | Operation state | Scope |
| --- | --- | --- |
| HTTP 200, usable `uniquePaymentId`, consistent optional fields, correlated persisted evidence, unowned local ID | `assigned`, with attached VA | Released atomically |
| HTTP 200, exact case-insensitive `INITIALIZED`, no errors | `pending`, no VA/Payment ID | Held |
| HTTP 200, missing/malformed ID or JSON | `unknown`, no VA/Payment ID | Held |
| HTTP 200 with contradictory or unverifiable explicit target fields | `unknown`, no VA/Payment ID | Held |
| Timeout or transport exception | `unknown` when persistence succeeds | Held |
| Any non-200, including 400/404/408/409/429/5xx and redirects | `unknown` | Held |
| Evidence persistence or local-resolution failure | `unknown`, or durable `submitting` if the database is unavailable | Held |
| Identifier already present on a VA or another operation | `manual_review`; existing records untouched | Held |
| Duplicate verified terminal evidence | Existing result, no mutation | Existing terminal result |
| Stale/conflicting/late response after a verified terminal winner | Existing terminal result preserved | Existing terminal result |

There is no definitive rejection classification in the Phase 2 dispatch path.
Evidence may prove a valid assignment later, but this phase never obtains it by
redispatch. Evidence persistence transactions can use the existing database-only
retry behavior; the HTTP request is outside them and is never retried.

## Compatibility and inactive gate

`services.nium.va_single_dispatch` defaults to `enabled=false`, an empty account
allowlist and `old_workers_fenced=false`. It has no environment-variable activation
shortcut. Even if these settings are changed, dispatch refuses every application
environment except `testing`. Production, staging and local activation require a
separately reviewed Phase 3 change; do not enable it for GREENTECH or any other
live account. The fence setting is only a test interlock, not proof that old
processes have actually stopped.

An enrolled key always returns through the ledger path even after disabling the
gate. New keys on enrolled wallets cannot fall back to legacy writers. This is
necessary to avoid losing scope protection during application rollback. Gate-off
legacy wallets continue using the existing workflow. The backend API returns 202
with a safe operation projection and null VA for unresolved ledger operations;
the CLI prints their state. Legacy and assigned responses keep their previous
behavior. No frontend changes are included.

The forward-only migration adds only the nullable encrypted target-binding
column and revises the operation attachment guard. It performs no backfill or
historical data updates. Apply no schema changes to Production under this task.
The Phase 1 migration is unchanged. Rolling application code back must retain
ledger history and keep assignment writers fenced.

## Phase 3 and limits

Phase 3 must implement verified reconciliation for claimed/submitting/pending/
unknown/manual-review operations, including process death after provider
acceptance and historical baseline ambiguity. Do not invent baselines or treat
GET 404 as empty inventory. Webhook-created VAs are deliberately not adopted:
without reviewed operation correlation, even a matching HTTP ID holds the scope
for manual review. There is no enrolled pending VA for the legacy webhook writer
to mutate. A later webhook for a terminal assigned VA cannot rewrite the terminal
operation through the existing webhook's terminal-state filter.

Before live use, review response contracts, inventory old operations and wallet
identity history, quarantine ambiguous mappings, fence/drain every old writer,
review webhook/reconciliation lock order and correlation, retain all unresolved
evidence and encryption keys, and perform approved cutover. Unresolved logs are
retained normally and do not acquire the terminal evidence immutability guards.
Provider ID syntax remains a conservative safety check, not bank-format proof.

## Audit hardening and outstanding ownership blocker

The shared assignment response checker compares explicitly present `currencyCode`
and `currency`, `accountCategory`, `accountType`, `walletHashId`, `customerHashId`
and `bankName` to the immutable request. These names come from the existing
Payment ID/VA parsers and Nium customer/wallet mapping in this repository;
`currency` is the existing VA webhook alias. No additional undocumented aliases,
nested shapes or display-bank names are interpreted. Optional fields may be
omitted. Present null/non-string fields, mismatched aliases, case/whitespace
differences, and a bank when no bank was frozen remain unresolved. No trimming,
case folding or coercion turns evidence into agreement. The orchestrator and
direct ledger resolver both enforce this policy, including contradictory
`INITIALIZED` responses. Raw evidence stays encrypted and errors contain no field
values. V2 request fields and endpoint remain unchanged; the separately supplied
V1 example is not a V2 contract or an activation authorization.

H2 is **BLOCKED**. Available repository documentation identifies provider,
client, customer and wallet, but does not establish whether `uniquePaymentId` is
unique per wallet, customer, client or provider environment. The existing VA
constraint is only `(user_provider_account_id, provider_payment_id)`; a global
application existence check cannot serialize absent IDs across accounts. No new
constraint, ownership-domain assumption, migration or historical rewrite is made
in this hardening. In particular, existing VA rows lack frozen origin/client
metadata, and different local accounts can refer to one wallet. A guessed
provider-wide constraint could reject legitimate history, while a wallet/local
account constraint could miss real ownership conflicts. The requested
cross-account duplicate-ID PostgreSQL ownership test and constraint change remain
blocked pending authoritative Nium uniqueness documentation and reviewed mapping
of pre-existing rows. Phase 2 remains on HOLD; do not activate it.

The account eligibility decision is made while holding `FOR UPDATE` in the same
transaction that durably consumes authorization. A revocation committed before
this lock is granted is observed and blocks authorization. PostgreSQL tests
verify a real lock wait for revocation during preparation and consumption.
Authorization commit releases this lock before HTTP. A revocation/new-account
creation after that decision can still race the outbound POST; eliminating that
window requires a separately reviewed protocol and must not be attempted by
holding a database transaction open across HTTP. Resolution may still persist
authoritative evidence after revocation, since doing so does not send a POST.

The lock-order test pauses a changed-currency same-key prepare after its account
lock, starts resolution on an independent PostgreSQL connection, verifies that
resolution waits for the account without holding the operation, and then lets
the conflicting prepare roll back. Resolution completes, attaches one VA and
releases scope; replay sends nothing. This is the new ledger lock protocol,
not a claim that legacy webhook/reconciliation writers or old workers have been
made compatible. Those writers remain a Phase 3 cutover requirement.

Tests use synthetic evidence and HTTP fakes, isolated SQLite schemas and a
disposable PostgreSQL Unix-socket cluster with forked workers. SIGKILL and commit
event injection test application failure boundaries; they do not demonstrate
physical COMMIT packet loss, host power failure, WAL loss or replica failover.
At-most-once dispatch still depends on acknowledged database history surviving
storage/failover. Automatic provider allocation and mixed-version live writers
cannot be made safe by this application feature gate alone.

Audit-hardening validation (2026-10-27): the full relevant SQLite/unit/regression
selection passed 374 tests / 2,723 assertions, including VA legacy/API, shared
transport/evidence, configuration, webhook, transfer and RFI regressions. The
disposable PostgreSQL suite passed 25 tests / 279 assertions, including six
revocation interleavings, the controlled account/operation lock-order scenario,
same/different-key arbitration, crashes and commit uncertainty. All provider HTTP
traffic in these tests is mocked; PostgreSQL uses a private `/tmp` Unix-socket
cluster with TCP disabled and is stopped afterward. No deployment configuration
or feature defaults were changed. These passing tests do not close the blocked
H2 cross-account ownership requirement or the deferred Phase 3 frontend/webhook
and cutover requirements.
