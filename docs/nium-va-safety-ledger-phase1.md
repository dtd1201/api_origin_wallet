# Nium VA safety ledger: hardened Phase 1

This remains an inactive persistence foundation. Existing assignment, GET
baseline, POST V2 endpoint/payload, controllers, commands, webhooks, reconciliation,
KYC, RFI, transfers and frontend do not invoke the new ledger. No shared HTTP
transport changes are required or included.

## Storage and database authority

The additive migration creates only three companion tables; original operation
columns, serialization and historical rows are unchanged. It performs no backfill.

- `nium_va_requests`: encrypted canonical request snapshot, payload SHA-256,
  unique stable provider UUID, version and authoritative `operation_state`.
  Nullable `outcome` holds the terminal Payment ID, resolution code and resolution
  timestamp; resolution references and an evidence fingerprint bind the proof.
- `nium_va_dispatch_attempts`: an operation primary key and consumption timestamp.
  Insertion consumes authorization permanently once committed. Update/delete are
  prohibited. Consumption is not proof of a provider POST or provider acceptance.
- `nium_va_assignment_scopes`: persistent primary key for provider environment,
  client, actual wallet and currency, with a nullable unique active operation ID.
  Category/type/bank and local account ID do not split a wallet scope. Sequential
  intentional USD assignments reuse the row after verified terminal resolution.

Requests and scopes cannot be deleted. Request identity, original operation
identity/dimensions, baseline fields and timestamps identifying the original
claim cannot change after enrollment. The original VA association is also frozen
in Phase 1 (null for new ledger operations); Phase 2 must explicitly design its
atomic attachment before activation.

A request UPDATE must advance the database's current version by exactly one,
follow the monotonic state graph, own the scope, and have consumed authorization.
Terminal transitions require outcome fields, evidence references and fingerprint.
An AFTER UPDATE trigger mirrors the authoritative request projection onto the
original operation. Direct SQL/Eloquent changes to an enrolled operation's state,
Payment ID, resolution code, resolution timestamp, submission timestamp or log
reference must match that projection or are rejected. Terminal companion rows
cannot be updated again, including with a newer version. Harmless identical
original values/updated_at touches do not change an outcome.

Referenced API logs, exchange evidence and webhook events are protected against
UPDATE/DELETE once used as resolution evidence. Relevant foreign keys are
restrictive; evidence and scope lookup indexes support these guards. Legacy
unreferenced logs/events and operations remain governed by their existing flows.
These triggers are integrity protection, not a privilege boundary against a DB
owner disabling triggers or executing arbitrary fabricated ledger transitions.

## H1: commit boundary

All ledger mutation methods reject an enclosing Laravel or raw PDO transaction.
Consumption uses one top-level transaction and one database-unique attempt;
PostgreSQL `synchronous_commit` is set locally to `on`. The return happens only
after commit and a check that no transaction remains active. No HTTP calls exist
inside these primitives. Commit/acknowledgement exceptions return no capability,
close the connection when a prepared consumption may have committed, and are
never automatically retried.

A commit that succeeded despite a lost acknowledgement leaves an immutable row:
subsequent consumption returns null. A transaction proven rolled back before any
capability was returned has not consumed authorization and has not authorized a
POST. A worker exit after committed consumption leaves the scope held and the
attempt unavailable, even when no provider request actually occurred. No expiry,
24-hour provider window or timeout restores authorization. Future transport must
use only the immediate successful result, never a loaded historical attempt.
Durability assumes PostgreSQL fsync/storage and any failover policy actually
preserve acknowledged commits; this code cannot repair lost database history.

## H2: transitions, versions and duplicate evidence

```text
claimed -> submitting                        consume only
submitting -> pending | unknown | manual_review
pending -> unknown | manual_review
unknown -> manual_review
submitting | pending | unknown | manual_review -> assigned | failed
                                              verified evidence only
```

Unresolved states hold ownership indefinitely. Resolution and release are one
transaction. Exact duplicate resolution with the current version and identical
frozen evidence returns the existing result without mutation. Stale versions,
new/conflicting evidence and terminal outcome rewrites fail closed. A new
intentional assignment requires a new operation key and UUID.

