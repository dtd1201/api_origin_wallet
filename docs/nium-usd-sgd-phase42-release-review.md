# NIUM USD to SGD LOCAL — Phase 4.2 release review

Date: 2026-10-30. **Production recommendation: NO-GO.** The reviewed preparation is implemented locally; executable SGD admission remains unconditionally denied. The historical FX hold must never be used for remittance. This report updates the earlier Phase 3 report for the Phase 4.2 delta only.

## 1. Isolated candidates and preservation

| Candidate | Exact path | Unchanged HEAD/base |
| --- | --- | --- |
| Backend | `/home/dtd1201/projects/api_origin_wallet_sgd_phase3` | `30e54607613e6fcd4cbe5eba93521288834888b1` |
| Frontend | `/home/dtd1201/projects/origin-wallet-platform_sgd_phase3` | `1711f82be42349f8ee672a07a9eac9ac17c181ac` |

Both existing detached Phase 3 worktrees were reused. Original checkouts, unrelated files, unfinished changes and other worktrees were preserved. Before editing, all 28 existing backend and seven frontend changed/untracked files were copied to `/tmp/origin-wallet-phase42-before`. No commit, push, deployment, environment/configuration change, provider API request or money movement occurred. No schema migration was added and no Production or persistent database was migrated. Tests created their isolated schema in in-memory SQLite with mocked provider transport; builds were local.

## 2. Completed implementation

- SGD parsing now requires an explicit `ACTIVE` response, both matching currency echoes, a valid integer-compatible positive legacy audit, a positive decimal-string rate and future absolute expiry. Conflicting aliases, modern quote IDs, expired holds and binary-float SGD rates fail closed. Non-SGD legacy compatibility is retained.
- The supplied `1.275939120` rate remains exactly that decimal string in the authoritative stored contract and customer presentation. Database comparisons normalize decimal strings without binary float arithmetic. The existing stored estimate now uses the installed Brick Math decimal implementation; it is not presented as a provider-confirmed conversion or final credit.
- Version 2 SGD contracts require explicit status/currency validation. Old, incomplete SGD snapshots cannot become executable locks. User/provider/client/customer/wallet/environment, source principal, rate and expiry remain bound through the verified provider account.
- Added a separate USD 5 registered LOCAL review payload builder. It reuses the strong Phase 3 corporate/individual, ownership, registration-evidence, routing and Supported Corridors checks. It uses the selected beneficiary's external string ID and an integer audit, with no destination amount, SWIFT charge type, forced SWIFT method, undocumented currency/method paths or invented invoice.
- `NiumTransferPolicy::providerPayload` now selects the separate SGD entry point. That entry point immediately invokes the immutable submission gate. Payload review does not grant admission. The existing HK/USD/SWIFT payload remains separate.
- SGD approval now requires a current attached lock and records a server-owned snapshot binding beneficiary, external beneficiary ID, user/provider/account context, principal, currencies, purpose, quote, exact rate and expiry. Missing/expired locks reject approval before an approval record is written. Repeating an approval after expiry cannot refresh the hold. Changes to approved payment fields require a new explicit review.
- Added a transaction-only hold-consumption operation, called after payload validation and before marking an SGD transfer as submitting. It locks matching audit rows, rejects a consumed audit or one attached to another transfer, and durably binds consumption to the transfer and operation identity. A consumed hold is never made available again after an uncertain outcome. The immutable corridor gate currently prevents the runtime SGD submission path from reaching consumption or transport.
- Existing request evidence capture remains in the encrypted `NiumApiExchangeEvidence` store, with hidden raw fields, hashes and existing sensitive-data projection. A regression verifies encrypted lock-response evidence and that it is absent from model serialization. No raw response or credential was added to customer-facing data.
- Listing, resource projection and the amount display do not acquire holds. The frontend preserves the full rate text on repeat rendering, expires displayed locks and continues to distinguish unknown fees from an explicit backend-confirmed zero.

## 3. Contract evidence and its limits

The Phase 4.2 user supplied the authorized Production observation for request UUID `52fe81dc-4581-4dc0-b2b4-81a21908b3b4`, HTTP 200:

| Provider field | Supplied observed type/value | Candidate handling |
| --- | --- | --- |
| `audit_id` | JSON integer, actual value withheld | Positive integer-compatible identifier; serialized as integer `payout.auditId` |
| `fx_rate` | String `1.275939120` | Preserved exactly; no authoritative float conversion |
| `source_currency` | String `USD` | Required and matched |
| `destination_currency` | String `SGD` | Required and matched |
| `hold_expiry_at` | String `2026-10-30T07:25:03.157Z` | Historical and expired; storage rounds down to seconds, never extends validity |
| `status` | String `ACTIVE` at observation | Explicit ACTIVE required, alongside future expiry |
| `fx_hold_id` | Present; actual value withheld | Existing secure provider evidence retains the complete returned response |
| `markup_rate`, `ecb_fx_rate`, `additionalFxMarkup` | Returned keys; values/types not supplied | No inference about fees or payout economics |

