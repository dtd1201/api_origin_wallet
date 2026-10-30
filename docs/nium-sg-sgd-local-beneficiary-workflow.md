# SG/SGD/LOCAL beneficiary workflow review

Date: 2026-10-30 (Asia/Bangkok). Starting commits: backend `5a737b5`, frontend `fc4a074`, including the earlier uncommitted SGD Phase 2 and bidirectional-pricing work.

The beneficiary method-selection, registered-edit protection, review and error-handling changes are implemented. **Authentic SG schema normalization and SG beneficiary creation are not fully verified.** The exact rejected SG regex is unavailable. Unsupported schemas still prevent saving; no provider validation was removed or relaxed. Real-money payout submission and its SGD hold were not changed in this task.

## Root causes and evidence

| Finding | Evidence and limit |
| --- | --- |
| A new SG/SGD selection could retain SWIFT | `AccountBeneficiaries.tsx` initialized `emptyForm.payoutMethod` to `SWIFT`; `applyCountryChange` changed only country, and the currency handler explicitly retained the method. `payoutMethodsFor()` always offered LOCAL and SWIFT. The requirements query ran immediately using these form values, without discovering supported methods first. This explains how the supplied `country_code=SG&currency=SGD&payout_method=SWIFT` request could be produced. |
| SWIFT routing does not authorize a SWIFT payout | The supplied Production evidence is SG/SGD/LOCAL, Individual beneficiary, Corporate customer, with routing type SWIFT. Existing backend corridor matching already compares payout method and routing type separately. The new discovery flow uses that matcher; the LOCAL payload continues to carry SWIFT/BIC routing independently. |
| Create versus Edit was not distinguishable from the supplied request | Both modes used the same requirements URL. `openCreate` initializes a new form; `openEdit` restores a selected record through `toForm`. The query and wildcard schema log contain no mode or beneficiary identity. They cannot establish which mode the Production customer used. Previously, the wizard allowed corridor changes during an edit, and the customer update controller had no registered-corridor immutability check. Both paths are now covered by tests. |
| Exact regex rejection remains unproven | The supplied HTTP 200 provider response was rejected locally with `unsupported_pattern` at `schema.properties.*`. That log does not contain the field, branch or pattern. The only authentic validation-schema fixture found is `tests/Fixtures/nium/beneficiary-hk-usd-swift-schema.sanitized.json`. Neither an authentic SG/SGD/LOCAL response nor the actual rejected pattern was available. The supplied log cannot by itself be correlated to a LOCAL request, particularly when the browser request uses SWIFT. |
| Requirements failures were indistinguishable | The requirements controller caught all service failures and returned the same 502 message. This included an unsupported corridor, although the frontend expected a 422 to explain unsupported selections. Structured codes now separate unsupported combinations from unavailable validation schemas and lookup failures. |
| Production CORS cause is unresolved | Laravel's default global middleware already includes `HandleCors`; `config/cors.php` covers `api/*` and uses explicit configured origins. Local HTTP tests verify 422 and 502 error headers and GET preflight for an allowed origin, and rejection of an unapproved origin. There is no supplied browser Origin, downstream status, response headers or proxy trace. Thus the browser symptom cannot be assigned to a proxy response, missing/mismatched headers, or another network error. No CORS configuration was changed. |

## Backend changes

`GET /api/user/users/{user}/beneficiaries/payout-methods` accepts provider, beneficiary legal type, country and currency. It requires authenticated ownership, an available NIUM beneficiary capability and eligible customer/wallet state. It discovers only LOCAL and SWIFT, using the existing exact Supported Corridors lookup for each method. Existing cache, client/environment binding, sender-type selection and matching rules are preserved. There are at most two corridor GETs on a cold cache, and no beneficiary/schema/payment write. A failed lookup does not admit an unverified method.

The response binds `payout_methods` to `provider_id`, `beneficiary_type`, `country_code` and `currency`. This is beneficiary method discovery, not payout submission admission.

Requirements/discovery error contracts:

| Status | Code | Meaning |
| --- | --- | --- |
| 422 | `beneficiary_corridor_unsupported` | No authoritative match for the selected combination. |
| 502 | `beneficiary_schema_unavailable` | NIUM validation requirements could not be obtained or safely normalized. |
| 502 | `beneficiary_requirements_unavailable` | Requirements/capability/account lookup could not complete. |
| 502 | `beneficiary_corridors_unavailable` | Method discovery could not complete. |

