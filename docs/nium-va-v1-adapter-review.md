# Nium Assign Payment ID V1 adapter — review handoff (2026-10-27)

Implemented locally on `main`. Phase 2 remains **HOLD**, H2 remains **BLOCKED for
Production**, and Phase 3 has not started. No feature or account was activated.
All provider responses used for validation were mocked. No live Nium requests,
Production database access, deployment, staging, commit or push occurred.

## Exact files changed by this task

The working tree already contained the Phase 1/2 implementation. Relative to that
starting state, this task changes exactly these files:

| File | Change |
| --- | --- |
| `app/Services/Nium/NiumVirtualAccountAssignmentContract.php` | New version-specific body builder and validator. |
| `app/Services/Nium/NiumPaymentIdService.php` | Explicit optional `apiVersion` argument, default V2; V1 cannot enter the legacy engine. |
| `app/Services/Nium/NiumVirtualAccountRequestIdentity.php` | Freeze/validate version-specific target and payload; separate immutable V1 funding rail. |
| `app/Services/Nium/NiumVirtualAccountAssignmentResponse.php` | V1/V2 dimension adapters and conservative delayed-response classification. |
| `app/Services/Nium/NiumVirtualAccountAssignmentService.php` | Select the adapter within the existing single-dispatch orchestrator. |
| `app/Services/Nium/NiumVirtualAccountSafetyLedger.php` | Persist local rail intent; serialize participating PostgreSQL collision checks; quarantine collisions even without VA attachment; keep V1 outside the V2-only narrow 400 rejection resolver. |
| `tests/Concerns/CreatesNiumVirtualAccountLedgerFixtures.php` | Generate synthetic evidence with the frozen version. |
| `tests/Feature/NiumVirtualAccountV1AdapterTest.php` | New mocked V1 contract, response, identity, replay and gate coverage. |
| `tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php` | V1/V2 dispatch races, mixed-version scope locking, cross-account conflicts, real advisory-lock wait and isolation checks; distinguish an old reused synthetic ID. |
| `docs/nium-va-v1-adapter-review.md` | This handoff. |

No migration, configuration, controller, command, frontend, webhook, KYB, RFI or
transfer file was changed by this task. Existing modifications in those areas
remain as received. `tatus --short` was not altered or staged.

## V1/V2 contract comparison

| Dimension | V1 adapter | Existing V2 adapter |
| --- | --- | --- |
| POST path | `/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId` | `/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId` |
| Currency field | `currencyCode` | `currency` |
| Category field | `accountCategory` | `accountCategory` |
| Bank field | `bankName`, required by this conservative V1 adapter | `bankName`, optional as before |
| Provider account type field | Omitted; rejected if included in a V1 identity | `accountType`, unchanged |
| Local rail | V1 snapshot `funding_rail`; projected to existing local `account_type` | Existing payload `accountType` |
| Selection | Explicit `apiVersion: 'v1'` service argument | Default `apiVersion: 'v2'` |
| Endpoint configuration | Fixed reviewed V1 contract, independent of the legacy endpoint setting | Existing endpoint setting and exact-template validation retained |

The intended USD/JPM_SG/self-funding/Wires dimensions are supported without any
account/customer/wallet identifiers in code. An authorized isolated test selects:

```php
$service->assign(
    $account, 'USD', 'SELF_FUNDING_ACCOUNT', 'WIRES',
    bankName: 'JPM_SG', operationId: $explicitOperationId, apiVersion: 'v1',
);
```

Its canonical JSON body contains exactly the supplied Su example's fields and
values (object key order has no semantic meaning):

```json
{"accountCategory":"SELF_FUNDING_ACCOUNT","bankName":"JPM_SG","currencyCode":"USD"}
```

Wires is frozen local intent, not an extra V1 provider field or independent proof
of which rail Nium provisioned. This change does not establish V1 bank/rail support
outside the supplied example. No GREENTECH activation/configuration is introduced.
V1 is never built by replacing the URL on a V2 request.

## Reuse of the Safety Ledger

Both adapters enter the same prepare/consume/dispatch/evidence-resolution engine.
Preparation freezes API version, HTTPS origin, exact endpoint, target identifiers,
canonical effective payload and payload hash, plus the existing durable provider
`x-request-id`. Existing V2 snapshots retain their previous shape and hashes.
V1 additionally freezes the rail outside its wire payload; changing rail also
conflicts with the operation identity. A key bound to one version cannot be
replayed through the other version, including prepared and consumed operations.

The Wallet/Currency scope deliberately excludes API version so changing versions
cannot bypass unresolved scope ownership. Eligibility/KYB checks and account-first
lock order remain in place. Authorization commits at top level before the one
existing POST dispatch site. Existing keys return state and never dispatch again;
there is no new assignment engine, retry, queue or recovery POST.