The supplied NIUM confirmation establishes use of legacy `auditId`, `sourceAmount=5`, conversion to SGD, and omission of `destinationAmount` for this source-funded registered path. These facts supersede the corresponding earlier source-only/FX-shape blockers. The exact additional LOCAL fields and fee requirements are expressly not confirmed by that statement.

Provenance is recorded in `tests/Fixtures/nium/usd-sgd-phase42-reported-fx.json`. It is a user attestation, not a new raw Production capture. Neither real hold identifier is stored in this fixture. Tests insert synthetic identifiers and freeze time before the historical expiry to test response shape, then after expiry to prove rejection. Synthetic values are not Production verification.

Public references already available locally:

- [NIUM OpenAPI](https://docs.nium.com/oas/nium.yaml): `RemittanceTransactionsRequestDTO`, `Beneficiary`, `Payout`, `FxHoldLockResponseContent`, and the target legacy Remittance / lockExchangeRate operations. Saved snapshot SHA-256: `57d3ce33c089206684ca7458982a4446278cf004d68438fc96c87e5af30fea17`.
- [Transfer Money](https://docs.nium.com/docs/payouts/transfer-money): registered beneficiary reference through `beneficiary.id`; generic LOCAL example.
- [Singapore SGD guide](https://docs.nium.com/docs/payouts/country-and-regional-guides/sgd-payments-to-singapore): LOCAL bank routing and corridor field requirements.
- [Payout validation schema](https://docs.nium.com/docs/payouts/transfer-money/payout-validation-schema): customer/corridor-specific constraints and purposes.
- [Fees](https://docs.nium.com/docs/fees-and-limits/fees): applicable LOCAL pricing must be established; FX markup is not remittance fee evidence.

No documentation or provider data was fetched during this implementation. The public root beneficiary definition still inconsistently combines `type: string` with object alternatives, and public `Payout` does not declare `destinationCurrency` or `payoutMethod`. Their necessity and exact inheritance remain unresolved for this customer-specific request.

## 4. Exact candidate request

The review builder produces the following structure. **This JSON is a synthetic illustration, not a sendable Production request:** the external ID and purpose are placeholders, and `4242001` is a synthetic test audit unrelated to the historical Production audit.

```json
{
  "beneficiary": {
    "id": "<selected beneficiary NIUM external ID>"
  },
  "payout": {
    "sourceAmount": 5,
    "sourceCurrency": "USD",
    "auditId": 4242001
  },
  "purposeCode": "<truthful supported purpose selected by the authorized user>",
  "sourceOfFunds": "Corporate Account"
}
```

Runtime values come from the authenticated transfer relationships and its fresh validated server-side FX quote. `beneficiary.id` is a string; `sourceAmount` is the exact whole-number CUG principal; `auditId` is an integer, never a modern quote string. No local beneficiary ID is placed in provider JSON. No specific Production payment purpose is selected by this work.

The request remains incomplete for release until NIUM settles any additional LOCAL requirements. `forReview` only verifies local candidate construction and available eligibility evidence; it does not assert provider acceptance. The runtime `providerPayload` refuses admission before transport regardless of the global FX flag.

## 5. Approval, reservations and idempotency

Existing maker/checker authorization, transfer row locking, durable operation identity, balance checks, reservation, settlement/release and reconciliation state machines are retained. SGD's additional approval snapshot is hidden in server-owned transfer data; client metadata cannot supply it. No automatic approval-time hold acquisition or silent replacement of expired holds was added.

Existing HK/legacy transport regressions verify missing approval, insufficient balance, duplicate submit, timeout after acceptance, persistence failure, retained reservations for unknown outcomes, and reconciliation. Unknown outcomes remain `submission_unknown`; there is no automatic Remittance POST retry. SGD tests verify denial even after a valid local approval and with FX enabled. The new consumption tests are isolated state-transition tests, not evidence of a live SGD submission or Production database concurrency behavior.

The present reserve calculation covers the existing principal model. It must not be used for SGD execution until fee currency, payer, debit allocation and all-in reserve requirements are authoritative. No speculative buffer or fee-aware reservation model was invented.

## 6. Fees

Unknown provider fees remain unavailable in customer resources. Existing non-null numeric storage columns still contain compatibility placeholders; new lock metadata expressly marks provider fee status as unavailable. Those columns, zero markup, an FX rate, or a calculated destination estimate never establish a zero payout fee.

The frontend already distinguishes an explicit backend-confirmed zero from missing fee evidence and retains that behavior. No provider fee-response parser was invented: mapping an actual fee response and its provenance remains blocked on the fee contract. Total wallet debit and final beneficiary credit remain unavailable.

## 7. Validation

| Check | Result |
| --- | --- |
| Final backend payout / beneficiary / security / reconciliation / idempotency selection | **318 tests, 1,618 assertions, all pass** |
| Initial full backend suite | 2,446 tests; **28 errors, 91 failures**, 67 skipped, one risky test; not a green suite |
| Reproduction of every failing/error/risky class on preserved pre-Phase-4.2 candidate | 529 tests; same 28 errors, 91 failures and risky test; problem-label comparison has no introduced labels |
| Complete frontend Vitest suite | **23 files, 272 tests pass** |
| ESLint | Pass, zero errors; 22 existing warnings |
| Cumulative Pint across all changed/new PHP files | Pass |
| PHP syntax across all 23 changed/new PHP files | Pass |
| Backend Vite production build | Pass |
| Frontend production build and prerender | Pass; existing bundle-size/Browserslist warnings |
| Diff whitespace checks | Pass in both candidates |

The full suite began before one final approval-expiry regression was added; that final test is included in the passing 318-test selection. No source changes followed the final passing regression selection, only this report and review artifacts.

Full-suite failures include existing KYC fixture/schema mismatches, serialization assumptions, unavailable other-provider routes and provider fixtures. They were reproduced without the Phase 4.2 changes; no broad repair was performed. Test logs and problem-label comparison are in `/tmp/origin-wallet-phase42-validation`. The isolated pre-change comparison is `/tmp/origin-wallet-phase42-prechange-backend`, reconstructed from the candidate base plus the preserved pre-change files, with local dependencies copied and no Production environment file.

## 8. Files changed in Phase 4.2

Backend paths are relative to `/home/dtd1201/projects/api_origin_wallet_sgd_phase3`:

```text
app/Services/Nium/NiumPayoutFxLockContract.php
app/Services/Nium/NiumPayoutFxLockService.php
app/Services/Nium/NiumSgdLocalPayoutAuthorization.php                 (new)
app/Services/Nium/NiumSgdLocalPayoutGate.php
app/Services/Nium/NiumSgdLocalRemittancePayload.php                   (new)
app/Services/Nium/NiumTransferPolicy.php
app/Services/Nium/NiumTransferService.php
app/Services/Wallet/TransferApprovalService.php
tests/Feature/NiumPayoutFxLockContractTest.php
tests/Feature/NiumPayoutFxLockServiceTest.php
tests/Feature/NiumSgdLocalPayoutGateTest.php
tests/Feature/NiumSgdLocalPayoutPresentationTest.php
tests/Fixtures/nium/usd-sgd-phase42-reported-fx.json                   (new)
docs/nium-usd-sgd-phase42-release-review.md                           (new)
```

Some files above were already untracked Phase 3 work; “new” denotes files first introduced in Phase 4.2. Frontend paths relative to `/home/dtd1201/projects/origin-wallet-platform_sgd_phase3`:

```text
src/pages/account/SgdLocalAmountBreakdown.tsx
src/pages/account/SgdLocalAmountBreakdown.test.tsx
```

Review manifests and patches distinguish this Phase 4.2 delta from the cumulative candidate difference against Production. They are in `/home/dtd1201/projects/origin-wallet-sgd-phase42-review` and include untracked source files.

## 9. Remaining release blockers and recommendation

1. **Additional registered LOCAL fields:** obtain the applicable customer payout schema or NIUM's complete accepted request. Establish inheritance/placement of destination country/currency, LOCAL method, BIC routing and any remitter fields. Source-only USD 5 is now confirmed by the supplied NIUM statement; that does not approve every omitted field.
2. **Fees and funding:** obtain authoritative fee amount/currency, payer, source-versus-destination charging, rounding, total USD wallet debit and final SGD credit semantics. Implement and review the resulting reserve/sufficiency model. Unknown charges cannot be treated as zero.
3. **Purpose:** obtain applicable Corporate-to-Individual SG LOCAL values and a truthful selection by the authorized user. A global purpose list or synthetic test code does not establish CUG eligibility.
4. **Fresh lock workflow and provider evidence:** separately authorize and implement/review the explicit lock-and-approval lifecycle after the contract blockers are resolved. The existing SGD acquisition gate remains closed. Review the actual secure capture and account binding for the historical observation if required; do not reconstruct an authentic response from this attestation or reuse its expired audit.
5. **Production admission and reconciliation review:** verify Andy's existing registration/status and exact account binding using permitted authoritative evidence, and establish accepted/rejected/unknown response and reconciliation behavior for the final SGD request. Review operation identity, hold consumption and database concurrency against the deployment database before execution. None was live-verified here.
6. **Repository-wide validation:** track the reproduced full-suite failures separately; the complete backend suite is not green. The relevant payment regressions pass, but that is not a complete release sign-off.

**NO-GO for Production release or a USD 5 payout.** Keep immutable submission admission and SGD FX acquisition blocked. An environment-variable change cannot activate this candidate. Technical review of the local preparation can proceed; deployment, fresh FX Lock and the first single Remittance POST each require a later explicit authorized release/execution process.