The isolated direct-POST resolver requires persisted evidence matching frozen
provider/user/client, UUID, exact target path, HTTPS origin and effective payload,
plus matching raw body hashes. It freezes a fingerprint of the correlated
exchange. It accepts HTTP 200 with a usable non-INITIALIZED uniquePaymentId, or a
narrow HTTP 400 structured field_missing/invalid_input rejection. These local
response rules still require technical-lead/provider-contract review before any
live use. Unknown, 404, INITIALIZED, timeout, 408, 429 and 5xx do not establish a
terminal outcome. The resolver does not create a VA in Phase 1.

### Payment ID safety correction

`NiumPaymentIdValidator::isValid()` is the shared check for direct POST assignment
and conclusive legacy assignment classification. It rejects non-strings, empty
values, case-insensitive `INITIALIZED`, every ASCII control byte (including CR,
LF, tab and NUL), DEL, leading/trailing ASCII or Unicode whitespace, and invalid
UTF-8. Values are never trimmed or coerced. The existing 255-byte conservative
storage bound is retained because operation Payment IDs use a 255-character
column; this is not evidence of a provider maximum. Longer or unrepresentable
values remain unresolved pending contract/storage review. No bank-specific
allowlist, numeric-only rule or fixed-length format is inferred. Passing this
check establishes syntactic safety only, not that Nium assigned the identifier.

After exact request/evidence correlation, HTTP 200 with a missing/malformed ID,
or any response explicitly carrying a malformed ID (including null), moves
`submitting`/`pending` to `unknown`. Existing `unknown`/`manual_review` remains
unchanged at the expected version. The operation Payment ID, terminal outcome and
resolution fields stay null; dispatch consumption and scope ownership remain
intact. `INITIALIZED` follows this unresolved path and is never an identifier.
The existing API log and encrypted raw exchange remain available with operation
key and request UUID correlation, without rewriting their contents. Terminal-only
evidence references are not populated for unresolved outcomes, so these records
still need normal retention; they do not gain terminal-evidence immutability.
Stale versions, uncorrelated evidence, and late/conflicting terminal evidence
still fail closed. A separately persisted authoritative valid response can later
resolve at the current version without any new dispatch authorization.

Malformed legacy assigned IDs keep the read-only barrier closed; this correction
does not change historical rows. The ledger is still inactive and the existing
live POST/GET flow is untouched. No schema or rollout configuration is changed.

## H3: trusted provider environment and bank

`RequestIdentity::forAccount()` derives origin, client and effective V2 endpoint
from trusted services.nium configuration and customer/wallet/provider/user from
the account. It accepts the already resolved effective payload. It never accepts
an application environment label. The provider environment is a deterministic
origin fingerprint, so APP_ENV=prod/production cannot split ownership. Origin
canonicalization folds host case and default HTTPS port; UUID identifiers fold
case. URL userinfo/query/fragment, non-HTTPS origins, base-path prefixes and
unsupported endpoint templates fail closed. DNS aliases representing the same
provider realm still require an explicitly reviewed canonical configuration.

Origin and target are stored with the payload, not reread to reconstruct a
historical snapshot. Preparation and consumption verify current trusted target
configuration still matches the saved snapshot. A changed origin/client/account
identifier cannot silently dispatch the old operation somewhere else. Consumption
uses the saved bank value/absence; it does not rerun the country/bank resolver or
apply new defaults. Profile/code changes therefore cannot alter this request.

Current live capture redacts customer/wallet paths, and such evidence deliberately
fails exact-target verification. Do not weaken redaction or put target identifiers
into public URLs/logs. Phase 2 must supply authenticated target binding without
changing the privacy guarantees. Test exact-target evidence is synthetic only.
Credentials/authentication headers are never stored in the request snapshot.

## H4: read-only legacy barrier and cutover

`legacyOperationEligibility()` only SELECTs existing data. It searches Nium
operations without companion requests across local accounts sharing the actual
wallet/currency. Same-account work and unknown/empty wallet mappings are treated
conservatively. Historical rows have no trustworthy origin/client snapshot, so
absence of that metadata cannot justify ignoring a matching legacy operation.