The same encrypted exchange evidence must match the frozen version's path, origin,
body, UUID and hashes. HTTP 200 plus a syntactically usable `uniquePaymentId`, no
errors and consistent explicitly present dimensions can resolve and attach a VA
atomically. V1's dimension adapter reads `currencyCode`; V2 still reads `currency`.
Present currency aliases, category, bank and target IDs must agree exactly; an
explicit response `accountType` must agree with V1 local rail intent. This check
does not imply V1 accepts that field in a request.

The existing case-insensitive `INITIALIZED` sentinel becomes pending in dispatch
handling, stores no Payment ID, attaches no VA and holds scope. The direct ledger
resolver conservatively leaves such evidence unknown. Missing/malformed IDs,
contradictory dimensions, undocumented delayed shapes, 202, all non-200 results,
408/409/429/5xx, timeouts and evidence failures never establish an assignment or
release scope through the dispatch path. V1 direct 400 evidence cannot borrow the
Phase 1 V2 validation-rejection rule. No additional delayed response semantics are
inferred from the request-only example; further authoritative V1 response variants
need technical-lead/provider review.

## H2 boundary — still a Production blocker

The existing local uniqueness constraint on
`(user_provider_account_id, provider_payment_id)` is preserved. No global unique
constraint, ownership registry, migration or historical rewrite was added.

Detected matching IDs in another VA or operation are quarantined as manual review:
no ID projection, VA attachment, terminal evidence or scope release. Existing
owners are not adopted or rewritten. This now also applies to direct ledger
assignment resolution when VA attachment was not requested.

PostgreSQL ledger resolvers serialize checks for an exact identifier with a
transaction advisory lock, then query ownership using fresh READ COMMITTED
snapshots. The lock remains through assignment/VA attachment and commit, so a
participating waiter observes the committed winner and stays unresolved. A hash
collision only causes extra serialization. Other PostgreSQL isolation levels fail
closed before terminal resolution to avoid stale-snapshot claims. The service
then retains unknown state/scope; direct callers receive an exception and retain
their pre-resolution state/scope.

This is conservative collision quarantine, not proof of globally safe ownership:

- Nium's uniqueness domain (wallet/customer/client/provider environment) remains
  unknown. Identical strings across unrelated legitimate domains may quarantine
  unnecessarily; the check cannot establish authoritative ownership equivalence.
- Existing rows lack reliable frozen origin/client ownership metadata. Changed or
  aliased wallet mappings and historical identities still need reviewed mapping.
- Legacy, webhook, automatic-provider and raw database writers do not participate
  in this advisory-lock protocol. They can still race it. No global guarantee is
  made for those writers or for untested database engines.
- When two participating direct resolutions collide, one can already be terminal;
  the later detected conflict stays unresolved. This does not establish that the
  earlier account is the provider-authoritative owner.

Authoritative Nium documentation and a reviewed historical ownership mapping and
all-writer enforcement design are required before H2 can be closed. Passing local
race tests does not resolve H2 or authorize Production rollout.

## Validation

Final results: **414 SQLite/unit/regression tests, 3,152 assertions passed**,
including **51 V1 adapter tests, 477 assertions**. The separate PostgreSQL suite
passed **34 tests, 429 assertions**. Pint passed for all nine PHP files changed
by this task. `git diff --check` and the task-only patch reverse-apply/whitespace
check passed. Tests use
isolated SQLite databases or a disposable PostgreSQL 18 Unix-socket cluster under
`/tmp/nium-va-ledger-pg.*`, with TCP disabled. No Production connection is used.
The disposable PostgreSQL cluster was stopped after its suite completed.

The new V1 suite covers the required exact body/no V2 fields, unchanged V2 body,
version mismatch both ways, immutable rail and targets, repeated/prepared/consumed
keys, valid assignment, INITIALIZED and ambiguous delays, malformed/missing IDs,
contradictory currency/bank/category/rail/target evidence, HTTP 408/409/429/5xx,
timeout, persistence failure, detected cross-account VA/operation conflicts,
sequential intentional USD assignments, and disabled/testing-only gate interlocks.

The PostgreSQL suite adds V1 same-key/different-key dispatch races, mixed-version
Wallet/Currency locking, V1/V1, V1/V2 and V2/V2 cross-account resolution conflicts,
conflict handling without VA attachment, an observed blocked advisory lock followed
by visibility of the committed winner, and REPEATABLE READ rejection. Existing
crash, commit uncertainty, revocation and immutable-evidence checks remain covered.
The broader selection covers legacy disabled-gate behavior, transport/evidence,
configuration, VA API safety, webhook, transfer and corporate RFI regressions.

## Remaining Phase 3 work (not begun)

