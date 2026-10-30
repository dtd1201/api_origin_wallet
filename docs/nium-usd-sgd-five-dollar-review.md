# USD 5 to SGD LOCAL: implementation and execution review

Date: 2026-10-30, Asia/Bangkok. Supersedes the Phase 3 report's test/formatting results. The Phase 3 candidate is reused; no new application architecture or replacement worktree was introduced.

**Decision: the executable USD-to-SGD LOCAL payout is still incomplete and submission remains blocked.** NIUM's generic legacy lock/remittance contracts are published, but neither an authentic USD/SGD legacy response nor the customer's exact registered SG LOCAL request was established. The mandatory stop condition therefore applies. Passing mocked tests is not Production verification, and this report does not claim the USD 5 transfer can be executed with the current candidate.

**1. Completed implementation and validation work**

The existing candidates remain based on supplied Production commits `30e5460` (backend) and `1711f82` (frontend):

- `/home/dtd1201/projects/api_origin_wallet_sgd_phase3`
- `/home/dtd1201/projects/origin-wallet-platform_sgd_phase3`

The original main checkouts and their unrelated pricing/beneficiary changes remain untouched. Production's beneficiary controller, write service, safe exception diagnostics and user routes are preserved in the backend candidate.

| Requirement | Current implementation |
| --- | --- |
| USD funding | Existing USD wallet/account identifiers and USD source principal are reused. No Virtual Account, SGD wallet or wallet-to-wallet conversion was created or requested. The USD 85 balance is a supplied historical observation, not a fresh funds check. |
| Legacy FX | Reuses `NiumPayoutFxLockService`, `NiumPayoutAuditId` and `NiumPayoutFxLockContract`. Validates numeric legacy audit, positive rate, expiry, pair, amount and user/provider/client/customer/wallet/environment binding. Modern quote IDs cannot be substituted. Direct SGD acquisition remains blocked. |
| Delayed approval | An expired attached lock cannot be remitted or silently replaced. Existing regression verifies approval retention and reservation release on that rejection. A reviewed lifecycle is still needed if future SGD review must occur before automatic approval-triggered locking/submission. |
| Beneficiary | Existing candidate verifies owned, active, registered NIUM beneficiary; explicit provider registration evidence; SG/SGD/LOCAL Individual dimensions; corporate KYC/account readiness; BIC routing; Supported Corridors. IDs are dynamic; fixture IDs 9 and 37 are tested. No beneficiary was recreated or modified. |
| LOCAL remittance | **Blocked/incomplete.** No guessed payload, source/destination amount substitution, SWIFT fee field or inline remitter object was added to the provider POST path. |
| Fees | Unknown remains unavailable. UI handles an explicit backend-confirmed zero correctly; new display tests exercise zero and a nonzero fee. Those are presentation fixtures, not evidence of GREENTECH's pricing. Current backend SGD projection returns unknown fees until a verified pricing mapping exists. |
| Purpose | Existing supported-purpose fetch and explicit customer selection remain. No CUG purpose is defaulted or invented. The actual Corporate-to-Individual SG restrictions and truthful purpose remain unverified. |
| Security | Approval, reservation, idempotency, row locking, evidence capture, duplicate protection and unknown-outcome restrictions are retained. No ambiguous Remittance POST is automatically retried. |
| Frontend | SGD selection requires matching backend executable admission. Current backend always denies it. USD principal, rate/expiry, SGD conversion, fee/total-debit uncertainty, beneficiary, purpose and approval state are represented by the existing candidate. Submit stays hidden for NIUM, and expiry invalidates the displayed conversion. |

This continuation adds a synthetic **USD 5 / USD 85 / beneficiary 9** approval regression: even with the FX flag enabled, approving the local fixture reaches the immutable route hold, sends no lock/remittance/beneficiary request, retains approval, releases the temporary reservation, and leaves USD 85 available. It is not a provider acceptance test and does not reverify the actual account or beneficiary.

