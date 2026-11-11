# SG/SGD/LOCAL beneficiary-create diagnosis

Baseline: backend `5a29ee7e57220f4b1f149f60e2f2c56a0e96fa27`; frontend `1711f82`.
Investigation date: 2026-10-30. All NIUM interactions in this investigation were mocked tests. No NIUM API calls, real beneficiary writes, Production DB changes, commits, pushes or deployments were performed.

## 1. Root cause and confidence

**Confirmed code defect: a failed customer beneficiary create rolls back its provider request audit and encrypted exchange evidence.** Six regression cases first reproduced the deployed behavior before the runtime changes below (6 tests, 40 assertions). A mocked POST was sent, both audit records existed inside the transaction, and neither record remained after the controller handled the exception. A direct service call without an enclosing rollback retained the HTTP error records.

**Unresolved: the particular NIUM validation rule or field that rejected the two Production attempts.** The supplied generic message, timestamps (2026-10-30 04:38:46 and 04:39:03 UTC), user ID 12, schema success/counts and absent POST rows do not reveal the actual HTTP status, endpoint, submitted values or NIUM error details. The reproduction proves an explanation consistent with the evidence; it does not independently prove the exact external exchange for either historical attempt.

No missing or invalid beneficiary field is asserted. No payload, regex adapter, requirements normalizer or preflight-validation correction is justified by the evidence currently available.

## 2. Responsible deployed code path

1. `app/Http/Controllers/Api/User/BeneficiaryController.php::store()` creates the pending beneficiary and invokes the manager **inside `DB::transaction()`**. Its catch and `providerFailureResponse()` run after rollback.
2. `app/Services/Integrations/ProviderBeneficiaryManager.php::createBeneficiary()` delegates through `ProviderRegistry` to the NIUM beneficiary provider.
3. `app/Services/Nium/NiumBeneficiaryService.php::createBeneficiary()` obtains the supported corridor and trusted requirements. `createValidatedBeneficiary()` checks readiness/schema, constructs the flat beneficiary payload, runs preflight validation, optionally verifies the account, then submits the beneficiary POST.
4. `app/Services/Nium/NiumService.php::post()` delegates to `ProviderHttpClient::post()` / `send()`. The ordinary NIUM transport returns HTTP error responses; it does not call `throw()` on HTTP 4xx/5xx.
5. `app/Services/Integrations/ProviderHttpClient.php::logRequest()` routes NIUM responses, including errors, to `logNiumRequest()`. This inserts `ApiRequestLog` and calls `NiumApiExchangeEvidenceStore::persist()` inside another default-connection `DB::transaction()`.
6. `NiumBeneficiaryService::handleWriteResponse()` rejects unsuccessful HTTP responses **or a create response missing `beneficiaryHashId`/`id`, including HTTP 2xx**. It updates failure metadata, then, in deployed `5a29ee7`, throws `RuntimeException($responseData['message'] ?? ...)`.
7. The outer controller transaction rolls back the beneficiary, failure metadata, API request log and encrypted exchange evidence. The controller subsequently logs the exception message and returns its generic customer-safe HTTP 422.

There is a second relevant branch: `verifyAccountIfRequested()` can POST to the separately configured account-verification endpoint before the beneficiary POST. Its unsuccessful response also throws the provider's top-level message as a RuntimeException in the deployed version. Its audit is rolled back too. Whether this branch ran for user 12 is unknown. Its default entitlement flag is false; the actual request flag and Production configuration were not supplied.

Preflight field failures throw Laravel `ValidationException`, which the controller rethrows for field-level validation responses. They are distinct from the observed provider-failure RuntimeException warning. Requirements lookup errors are also converted to a validation failure before submission. A connection failure or audit-persistence exception has a different code path/message; the supplied generic validation message does not establish either condition.

## 3. Why POST_COUNT can be zero

Both audit models use the default database connection. The inner transaction/savepoint does not commit independently of the enclosing create transaction. The evidence table has a unique foreign key to `api_request_logs`; encryption protects raw exchange contents but does not give the evidence independent transaction durability.

| Location | What the deployed code records | What remains after this rollback |
| --- | --- | --- |
| `api_request_logs` | Safe request/response projections, HTTP status, operation, request UUID, timing and transport outcome, including HTTP errors | No row for the rolled-back exchange |
| `nium_api_exchange_evidence` | Encrypted exact request/response bodies, filtered encrypted headers, hashes and byte lengths, linked to the request log | No row for the rolled-back exchange |
| Configured Laravel warning channel | Operation, user ID, optional local beneficiary ID, exception class and previously the exception's raw message | The post-rollback warning can remain |

The configured warning destination is controlled by `LOG_CHANNEL` / `LOG_STACK` in `config/logging.php`; the repository defaults to `storage/logs/laravel.log`, with daily/stderr alternatives. The actual Production sink was not inspected. This code path has no separate independently committed beneficiary exchange archive.