Normal request-validation and authentication errors retain their existing statuses. Failure responses contain application-defined codes/messages, not provider schemas, credentials, raw exceptions or provider messages.

The customer update controller locks and rereads the beneficiary before mutation. Registered NIUM records reject changes to provider, legal type, country, currency or effective payout method with validation errors before local mutation or provider writes. If neither the saved method nor matching successful provider-request evidence establishes the registered method, editing is blocked with a support message; client raw metadata cannot establish it. Changing provider is also rejected for an unregistered edit. Existing permitted update behavior and entitlement flags remain in place; editing does not silently open a replacement-create path for a registered beneficiary.

Customer beneficiary resources add `is_registered`, without exposing the external provider identifier. Earlier Phase 2 `payout_admission` data is preserved.

`NiumBeneficiarySchemaPattern`, the schema normalizer and the preflight validator were inspected and left unchanged. Required fields, enums, lengths, patterns, corridor-based conditional requirements, and routing constraints still run through the existing trusted-schema pipeline. Unsupported patterns and payload-dependent conditions remain fail-closed. No speculative SG regex translation was added.

## Frontend changes

The wizard discovers methods for the selected provider, legal type, country and currency before requesting validation requirements. Missing, loading, failed, malformed or mismatched discovery metadata blocks review and saving. Method choices are restricted to the backend response. New SG/SGD Individual forms prefer LOCAL when the server verifies it, including when both bank methods are supported. HK/USD retains its supported SWIFT default. A user's supported method choice is retained within the same corridor; changing country, currency or legal type clears routing values and resolves a fresh supported default.

Edits retain the saved method. Registered edits show an explanation and reject attempted corridor conversion while leaving the form values unchanged. Missing registration metadata from an older server is treated conservatively during an edit. A missing saved method is not guessed as SWIFT. The customer must explicitly choose Add beneficiary to create separate payment details.

Review renders the final submitted payload, including country, currency, LOCAL method code, account and typed routing values. LOCAL with SWIFT/BIC routing remains distinct. HK SWIFT review wording is preserved.

Unsupported corridor, provider-schema failure, corridor-discovery failure and network/browser-access failure have separate messages. A browser fetch failure cannot reliably distinguish CORS from a network outage, so the UI describes that limit instead of diagnosing CORS from status 0. Retry retains entered bank details and creates no beneficiary automatically.

## Files changed in this task

Backend:

- `app/Http/Controllers/Api/User/BeneficiaryController.php`
- `app/Http/Resources/User/BeneficiaryResource.php` (additive registration flag; prior Phase 2 work preserved)
- `app/Services/Nium/NiumBeneficiaryService.php`
- `app/Services/Nium/NiumSupportedCorridorService.php` (additive discovery and a `RuntimeException` subclass for an unmatched corridor; existing matching semantics unchanged)
- `app/Services/Nium/NiumUnsupportedBeneficiaryCorridor.php` (new)
- `routes/user.php`
- `tests/Feature/NiumSgdBeneficiaryWorkflowTest.php` (new)
- `tests/Feature/NiumBeneficiaryLifecycleTest.php` (two existing successful-edit fixtures now include the authoritative saved method required by the edit guard)
- `docs/nium-sg-sgd-local-beneficiary-workflow.md` (this report)

Frontend:

- `src/pages/account/AccountBeneficiaries.tsx`
- `src/pages/account/beneficiaryPayoutMethods.ts` (new)
- `src/pages/account/AccountBeneficiaries.sgd.test.tsx` (new)
- `src/pages/account/AccountBeneficiaries.corridor.test.tsx`
- `src/pages/account/AccountBeneficiaries.updateFailure.test.tsx`
- `src/services/moneyMovementService.ts` (additive registration/discovery types and GET; prior Phase 2 types preserved)

All earlier uncommitted files were checked against hashes captured at task start. Only the two shared resource/type files above changed, additively. The payout services, payout gate, earlier Phase 2 report/evidence/tests, quote-test changes and unfinished bidirectional-pricing report retain their starting bytes. No dependencies, migrations, config or environment files were changed.

