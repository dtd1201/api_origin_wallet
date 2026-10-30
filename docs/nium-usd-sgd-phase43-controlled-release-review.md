# NIUM USD → SGD LOCAL: Phase 4.3 controlled release review

Date: 2026-10-30. **Release recommendation: NO-GO for Production and money movement.**

The UI and backend now implement controlled preparation, explicit fresh-lock acquisition, exact-rate review, safe recovery and guarded approval/submission controls. A real payout cannot proceed: authoritative charging terms, the complete registered LOCAL request and corridor-specific purpose eligibility remain unverified. The immutable execution gate remains closed. This is a reviewable preparation candidate, not a successfully enabled end-to-end payment integration.

## Scope and preservation

Only the existing isolated candidates were edited:

| Candidate | Path | Unchanged detached HEAD / Production base |
| --- | --- | --- |
| Backend | `/home/dtd1201/projects/api_origin_wallet_sgd_phase3` | `30e54607613e6fcd4cbe5eba93521288834888b1` |
| Frontend | `/home/dtd1201/projects/origin-wallet-platform_sgd_phase3` | `1711f82be42349f8ee672a07a9eac9ac17c181ac` |

Existing Phase 4.2 work was retained. Before Phase 4.3, 34 backend and seven frontend dirty/untracked files were copied into `/tmp/origin-wallet-phase43-before`. Review artifacts distinguish incremental Phase 4.3 changes from cumulative changes against Production, including untracked source. The original checkouts and other worktrees were not edited. No live NIUM call, FX Lock, Remittance POST, money movement, deployment, persistent database migration, environment/configuration-file change, commit or push occurred. Tests use in-memory SQLite and faked HTTP with stray requests prohibited. Production concurrency and provider acceptance were not verified by those tests.

Review package: `/home/dtd1201/projects/origin-wallet-sgd-phase43-review`. `phase43-files.json` is the exact incremental manifest; `cumulative-files.json` records all candidate differences from Production. Patches include new files. Prior reports remain historical and are superseded by this report where the workflow changed.

## Implemented UI flow and its limits

1. The backend exposes preparation eligibility only for user 12, NIUM provider 10, verified provider account 6, existing beneficiary 9 and USD 5 → SG/SGD/LOCAL. These IDs are isolated in `NiumSgdLocalCugScope`; provider requests use the dynamically stored external beneficiary ID. No new account or SGD wallet is created.
2. An eligible beneficiary becomes selectable with an explicit preparation-only notice. The dedicated screen shows the USD wallet and available balance after reservations, beneficiary/bank, LOCAL method, fixed USD 5 principal and NIUM purpose choices. The user must confirm the selected purpose is truthful. The current list is the global supported list; corridor eligibility is still a release blocker.
3. The explicit button saves an idempotent local draft and requests a fresh lock. Rendering, listing, timers and refresh never acquire a hold. The existing global `payout_fx_enabled` switch is still required and unchanged; while false, the fresh-lock button is disabled. No flag can enable Remittance submission.
4. A validated hold displays its exact decimal-string rate, expiry and a clearly labeled source × rate estimate. The synthetic regression rate `1.275939120` yields estimate `6.3796956` SGD for USD 5. This arithmetic is for display; NIUM performs conversion, rounding and payout. It is not guaranteed credit.
5. Expected CUG fee USD 0 is labeled a user expectation. Confirmed provider fee, all-in USD debit and final SGD credit remain unavailable. Any unexpected charge blocks the controls even if a response claims readiness.
6. Approval and one-time submit controls are wired to protected APIs, with status refresh and existing reconciliation. They remain disabled because the backend reports `approval_request_allowed=false` and `dispatch_allowed=false`. Admin resources also deny approval and avoid displaying storage zero as a confirmed fee. No approval record, reservation or successful payment is manufactured.
7. A saved draft can be resumed. Pending/unknown FX prevents another lock. Unknown payment status exposes reconciliation without resubmitting. Cancellation permits a replacement only when no unknown lock, consumed audit or provider-dispatch history remains.