Provider-authoritative H2 ownership-domain confirmation and reviewed enforcement
are prerequisites. Phase 3 still needs reviewed operation-specific GET/webhook
reconciliation for pending/unknown/manual-review results; authoritative evidence
and manual resolution rules; UI/operation-state and key-lifecycle handling; and
legacy-writer/webhook lock-order compatibility. Cutover requires inventory and
quarantine of ambiguous legacy operations, old-worker fencing/drain, automatic
allocation review, durable-history/failover procedures, and separately approved
rollout controls. No existing operation is automatically enrolled or migrated.
The current gate remains disabled by default, the allowlist remains empty, and
actual dispatch is still restricted to isolated testing with the worker fence.

## Git/review boundary

`main` was retained. The inherited dirty working tree was preserved. Nothing was
staged. The ordinary tracked diff alone omits the untracked Phase 1/2 files;
review must include those files. A task-only patch against a saved start-of-task
snapshot is supplied at `/tmp/nium-v1-review.patch`, including new files.

The SQLite/unit/regression command was:

```sh
vendor/bin/phpunit \
  tests/Feature/NiumVirtualAccountV1AdapterTest.php \
  tests/Feature/NiumVirtualAccountSingleDispatchTest.php \
  tests/Feature/NiumVirtualAccountSafetyLedgerTest.php \
  tests/Feature/NiumVirtualAccountSafetyLedgerIsolationTest.php \
  tests/Feature/NiumVirtualAccountSafetyLedgerMigrationTest.php \
  tests/Feature/NiumVirtualAccountSafetyAuditTest.php \
  tests/Feature/NiumVirtualAccountEligibilityProjectionTest.php \
  tests/Feature/NiumPaymentIdServiceTest.php \
  tests/Unit/NiumPaymentIdValidatorTest.php \
  tests/Feature/ProviderHttpClientTest.php \
  tests/Feature/NiumApiExchangeEvidenceTest.php \
  tests/Feature/NiumProviderConfigurationTest.php \
  tests/Feature/NiumSmokeTestCommandTest.php \
  tests/Feature/NiumWebhookApiTest.php \
  tests/Feature/NiumTransferServiceTest.php \
  tests/Feature/NiumCorporateRfiSubmissionTest.php
```

The PostgreSQL invocation used `NIUM_VA_LEDGER_PG_TESTS=1`, `DB_CONNECTION=pgsql`,
`DB_HOST=/tmp/nium-va-ledger-pg.ecIXcq`, `DB_PORT=55439`,
`DB_DATABASE=nium_va_ledger_test_hardened`, `DB_USERNAME=dtd1201`, empty test
password/DB_URL, and:

```sh
vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php
```

Earlier development runs found a reused synthetic identifier in two existing PG
tests and a new assertion attempting consumption after scope release. The final
PG suite uses a fresh disposable database with corrected fixtures/assertions and
passes; no failing check remains.

Final status (`git status --short`; all listed modifications are unstaged):

```text
 M app/Console/Commands/AssignNiumPaymentId.php
 M app/Http/Controllers/Api/User/ProviderAccountController.php
 M app/Models/NiumApiExchangeEvidence.php
 M app/Services/Integrations/ProviderHttpClient.php
 M app/Services/Nium/NiumApiExchangeEvidenceStore.php
 M app/Services/Nium/NiumPaymentIdService.php
 M config/services.php
?? app/Models/NiumVirtualAccountAssignmentScope.php
?? app/Models/NiumVirtualAccountDispatchAttempt.php
?? app/Models/NiumVirtualAccountRequest.php
?? app/Services/Nium/NiumPaymentIdValidator.php
?? app/Services/Nium/NiumVirtualAccountAssignmentContract.php
?? app/Services/Nium/NiumVirtualAccountAssignmentResponse.php
?? app/Services/Nium/NiumVirtualAccountAssignmentService.php
?? app/Services/Nium/NiumVirtualAccountOperationState.php
?? app/Services/Nium/NiumVirtualAccountRequestIdentity.php
?? app/Services/Nium/NiumVirtualAccountSafetyLedger.php
?? database/migrations/2026_10_27_000001_create_nium_va_safety_ledger.php
?? database/migrations/2026_10_27_000002_support_nium_va_single_dispatch.php
?? docs/nium-va-safety-ledger-phase1.md
?? docs/nium-va-single-dispatch-phase2.md
?? docs/nium-va-v1-adapter-review.md
?? "tatus --short"
?? tests/Concerns/CreatesNiumVirtualAccountLedgerFixtures.php
?? tests/Feature/NiumVirtualAccountSafetyLedgerIsolationTest.php
?? tests/Feature/NiumVirtualAccountSafetyLedgerMigrationTest.php
?? tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php
?? tests/Feature/NiumVirtualAccountSafetyLedgerTest.php
?? tests/Feature/NiumVirtualAccountSingleDispatchTest.php
?? tests/Feature/NiumVirtualAccountV1AdapterTest.php
?? tests/Unit/NiumPaymentIdValidatorTest.php

```