Consequently, POST_COUNT=0 is **not evidence of no outbound POST**, and querying `nium_api_exchange_evidence` alone cannot recover an exchange removed by the same rollback. A separately committed schema GET can still be present; a schema GET made inside the failed create transaction can also be rolled back. Cached requirements may avoid that GET entirely.

## 4. Payload and preflight comparison

Available authenticated Production evidence confirms schema GET HTTP 200, 24 normalized fields and 11 supported patterns. **The authenticated SG schema document, its 24 field definitions and the failed request bodies were not supplied or found in the inspected checkout/artifacts.** A field-by-field comparison with those actual attempts cannot be completed from counts alone. The checked-in SG test schema is explicitly synthetic; the authentic HK/USD/SWIFT fixture does not establish SG-specific requirements.

The local downloaded NIUM OpenAPI artifact `/tmp/nium-official-openapi.yaml` and [NIUM beneficiary documentation](https://docs.nium.com/docs/payouts/beneficiaries) support the following structural checks against the deployed builder:

| Concern | Deployed behavior and available contract evidence |
| --- | --- |
| Add Beneficiary V2 | Configured default endpoint is `/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries`; payload uses flat camel-case beneficiary fields, unlike the separate account-verification request. Actual Production endpoint overrides were not inspected. |
| Account type | Corporate customer is a trusted corridor selection fact; the Individual beneficiary is sent as `beneficiaryAccountType: Individual`, matching the documented beneficiary enum casing. |
| Destination/method | SG, SGD and `payoutMethod: LOCAL` are built from the selected beneficiary/corridor. LOCAL and SWIFT are independently represented. |
| Routing | `routingCodeType1: SWIFT` and `routingCodeValue1` are flat routing fields; SWIFT routing does not change `payoutMethod` to SWIFT. The supplied Supported Corridors evidence permits this pairing. |
| Common required fields | Builder supplies beneficiary name, account type, beneficiary country, destination currency and payout method; the common documented shape shows no demonstrated omission. Actual values/conditional requirements remain unverified. |
| Account/contact/address/extra fields | Account number falls back to IBAN. Optional contact/address/bank/identification fields are filtered when empty. Additional fields must belong to the trusted schema and cannot override reserved or existing payload fields. Presence, format or entitlement problems cannot be established without the actual schema and error. |
| Legal entity/customer type | `clientLegalEntity: HK` selects schema branches. `customerType` is injected from trusted dimensions for preflight comparison; it is not an outgoing beneficiary field. The available Add Beneficiary contract does not establish that omitting customerType is a defect. |

`NiumBeneficiaryPreflightValidator::validate()` checks trusted schema/corridor identity, dimensions, allowed routing types and required routing pairs, required fields, primitive types, enums, Unicode lengths and supported patterns. Passing these checks does not establish provider acceptance of bank data or business rules. The supplied successful schema normalization provides no reason to change the regex adapter. HK/USD/SWIFT coverage remains in the passing beneficiary suite.

The deployed frontend builds the corresponding API fields and `raw_data.nium.additionalFields`; no frontend change is warranted by this diagnosis. No frontend files were edited and no frontend checks were rerun for this backend-only patch.

## 5. Changed files and validation

Isolated worktree: `/home/dtd1201/projects/api_origin_wallet_beneficiary_diagnostics`

Branch: `fix/nium-beneficiary-create-diagnostics`, based directly on `5a29ee7`. Changes remain unstaged and uncommitted.

| File | Change |
| --- | --- |
| `app/Services/Nium/NiumBeneficiaryWriteException.php` (new) | RuntimeException subclass holding a fixed message and safe structured diagnostics: stage, HTTP status, response presence, failure category, UUID correlation, error count and selected existing error projections. Raw bodies/messages, returned identifiers and credentials are not retained. |
| `app/Services/Nium/NiumBeneficiaryService.php` | Reuses existing `postWithRequestId()` for create and optional verification, so the post-rollback diagnostic knows the exact outbound UUID. Labels account verification separately. Throws the typed exception on rejected responses; the shared update response handler inherits safe diagnostic handling. |
| `app/Http/Controllers/Api/User/BeneficiaryController.php` | Logs typed safe diagnostics after rollback and removes arbitrary exception-message logging from the shared failure warning. The generic customer response remains HTTP 422. |
| `tests/Feature/NiumSgdBeneficiaryWorkflowTest.php` | Adds eight mocked cases: HTTP 400/422/500, 2xx missing ID, direct-service audit retention, optional verification rejection, hostile sensitive error content, and non-JSON rejection. |
| `docs/nium-sgd-beneficiary-create-diagnosis.md` (new) | This report and review commands. |

Runtime dependencies are existing Laravel HTTP/UUID/logging facilities, `NiumService::postWithRequestId()` and `NiumSafeValueProjector`. No new package, environment variable or migration is needed. Payloads, validation, retry policy, transaction boundaries, provider transport, FX/Payout code and the payout submission gate are unchanged.

Validation results:

- Pre-change reproduction on deployed runtime code: **6 tests, 40 assertions passed** (`/tmp/nium-beneficiary-diagnostics-reproduction.log`).
- Final relevant suite: **272 tests, 2,208 assertions passed**, including beneficiary workflow/lifecycle/service/requirements/schema/migration/reconciliation, HTTP auditing, encrypted exchange evidence, safe projection/state sanitization and unchanged transfer/FX-lock regressions (`/tmp/nium-beneficiary-diagnostics-regression-guarded.log`). The final run replaced disabled cURL execution functions with rejecting stubs and disabled stream networking; the mocked tests passed without network dispatch.
- All four changed PHP files pass `php -l`; targeted Pint and `git diff --check` pass.
- Two supplemental unrelated tests, `PingPongBeneficiaryServiceTest` and `TazapayBeneficiaryServiceTest`, miss their mocked endpoint because their endpoint config is unset in this checkout. Initial attempts stopped at DNS failure; a guarded repeat prohibited dispatch. The same two failures reproduce on an untouched archive of `5a29ee7` at `/tmp/nium-beneficiary-diagnostics-baseline-ar0jor_g`, using the rejecting network guard. No fixes were made to those tests/providers.
- An intermediate attempt to disable cURL functions without replacement stubs also broke Guzzle initialization for mocked requests. Those failures were test-harness failures; the final guarded result above supersedes that run.

The final suite covered these existing test files in addition to the changed workflow test:

```text
tests/Feature/NiumBeneficiaryLifecycleTest.php
tests/Feature/NiumBeneficiaryServiceTest.php
tests/Feature/NiumBeneficiaryRequirementsServiceTest.php
tests/Feature/NiumBeneficiaryProductionSchemaTest.php
tests/Feature/ReconcileNiumBeneficiaryPayoutMethodsTest.php
tests/Feature/BeneficiaryPayoutMethodMigrationTest.php
tests/Feature/NiumApiExchangeEvidenceTest.php
tests/Feature/ProviderHttpClientTest.php
tests/Feature/NiumProviderStateSanitizationTest.php
tests/Unit/NiumSafeValueProjectorTest.php
tests/Feature/NiumTransferServiceTest.php
tests/Feature/NiumPayoutFxLockServiceTest.php
```

Preservation checks cover HEAD, status, staged-diff hash and all changed/untracked file-content hashes for both original repositories and all four pre-existing beneficiary hotfix/release worktrees. Snapshot: `/tmp/nium-beneficiary-diagnostics-preservation-before.json`. No original changes were copied into this candidate or altered.

## 6. Additional evidence and deployment decision

Needed to identify the actual rejection:

1. Existing NIUM-side trace or an independently retained response for either historical timestamp: endpoint, exact HTTP status, request correlation UUID, error codes and sanitized field paths/descriptions. Do not recreate a beneficiary to obtain it.
2. The actual authenticated SG/SGD/LOCAL schema, including conditional branches, and selected dimensions/schema fingerprint. Field definitions alone contain no beneficiary values.
3. A securely reviewed, redacted representation of the failed outgoing request: field names, presence, types and lengths, and whether `verify_before_create` was requested/enabled. Inspect sensitive values only in the authorized environment; do not put full accounts, personal data or credentials into ordinary logs or this report.

**Diagnostic hotfix:** technically reviewable with passing scoped regressions, preserving current failure/validation behavior. It improves evidence for subsequent authorized operations; it does not perform such an operation. Existing log retention/access controls must retain the warning for support correlation. Structured error arrays use the current strict projector; string errors and unrecognized fields are not converted into guessed provider details. Safe UUIDs can be used for NIUM support lookup.

**Corrective payload hotfix:** not established as safe or effective yet. The exact invalid field/rule remains unresolved. This patch neither restores historical audit rows nor independently commits future exchange rows; full durable audit redesign is outside this scoped diagnostic change. There is no basis to claim beneficiary creation is fixed or to retry either Production attempt automatically. No deployment was performed.

Optional user-executed commands after review (explicit paths stage only this candidate):

```bash
cd /home/dtd1201/projects/api_origin_wallet_beneficiary_diagnostics
git diff --check
git diff -- app/Http/Controllers/Api/User/BeneficiaryController.php app/Services/Nium/NiumBeneficiaryService.php tests/Feature/NiumSgdBeneficiaryWorkflowTest.php
cat app/Services/Nium/NiumBeneficiaryWriteException.php
git add -- app/Http/Controllers/Api/User/BeneficiaryController.php app/Services/Nium/NiumBeneficiaryService.php app/Services/Nium/NiumBeneficiaryWriteException.php tests/Feature/NiumSgdBeneficiaryWorkflowTest.php docs/nium-sgd-beneficiary-create-diagnosis.md
git diff --cached --check
git diff --cached --stat
git diff --cached
git commit -m "fix(nium): retain safe beneficiary failure diagnostics after rollback"
```