Claimed/preflight_failed/submitting/pending/unknown/manual_review and unrecognized
statuses block new keys. An assigned row clears this barrier only with a resolved
timestamp and usable non-INITIALIZED Payment ID. Historical failed rows also
block: the old generic failure classification does not prove provider
non-acceptance. Such cases require separately reviewed authoritative/manual
resolution; Phase 1 does not invent proof or modify the legacy row. The original
legacy operation key can never be enrolled through prepare(), even when assigned.

The new primitives check this barrier during preparation and immediately before
consumption. It remains inactive for live requests. A SELECT alone cannot fence
an old worker that ignores scope locking. Safe cutover, to be performed only
under later technical-lead approval:

1. Apply the reviewed additive schema while the new workflow remains inactive.
2. Fence every old assignment entry point, including CLI/jobs, drain/stop old
   workers, and confirm no old process can still reach POST. Do not run mixed
   assignment writers. Continue treating already in-flight outcomes as uncertain.
3. Inventory unresolved legacy operations and earlier API evidence, including
   missing/changed historical wallet mappings. Quarantine ambiguous wallets and
   obtain verified resolution; never backfill invented consumption or baselines.
4. Deploy only approved Phase 2 workers, verify the same canonical origin/client
   configuration and encryption keys, then enable new writers under the reviewed
   gate. Keep legacy writers fenced throughout. New operations must pass the
   read-only barrier and acquire the durable scope.

The helper cannot reconstruct a wallet's historical identity after that identity
was changed or deleted. Cutover inventory/manual quarantine is required for those
cases. It is not a claim that mixed-version assignments are already safe.

## Lock order, recovery and key retention

Mutation calls must start outside all transactions and pre-held row locks.
Phase 2 audit hardening makes every ledger mutation lock the provider account
first, then scope, companion request and operation, then resolution API log,
exchange and VA when needed. Preparation reads existing operation identity without
a row lock, then locks its request before its operation. Request-before-operation
matches the database mirror trigger; account-first prevents the account/operation
cycle involving VA foreign-key checks. Preparation and consumption recheck the
current account eligibility while that account lock is held. Phase 3 must review
the existing webhook's VA-before-operation write order before reusing it for
enrolled operations; legacy webhook/reconciliation paths must not mutate them.
Raw companion writers must follow this same lock order and CAS protocol.

The migration is intentionally forward-only: down() always throws before any
DDL, even when empty, avoiding a race with a first concurrent authorization.
Rollback the application to an inactive compatible version while retaining the
schema/history; use a reviewed forward corrective migration or point-in-time
recovery with reconciliation. Never drop/truncate/reset attempts or remove
ownership merely to unblock work. On a database restore/failover that could lose
accepted attempts, fence dispatch and reconcile provider outcomes first.

Keep APP_KEY and APP_PREVIOUS_KEYS sufficient to decrypt every retained snapshot
and evidence record. Ordinary re-encryption is blocked by ciphertext immutability;
key retirement or ciphertext migration requires an explicit reviewed forward
maintenance procedure. Hashes detect content mismatch but do not establish
provider authenticity; that depends on trusted transport capture and controlled
DB writers. Evidence-retention jobs must retain protected referenced records.
Installing triggers/FKs locks existing tables briefly; use reviewed bounded DDL
lock timeouts and deployment ordering. No Production access was needed to test
compatibility against the repository schema.

## Validation boundaries

Focused SQLite tests use separate ephemeral schemas without outer transactions.
They cover H1-H4, immutable evidence, sequential USD operations, legacy migration
compatibility and unchanged GET-before-POST behavior. Existing Nium VA regression
tests use mocked HTTP only.

The opt-in PostgreSQL class uses a pre-migrated disposable Unix-socket database,
separate connections and forked workers. It covers consumption/scope/state races,
outer-transaction rejection, exceptions immediately before/after actual commit,
SIGKILL after committed consumption, and database outcome/evidence guards. Event-based
failure injection is not a physical COMMIT packet loss or a database power-loss
test. No live POST, provider-acceptance timeout, SIGKILL during WAL flush, host
power failure or replica-promotion test is performed. Those and the dedicated
one-shot transport/atomic VA attachment/reconciliation remain Phase 2 work.