The previously failing customer-security assertion was corrected to verify Production's existing allowlisted `beneficiary.raw_data` envelope instead of requiring the envelope absent. All existing private-data sentinel assertions still run, and now pass. No production resource behavior or security check was relaxed. Pint formatting in the legacy transfer test file was also corrected; its test behavior was preserved.

**2. Authoritative NIUM evidence checked now**

Only public, credential-free documentation GETs were performed. No NIUM customer/account API, live lock, modern quote, conversion or remittance operation was called. Fresh public excerpts and provenance are stored in:

`tests/Fixtures/nium/sg-sgd-local-completion-public-evidence.json`

The [current OpenAPI](https://docs.nium.com/oas/nium.yaml) SHA-256 is `57d3ce33c089206684ca7458982a4446278cf004d68438fc96c87e5af30fea17`: exactly the specification reviewed in the previous phases. The guide sections were freshly read from [NIUM's public text documentation](https://docs.nium.com/llms-full.txt); the evidence records both the download hash and each section hash. Public examples remain illustrative.

| Source | Confirmed contract facts | Remaining distinction |
| --- | --- | --- |
| OpenAPI legacy `GET /api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate` | Queries `sourceCurrency` and `destinationCurrency`; returns an audit usable as `payout.auditId`/`audit_id` on the same client/customer/wallet until expiry. Referenced schema uses `audit_id`, `fx_rate`, `hold_expiry_at`, `source_currency`, `destination_currency`, `status`; optional hold/markup fields also exist. | Endpoint's camel-case example is **SGD→USD**, with amounts, while the query is rate-only and the referenced snake-case schema has no amounts. No actual USD→SGD response was obtained. Its illustrative rate is not a rate for this payment. |
| OpenAPI registered `Beneficiary` and `Payout` | `beneficiary.id` is the provider beneficiary identifier tied to its destination currency/method. `payout.sourceAmount` or `destinationAmount` is required; both are documented with at most 2 decimals. `sourceCurrency` and integer `auditId` are documented. | Generic LOCAL example supplies only source amount/currency, without a destination amount. It does not establish the source-driven SG variant for this customer. |
| [Transfer Money](https://docs.nium.com/docs/payouts/transfer-money) | Explicitly supports the stored `beneficiaryHashId` as `beneficiary.id` on the existing remittance endpoint. Generic source-of-funds enum contains `Corporate Account`; purpose must be selected. | Its registered example uses `destinationAmount`, and current guide's quote discussion emphasizes modern `quoteId`; this does not override the explicit legacy audit contract or authorize substituting IDs. |
| OpenAPI payout-schema endpoint | Its illustrative `unified.sgd.local.json` requires `payout.destinationAmount`, `beneficiary.destinationCountry`, `remitter.name`, `remitter.accountType`, `beneficiary.name`, `beneficiary.accountType`, `beneficiary.routingCodeType1`, `beneficiary.routingCodeValue1`. | It is an example response, not GREENTECH's schema. Whether these inline fields are resolved from an existing registered beneficiary/customer is not specified. Generic remittance documentation describes `remitter` as applicable to financial-institution on-behalf payments, so blindly adding this object for GREENTECH is unsafe. |
| [Singapore Payments](https://docs.nium.com/docs/payouts/country-and-regional-guides/sgd-payments-to-singapore) | LOCAL SGD bank payout uses BIC; guide lists `destination_amount` required, minimum SGD 1 and at most 2 decimals, plus `payout_method`, `destination_currency`, `routing_code_type_1=SWIFT CODE`, `remit_purpose_code`. It documents FAST/MEPS+/GIRO routing. | Exact mapping/derivation of these fields for the registered-beneficiary source-driven JSON is missing. Supplied Supported Corridors evidence uses routing token `SWIFT`. |
| [Fees](https://docs.nium.com/docs/fees-and-limits/fees) | Fee and FX margin depend on the applicable plan; LOCAL has `REMIT_BANK_FEE`. The remittance contract requires funds for amount and fees. | Neither the legacy lock schema nor `RemittanceResponseDTO` supplies an authoritative fee or all-in debit. Zero cannot be inferred. |
| [Purpose Codes](https://docs.nium.com/docs/payouts/transfer-money/purpose-codes) and [Payout Validation Schema](https://docs.nium.com/docs/payouts/transfer-money/payout-validation-schema) | Purpose codes have real business meanings and schema restrictions may depend on sender/beneficiary account types. | No universal CUG-test purpose exists in the evidence; global list membership is insufficient to establish this transaction's truthful eligible code. |

**3. Precise remaining fields and behaviors needed**

Provide NIUM's authentic existing sanitized USD→SGD legacy response or explicit applicable contract confirmation, without acquiring a live lock under this task. Preserve field names, types and meanings for:

- `audit_id`/`auditId`: positive numeric legacy identifier usable as integer `payout.auditId`, not a modern quote ID.
- `fx_rate`/`exchangeRate` (or supported `fxRate`): effective SGD per USD rate and markup semantics.
- `hold_expiry_at`/`expiresAt` (or supported `holdExpiryAt`): absolute expiry with timezone and any status/hold requirements.
- `source_currency`/`sourceCurrency=USD`, `destination_currency`/`destinationCurrency=SGD`, and proof that the lock is for the same NIUM client/customer/wallet.
- If amount fields are returned, their exact USD principal and SGD conversion meaning, precision and rounding. A rate-only lock does not itself give an authoritative SGD amount or guaranteed final credit.

Obtain the actual customer payout schema or a complete NIUM-confirmed registered request for:

```text
GET /api/v1/client/{clientHashId}/customer/{customerHashId}/currency/SGD/payout/schema?payoutMethod=LOCAL&destinationCountry=SG
```

The required clarifications are:

1. Is `beneficiary.id=<existing external beneficiary ID>` plus `payout.sourceAmount=5.00`, `payout.sourceCurrency=USD` and integer `payout.auditId` sufficient for this registered LOCAL recipient? Is `payout.destinationAmount` additionally required, or is source-driven SGD payout unsupported? Do not add both amounts without verified semantics.
2. Are destination currency and method inherited solely from the registered ID, or required in the request, and at exactly which paths? `payout.destinationCurrency` and `payout.payoutMethod` are not declared by the generic `Payout` schema; existing HK SWIFT usage does not prove LOCAL support for them.
3. Are any inline beneficiary/remitter/routing fields required despite registration? If so, identify their exact paths and accepted `SWIFT`/`SWIFT CODE` token. Do not duplicate/recreate Andy or invent remitter fields.
4. Confirm `purposeCode` mapping versus the guide's `remit_purpose_code`, the Corporate-to-Individual accepted values and the actual business purpose. Confirm any comments/reference constraints; do not invent an invoice to populate optional `tradeOrderID`.
5. Confirm omission of `swiftFeeType`, intermediary-bank fields and other SWIFT-only values. The generic LOCAL example omits them; no LOCAL SHA charge allocation is assumed.
6. Confirm the applicable provider fee amount/currency/payer, whether it is additional to USD 5 principal or deducted from proceeds, the authoritative total debit, precision/rounding and expected-credit semantics. Unknown may be displayed, but the ledger currently reserves principal only, so fee sufficiency/reservation needs a verified model before submission.
7. Confirm SGD lifecycle responses. Generic acceptance provides `systemReferenceNumber`/`system_reference_number` and optional `payment_id`; acceptance is pending, not final paid. Singapore documents `SENT_TO_BANK`, `PAID`, **`RETURN`**. Existing generic normalization recognizes `RETURNED`, while `RETURN` falls back to pending; returned-after-paid accounting/manual reconciliation must be reviewed for SGD before opening admission. No global HK status behavior was changed to guess this accounting contract.

Registration/corridor/CUG authorization facts supplied by the user are accepted. They do not resolve the missing executable field mapping. The old USD 85 observation does not confirm current spendable funds, applicable fees or an executable rate.

**4. Exact files changed**

Changes made in this continuation, relative to the Phase 3 candidate at the start of this request:

Backend:

- `tests/Feature/CustomerTransferSecurityTest.php` — correct the stale response-envelope assertion; retain private-data rejection checks.
- `tests/Feature/NiumSgdLocalPayoutGateTest.php` — USD 5 approval/hold-release/no-provider-POST regression using synthetic fixtures.
- `tests/Feature/NiumTransferServiceTest.php` — Pint formatting and equivalent imported-class references only.
- `tests/Fixtures/nium/sg-sgd-local-completion-public-evidence.json` — freshly retrieved public contract excerpts and source hashes; not customer verification.
- `docs/nium-usd-sgd-five-dollar-review.md` — this report and conditional execution sequence.

Frontend:

- `src/pages/account/SgdLocalAmountBreakdown.tsx` — move the React hooks import to the import block; preserve behavior.
- `src/pages/account/SgdLocalAmountBreakdown.test.tsx` — verify explicit zero/nonzero fee presentation without inferring total debit or final credit.

The cumulative candidate still includes the existing Phase 1/2/3 implementation described above. Complete exact path/hash lists against Production are `backend-manifest.json` and `frontend-manifest.json` in `/home/dtd1201/projects/origin-wallet-sgd-completion-review`; separate incremental manifests identify the seven files above. Review patches include untracked files. The previous Phase 3 review bundle is preserved.

**5. Tests and results**

| Check | Result |
| --- | --- |
| Backend payout, FX, beneficiary, purpose, security, reconciliation and idempotency selection | **295 tests pass; 1,553 assertions.** Runs with `tests/PayoutOfflineBootstrap.php`, in-memory SQLite and unfaked-Laravel-HTTP rejection. No live NIUM requests. |
| Frontend complete Vitest suite | **23 test files, 271 tests pass.** |
| PHP syntax | All **19** changed/new PHP files pass. |
| Pint | Pass on all changed/new PHP files, including the previously failing legacy transfer test. |
| Frontend ESLint | Pass: 0 errors, 22 existing warnings. No separate frontend formatter is configured. |
| Backend production Vite build | Pass. |
| Frontend production build and prerender | Pass; existing large-bundle/Browserslist warnings. |
| Whitespace checks | Pass in both candidates. |

This is a backend regression selection, not a full backend-suite claim. Exact commands and full output are retained in the new review bundle. Relevant coverage includes HK/USD/SWIFT SHA payload preservation, dynamic IDs, unsupported ownership/currency/method/registration, invalid/expired legacy audits and context, delayed approval, insufficient balance, idempotent creation, duplicate submission, unknown outcome hold retention and no automatic remittance retry. SGD assertions prove candidate checks and denial before provider money movement, not successful SGD execution.

**6. Production readiness**

The safe candidate is reviewable with green scoped checks. **It is not ready for a live SGD payout.** Backend admission remains denied and direct SGD lock acquisition remains blocked. Removing that hold alone would still leave a SWIFT-oriented payload policy and unverified SGD financial/status semantics; that is not an acceptable completion strategy.

No live NIUM account requests, FX locks, Remittance POSTs, wallet conversions, money movement, Virtual Account creation, beneficiary modifications, Production configuration/database changes, deployments, commits or pushes occurred. Public documentation GETs and mocked local tests are the only NIUM interactions in this continuation.

**7. Controlled sequence for the first USD 5 payment — after blockers are resolved**

This is the exact order required by the reviewed application flow. It is conditional; it is not executable authorization or a fabricated provider POST template.

1. Technical Lead obtains and reviews the missing legacy USD/SGD and registered LOCAL evidence above. Implement and review the resolved LOCAL payload, provider fee-aware reservation, SGD return/status behavior, route-specific purpose filtering, and rate/amount-review lifecycle in this candidate. Add successful/rejected/unknown SGD response mocks using clearly labelled fixtures. Keep HK/USD/SWIFT regression tests green. Only that reviewed code may make backend admission true for the controlled customer/corridor; do not globally open FX or remove general security gates.
2. Obtain separate authorization for the release, live USD/SGD lock and single real-money remittance. The present instruction prohibits all three execution actions. Do not infer authorization from NIUM CUG approval or this runbook.
3. In the authorized environment, perform fresh read-only verification: user **12**, active/clear/reconciled NIUM corporate account, current authenticated customer/wallet IDs, existing USD balance and reservations, and existing beneficiary **9** owned by user 12 with its registered external ID, SG/SGD/LOCAL Individual dimensions and active provider status. Verify the corridor and the allowed truthful purpose. Reuse these records; create no VA or beneficiary. Current funds must cover the verified all-in debit, not merely the last observed USD 85.
4. Treat **USD 5.00 as source principal**. If the requested USD 5 is instead an all-in debit cap, stop until authoritative fee-inclusive pricing supports that input. Do not assume SGD receipt, zero fees, USD 80 remaining or an arbitrary fee buffer.
5. Create one Origin Wallet request with a durable, unique `client_reference`, using customer API `POST /api/user/users/12/transfers`: resolved NIUM `provider_id`, `beneficiary_id=9`, `transfer_type=payout`, `source_currency=USD`, `target_currency=SGD`, `source_amount=5.00`, the confirmed truthful `purpose_code`, and an accurate reference. Omit client-supplied FX locks/rates and bank-account/VA instructions. Require returned status `approval_required`. Persist the returned local transfer ID and reuse the same client reference for any safe create recovery; never create a new reference to work around an uncertain existing payment. **The current gate rejects this request; do not execute it until step 1 is complete.**
6. Complete the verified quote/review process and obtain admin approval for those economics. Existing `POST /api/admin/transfers/{transferId}/approve` auto-submits NIUM transfers that were `approval_required`; treat this as the execution action, not a harmless preparatory click. The existing pipeline checks approval/balance, reserves funds, acquires/validates the server legacy lock, rechecks binding/expiry and constructs the provider request. The future reviewed SGD lifecycle must satisfy step 1 before this endpoint is authorized. If an attached lock has expired during approval, the current code rejects it, retains approval and releases its temporary hold; stop and use a separately reviewed fresh-lock/re-review process rather than silently replacing it or editing database fields.
7. Execute at most one provider Remittance POST using the verified LOCAL payload and authentic numeric audit, with current user/provider/beneficiary/amount/pair/rate/expiry binding and fee-aware reservation. Capture request/response evidence and operation identity. The customer frontend must not separately issue a second Submit after automatic admin execution.
8. A provider system reference indicates initiation, so track lifecycle to authoritative terminal status. A timeout, HTTP 408/429/5xx, missing authoritative reference or evidence-persistence failure must follow the existing unknown-outcome path: retain the reservation and do not repeat the POST. Use read-only provider audit/status reconciliation with the durable local/provider identities. Where normal sync is applicable, `POST /api/user/users/12/transfers/{transferId}/sync-status` triggers provider status GET; an unknown outcome without a system reference needs the operator reconciliation path, not a blind sync or resubmit.
9. Confirm authoritative `PAID`/final lifecycle, actual USD debit and fees, ledger settlement/release, and Andy's SGD receipt. Resolve `RETURN`/rejection through the reviewed SGD reconciliation/accounting process. Stop the CUG exercise if outcome, debit or receipt is uncertain; do not submit another transfer to test whether the first succeeded.

No exact NIUM LOCAL POST body is supplied here because its unresolved fields are the blocker. Providing one now would falsely turn public examples into a verified customer contract.