## Validation

Provider responses were mocked, databases were SQLite in memory, and the backend run used the existing `/tmp/nium-sg-offline-bootstrap.php` stray-HTTP guard. New positive SG tests use an explicitly **synthetic** schema to verify plumbing; they are not presented as authentic NIUM SG schema coverage.

| Check | Result |
| --- | --- |
| Relevant backend beneficiary, corridor, lifecycle, schema, payout-safety and customer-security suites | 338 tests, 2116 assertions: 337 passed; one pre-existing failure, `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources` at line 167. It expects nested beneficiary `raw_data` to be absent. That existing field was not changed. |
| Full frontend suite | 23 files, 263 tests passed. |
| Regex cross-runtime check | PHP adapter and JavaScript `RegExp(pattern, 'u')` agree for all 13 structural pattern entries from the authentic HK fixture across 51 values: 663 comparisons. Inputs include positive/negative values, length boundaries, LF/CR, U+2028/U+2029, BOM, U+0085, accented text, emoji and full-width digits. This does not establish compatibility with the missing SG pattern. |
| PHP syntax and Pint | All eight changed/new PHP source/route/test files pass. |
| ESLint | Full lint passed with 22 existing warnings and no errors; the final changed-file check passed without diagnostics. |
| TypeScript | Same six pre-existing errors: KYC union-property access (two), RFI ES2020 `replaceAll` (two), and unchanged request-call payload index signatures in `moneyMovementService.ts` (two, now lines 243/270). No new diagnostics. |
| Production build/prerender | Passed, with existing Browserslist freshness and bundle-size warnings. |
| Diff preservation | `git diff --check` passes in both repositories; unrelated uncommitted file hashes preserved as described above. |

Backend coverage includes SG Individual eligibility, HK Corporate regression, LOCAL with SWIFT routing, unsupported method/type, failed discovery, required and conditional fields, pattern/length/routing enforcement, unsupported-schema holds, registered edit immutability, cross-customer protection, CORS error/preflight handling and no unintended HTTP writes. Existing payout regression tests cover the unchanged submission hold, approval, idempotency and unknown-outcome safeguards. Frontend tests exercise actual wizard selection, review, create versus edit, stale/mismatched metadata, loading, structured errors and safe retries.

Logs: `/tmp/nium-beneficiary-backend-validation.log`, `/tmp/nium-beneficiary-frontend-validation.log`, `/tmp/nium-beneficiary-pattern-parity.log`, `/tmp/nium-beneficiary-lint*.log`, `/tmp/nium-beneficiary-types.log`, `/tmp/nium-beneficiary-build.log`, `/tmp/nium-beneficiary-pint-final.log`.

## Remaining read-only evidence and readiness

1. Obtain an authentic, sanitized SG/SGD/LOCAL beneficiary validation-schema response for Corporate customer / Individual beneficiary and SWIFT routing. Preserve every pattern, escape, property, conditional, required list and length/enum rule; redact only nonvalidation annotations or identifiers. Include request dimensions, HTTP status and retrieval provenance. The existing configured NIUM beneficiary validation-schema endpoint is separate from the payout schema discussed in Phase 2.
2. Identify the exact rejected field/conditional path and regex from that response. Compare JavaScript Unicode semantics with PHP on representative valid, invalid, Unicode and boundary inputs before extending the adapter. Retain the hold on any semantics that cannot be reproduced safely.
3. Provide a correlated browser capture containing request URL/method, Origin, preflight and actual statuses, and response CORS/proxy headers. Exclude Authorization, cookies, credentials and personal account details. This distinguishes application 422/502 responses from proxy-generated errors or actual CORS configuration mismatches.
4. Confirm whether the original user action was Add or Edit. If Edit, inspect only the sanitized record's registration state and saved country/currency/payout method; do not convert or replace it automatically.

The method-selection and edit/error fixes are reviewable. **Full SG/SGD/LOCAL beneficiary-creation verification remains blocked on authentic SG schema evidence.** No regex fix or successful Production creation is claimed. No additional configuration change is proposed.

No live beneficiary creation/update, FX lock, Remittance POST, money movement, provider write, Production database change, manual ledger adjustment, config/`.env` change, commit, push or deployment occurred. All record creation and mocked writes were confined to isolated tests.