Steps for actual Admin Approval and dispatch are intentionally unavailable pending the missing evidence and a reviewed execution authorization implementation. Future release must review the current automatic-submit-on-admin-approval behavior of the existing infrastructure; this candidate does not authorize a second customer POST after an admin action has already dispatched.

## Provider evidence and proposed request

No provider documentation or authenticated response was fetched during Phase 4.3. Existing evidence:

- Public [NIUM OpenAPI](https://docs.nium.com/oas/nium.yaml), saved SHA-256 `57d3ce33c089206684ca7458982a4446278cf004d68438fc96c87e5af30fea17`. Relevant definitions: `RemittanceTransactionsRequestDTO`, `Beneficiary`, `Payout`, `FxHoldLockResponseContent`; target legacy Remittance and `lockExchangeRate` operations. The review package contains `evidence/public-contract-extract.json`.
- [Transfer Money](https://docs.nium.com/docs/payouts/transfer-money) provides a generic registered LOCAL example; [Singapore SGD](https://docs.nium.com/docs/payouts/country-and-regional-guides/sgd-payments-to-singapore) and [payout validation schema](https://docs.nium.com/docs/payouts/transfer-money/payout-validation-schema) describe corridor requirements. These are not an accepted GREENTECH/Andy request.
- The Phase 4.2 user-attested NIUM confirmation establishes legacy auditId, sourceAmount=5 USD and omission of destinationAmount for this source-funded registered payout. The user-attested historical FX response is recorded in `tests/Fixtures/nium/usd-sgd-phase42-reported-fx.json`; actual audit/hold IDs are withheld. Its `2026-10-30T07:25:03.157Z` expiry is historical and must never be reused. This is an attestation, not a raw capture authenticated during this work.

Proposed wire body below is the exact current review-builder shape, **not a sendable or provider-approved request**. `4243001` is a synthetic test-only audit, and the strings in angle brackets are placeholders. Additional LOCAL fields remain unverified.

```json
{
  "beneficiary": {"id": "<stored NIUM external beneficiary ID>"},
  "payout": {"sourceAmount": 5, "sourceCurrency": "USD", "auditId": 4243001},
  "purposeCode": "<truthful eligible NIUM purpose selected by the user>",
  "sourceOfFunds": "Corporate Account"
}
```

| JSON location | Generic OpenAPI definition / type / requirement | Confirmed specifically for this registered SGD LOCAL request? |
| --- | --- | --- |
| `beneficiary.id` | `Beneficiary.properties.id`: required string within the registered-beneficiary object; root `beneficiary` required | Existing registration supplied; generic reference form documented. Exact complete customer request still requires confirmation. Root schema inconsistently combines string type and object alternatives. |
| `payout.sourceAmount` | `Payout.properties.sourceAmount`: number, at most 2 decimal places; source or destination amount mandatory by description | User supplies NIUM confirmation for source USD 5; implemented as exact integer 5. |
| `payout.sourceCurrency` | `Payout.properties.sourceCurrency`: string; not listed in nested `required`, described by endpoint as source wallet currency | USD funding confirmed by user; current request sends USD. |
| `payout.destinationAmount` | `Payout.properties.destinationAmount`: number, 2 decimal places; alternative amount by description | NIUM omission confirmed by user. Omitted. Public schema has no formal oneOf/not rule proving both amounts forbidden; source-only is the confirmed scenario. |
| `payout.destinationCurrency` | Not declared in public `Payout`; no defined type at this location | Not added. Confirm whether SGD is fully inherited from registered beneficiary, or identify exact required path. |
| `payout.payoutMethod` | Not declared in public `Payout`; no defined type at this location | Not added. Confirm whether LOCAL is fully inherited from registered beneficiary. |
| `payout.auditId` | `Payout.properties.auditId`: integer/int64; optional generically, required by this locked-rate application flow; alias audit_id documented | Legacy audit confirmed by user. Must come from a fresh bound hold. Never a modern quoteId. |
| `payout.quoteId` | `Payout.properties.quoteId`: optional string, documented mutually exclusive with auditId | Omitted. |
| `payout.swiftFeeType` | `Payout.properties.swiftFeeType`: optional string enum OUR/SHA; SWIFT charge semantics | Omitted for LOCAL. No invented SHA instruction. Existing USD/SWIFT branch retains its own behavior. |
| `purposeCode` | `RemittanceTransactionsRequestDTO.properties.purposeCode`: required string; descriptive default contradicts required list | Explicit selection mandatory. Applicable Corporate→Individual SG LOCAL values still unconfirmed. |
| `sourceOfFunds` | `RemittanceTransactionsRequestDTO.properties.sourceOfFunds`: required string enum including Corporate Account | Generic enum supports proposed value. Customer-specific applicability still needs complete request confirmation. |
| `customerComments` | `RemittanceTransactionsRequestDTO.properties.customerComments`: optional string, described max 512/no special characters | Omitted; no corridor-specific requirement established. |
| `externalId` | `RemittanceTransactionsRequestDTO.properties.externalId`: optional string, described 1–36 alphanumeric/hyphen characters with restrictions | Omitted. Do not confuse UI client reference or operation header with this field. |
| `payout.tradeOrderID` | `Payout.properties.tradeOrderID`: optional string for actual invoice number | Omitted; no fictitious invoice. |

All table definitions are under `https://docs.nium.com/oas/nium.yaml#/components/schemas/<definition>`. Declared optional fields above are not declared nullable; omission does not imply JSON null is supported. Public root `beneficiary`, `payout`, `purposeCode` and `sourceOfFunds` are required. Dynamic client/corridor requirements can add constraints not represented in this generic schema. The table is not evidence that an unknown property would be accepted or rejected by NIUM.

Legacy FX contract: GET `/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate` with required string query parameters `sourceCurrency=USD` and `destinationCurrency=SGD`; optional numeric `additionalFxMarkup` is omitted. A stable UUID is forwarded as `x-request-id`. No source amount is invented in this request. `FxHoldLockResponseContent` declares root `audit_id`, `fx_rate`, `source_currency`, `destination_currency`, `hold_expiry_at`, `status`, `fx_hold_id` as strings, without a schema required list. The reported actual USD/SGD response uses an integer audit_id and string rate. The adapter validates a positive int64-compatible legacy audit, exact positive string rate, matching USD/SGD echoes, explicit ACTIVE and future absolute expiry, while retaining encrypted provider evidence. Missing/conflicting fields reject. The hold binds to the same client/customer/wallet/environment and USD 5 intent; the historical rate never supplies a new executable hold.

`NiumTransferPolicy::providerPayload` retains the separate SGD review builder and immutable execution denial. Other payouts retain the existing HK/USD/SWIFT path. Tests verify the proposed LOCAL body has no destination amount, SWIFT fee field, forced SWIFT method or quoteId.

## Fees, debit and reservation rules

- User expectation: principal USD 5, expected CUG fee USD 0. This never becomes provider fee evidence.
- Preparation and fresh-lock requests require at least USD 5 available after current reservations. No balance reservation occurs for a preparation draft or blocked approval.
- Legacy non-null fee storage columns contain compatibility zero values; safe customer/admin projections expose provider fee, all-in debit and beneficiary credit as unavailable. They must not authorize a reserve.
- The workflow rejects approval before saving it; the manager denies controlled SGD dispatch before the existing principal-only reservation. Thus an unknown fee cannot enter settlement as zero.
- Once NIUM supplies authoritative charging terms, implementation must calculate and reserve the confirmed all-in debit atomically. For the expected zero-fee CUG, require authoritative zero USD charge and total USD debit exactly 5, with no undocumented deduction from SGD credit. Any nonzero, differently denominated or changed charge requires fresh user review; do not increase debit silently or invent a buffer.
- No authoritative fee parser or fee-aware SGD execution reserve is claimed complete. Both depend on NIUM's actual charging contract.

## FX, approval and dispatch protections

Draft identity binds maker, provider, beneficiary/external ID, verified customer/wallet/client/environment, principal, currencies, purpose and expected fee. A user-row lock serializes draft creation, including different client references; the same reference returns the same matching intent. Only one unresolved controlled operation is permitted.

An explicit FX request claims `locking` plus request UUID and timestamp durably under the transfer lock before provider I/O. HTTP occurs outside the application row-lock transaction. The hold-creating legacy GET explicitly disables redirects and automatic retries; a redirect is retained as an uncertain lock outcome, never followed. Completed same-UUID requests reuse their current result. A different UUID cannot replace a still-current hold; expired holds require a new explicit UUID on an unapproved draft. Pending/uncertain acquisition cannot repeat. Account/payment changes during I/O prevent quote attachment and mark the attempt uncertain; no hold is automatically released or reused.

Approval requires current intent, eligible scope, supported purpose, available principal and unexpired authentic bound audit before reaching the immutable block. Maker self-approval is refused. Existing approval snapshots bind purpose, external beneficiary, amount, exact rate/audit and expiry; dispatch revalidates them. Delayed admin approval cannot use an expired hold. There is no automatic refresh on approval or dispatch.

Existing operation identity, single-dispatch, duplicate prevention, audit consumption, request evidence capture, accepted/rejected handling, reservation retention for unknown POST outcomes and protected reconciliation remain in place. Never automatically retry an ambiguous Remittance POST. The current SGD gate prevents reaching POST even if status or approval records are forged. Test cases exercise these protections with synthetic transport; they do not demonstrate live SGD acceptance or real database parallelism.

## Validation

| Check | Result |
| --- | --- |
| Focused backend payment, beneficiary, FX, approval, security, duplicate and reconciliation selection | **341 tests, 1,861 assertions pass**, including 23 new preparation-workflow cases |
| Complete frontend suite | **24 files, 281 tests pass**, including nine new workflow cases |
| ESLint | Pass; zero errors, 22 existing warnings |
| Cumulative changed/new PHP Pint and syntax | Pass |
| Backend Vite production build | Pass |
| Frontend Vite production build/prerender | Pass; existing Browserslist/bundle-size warnings |
| Both candidate `git diff --check` | Pass |
| Rerun all 25 classes with established full-suite problems | 529 tests; same **28 errors, 91 failures, one risky test**; no introduced problem labels |

The earlier complete backend run recorded 2,446 tests, 28 errors, 91 failures, 67 skipped and one risky test. Phase 4.3 reran all problem classes and the relevant payment selection; it did not rerun the entire suite. The complete backend suite is not green. Comparison includes 119 error/failure labels plus the same risky label (120 total); none changed. Existing KYC/serialization/other-provider failures were not broadly repaired. Logs and comparison JSON are in the review package's `validation` directory. Frontend API/HTTP fixtures are explicitly synthetic, never evidence of Production payment success. No browser-driven or live end-to-end transfer was run.

## Exact Phase 4.3 files changed

Backend, relative to the backend candidate:

```text
app/Http/Controllers/Api/User/NiumSgdLocalPayoutController.php       (new)
app/Http/Resources/Admin/TransferListResource.php
app/Http/Resources/User/TransferResource.php
app/Services/Integrations/ProviderHttpClient.php
app/Services/Integrations/ProviderTransferManager.php
app/Services/Nium/NiumPayoutFxLockService.php
app/Services/Nium/NiumService.php
app/Services/Nium/NiumSgdLocalCugScope.php                          (new)
app/Services/Nium/NiumSgdLocalPayoutAmounts.php
app/Services/Nium/NiumSgdLocalPayoutAuthorization.php
app/Services/Nium/NiumSgdLocalPayoutGate.php
app/Services/Nium/NiumSgdLocalPayoutWorkflow.php                    (new)
app/Services/Wallet/TransferApprovalService.php
routes/user.php
tests/Feature/NiumSgdLocalPayoutWorkflowTest.php                    (new)
docs/nium-usd-sgd-phase43-controlled-release-review.md              (new)
```

Frontend, relative to the frontend candidate:

```text
src/pages/account/AccountTransfers.tsx
src/pages/account/SgdLocalAmountBreakdown.tsx
src/pages/account/SgdLocalPayoutWorkflow.tsx                        (new)
src/pages/account/SgdLocalPayoutWorkflow.test.tsx                   (new)
src/pages/account/transferForm.ts
src/services/moneyMovementService.ts
```

Other dirty/untracked candidate files predate Phase 4.3. The review package itself is additional local review output, not application code or deployed configuration.

## Remaining blockers and minimum NIUM confirmation

1. Obtain the complete request accepted for this client/customer, source USD wallet, registered individual beneficiary, SG/SGD/LOCAL and source principal 5. Specifically confirm beneficiary-based inheritance or exact paths/types for destination country/currency, LOCAL rail, BIC routing, sourceOfFunds and any mandatory remitter/supplementary fields. Resolve the root beneficiary schema inconsistency. Do not infer a complete contract from corridor support alone.
2. Obtain authoritative CUG fee amount/currency, payer, allocation to source debit versus beneficiary credit, rounding and final-credit semantics, including the response/schema and evidence used to validate them. User expectation, zero FX markup and an HTTP 200 FX response do not establish free remittance.
3. Obtain the applicable Corporate→Individual SG LOCAL purpose values; the maker must choose the truthful purpose. Do not silently select a generic purpose or inherit the USD/SWIFT default.
4. Implement and review the evidence-backed fee mapping, atomic all-in reservation and narrowly scoped production execution authorization. The present always-false approval/dispatch presentation and `assertSubmissionAllowed(): never` require reviewed code changes, not environment bypasses. Review exactly one dispatch owner in the admin approval path.
5. Verify accepted, rejected and uncertain response/reconciliation semantics for the final payload against authoritative NIUM evidence, and review concurrency against the target database. Reconcile the original authenticated historical capture/account binding if needed; never fabricate withheld identifiers. Obtain explicit release and execution authorization separately.
6. Track the pre-existing full-suite failures and obtain the technical lead's validation disposition. Passing focused tests alone is not release approval.

## Exact safe release sequence for technical-lead review

This is a future runbook; no step authorizes execution now.

1. Keep current execution gates closed. Review incremental and cumulative patches, evidence provenance and test logs. Resolve NIUM contract, fee and purpose blockers above.
2. Implement the confirmed supplementary fields/fees, reserve calculation and scoped authorization; add corresponding offline acceptance/rejection/unknown and approval-expiry regressions. Review Production-database row-lock behavior, account binding and one-dispatch ownership. Rerun the relevant checks and obtain a new GO decision.
3. Obtain separately authorized deployment/configuration approval and release the reviewed candidate through the normal process. No configuration flag alone may permit payout. Keep scope bound to user 12/provider 10/account 6/beneficiary 9, USD 5 and this one controlled exercise.
4. With authorized access, verify the existing GREENTECH USD wallet and Andy registration/status, available balance and unresolved prior operations using approved evidence/read-only checks. Do not create an account, wallet or beneficiary, and do not move funds between wallets.
5. Maker selects the USD wallet and Andy, enters exactly USD 5, selects a truthful corridor-eligible purpose, and reviews the authoritative zero-fee terms. Stop if fee terms/total debit differ from the reviewed expectation.
6. After explicit separate authorization for a live FX Lock, use the UI button once. Inspect the captured authentic legacy audit, ACTIVE state, exact USD/SGD pair/rate, client/customer/wallet binding and future expiry. Never copy the historical audit or the synthetic example. If lock outcome is uncertain, stop and reconcile; do not repeat blindly.
7. Maker reviews principal 5, exact locked rate, SGD estimate, confirmed fee, all-in debit, beneficiary and purpose, then submits for Admin Approval. Atomically reserve only the authoritative reviewed debit according to the finalized model.
8. A different authorized admin checks immutable intent, fee evidence, available/reserved funds and remaining lock validity. If expired, stop approval/dispatch and use a new explicit lock-and-review cycle; do not refresh silently. If an existing approval action dispatches automatically, it is the sole dispatch owner; never follow it with another submit action.
9. Under explicit approval for the controlled money movement, allow exactly one protected NIUM Remittance POST using the reviewed legacy audit and operation identity. Persist evidence and provider reference. Any ambiguous response remains unknown with reservation held; reconcile by reference/status without an automatic POST retry.
10. Confirm provider terminal status, USD debit, fees and actual SGD credit using authorized reconciliation and the receiving account. Release/settle reservations only according to the verified outcome. Stop further controlled attempts and retain the audit evidence for technical-lead review.

**Current decision: NO-GO. Stop after local implementation, verification and reporting.**
