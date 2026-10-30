**NIUM SGD Local payout — Phase 2 implementation and review report**

Date: 2026-10-30, Asia/Bangkok. Starting commits: backend `5a737b5`, frontend `fc4a074`.

The verifiable Phase 2 work is implemented and tested. **SG/SGD/LOCAL remains closed for submission and real FX-lock acquisition.** No executable LOCAL remittance payload was added because the exact registered-beneficiary SG request remains unresolved across NIUM's public contracts. The hold in `NiumSgdLocalPayoutGate::assertSubmissionAllowed()` is unchanged. NIUM representatives' confirmation is accepted as evidence of the proposed customer's corridor entitlement, not as evidence of undocumented request fields or pricing.

This report continues `docs/nium-sg-sgd-local-payout-safety.md`. It supersedes that report's descriptions of response-alias support and customer presentation. The unfinished bidirectional-pricing investigation and pre-existing quote-test edits were preserved.

**Contract evidence and the eight inspection questions**

The original OpenAPI excerpts remain in `tests/Fixtures/nium/sg-sgd-local-contract-evidence.json`. Additional public documentation was obtained through documentation-only GET requests. Exact excerpts, source URLs, retrieval dates and hashes of the full downloaded documents are in `tests/Fixtures/nium/sg-sgd-local-phase2-evidence.json`. These are public specifications and illustrative examples, not Production account responses.

| Question | Authoritative evidence and implementation decision |
| --- | --- |
| Exact registered-beneficiary SG Remittance | [Transfer Money](https://docs.nium.com/docs/payouts/transfer-money) explicitly documents `beneficiary.id` for a pre-created beneficiary. The [OpenAPI](https://docs.nium.com/oas/nium.yaml) `RemittanceTransactionsRequestDTO` requires beneficiary, payout, purpose and source of funds; its LOCAL example uses `sourceAmount` and `sourceCurrency`. However, [Singapore Payments](https://docs.nium.com/docs/payouts/country-and-regional-guides/sgd-payments-to-singapore) lists `payout_method`, `destination_currency`, `destination_amount`, `routing_code_type_1` and `remit_purpose_code` as required, without mapping these to the registered-beneficiary object. It states minimum 1 SGD and two decimal places. The precise source-driven registered SG request and applicable minimum therefore remain unverified. No guessed payload was wired into submission. |
| Include or derive method/currency | OpenAPI `Beneficiary.id` is documented as depending on destination currency and payout method; generic registered requests reference the stored beneficiary. The `Payout` schema does not declare `payoutMethod` or `destinationCurrency`, while the Singapore guide requires their snake-case counterparts without a precise object path. This distinction must be resolved using the customer's SG payout schema or explicit NIUM clarification. Existing HK/USD/SWIFT fields were preserved. |
| `swiftFeeType` on LOCAL | The generic schema describes it as optional SWIFT charge allocation, with SHA as the SWIFT default. The LOCAL example omits it, and the [Fees guide](https://docs.nium.com/docs/fees-and-limits/fees) distinguishes `REMIT_BANK_FEE` for LOCAL from SWIFT fee types. There is no verified requirement to send SHA on LOCAL, nor an explicit statement that the field is prohibited. No LOCAL payload is submitted; LOCAL UI and client metadata continue to omit SHA. |
| USD source, legacy audit and SGD result | OpenAPI `Payout.sourceAmount` is a remittance principal in `sourceCurrency`; the wallet must cover amount and fees. The lock operation expressly says to pass its audit ID as payout `auditId`/`audit_id`. A rate-only lock is not a final-recipient quote. The parser accepts a provider conversion amount only when the response explicitly includes matching source amount and source/destination currencies. It never derives that amount by multiplying the rate. |
| Banking and routing | The Singapore guide confirms bank-account LOCAL payouts use SWIFT BIC and select FAST/MEPS+/GIRO automatically. It calls the routing token `SWIFT CODE`; supplied Production Supported Corridors evidence calls it `SWIFT`. The existing beneficiary validation-schema integration remains authoritative for the exact token and field set. The candidate gate still requires SG/SGD Individual, authoritative LOCAL method, owned/active/synced beneficiary, valid BIC, HK Corporate sender and the exact Supported Corridors match. No public account-verification example was promoted into an SGD beneficiary-creation schema. |
| Legacy validity/expiry | OpenAPI says legacy audit IDs apply only to the same client/customer/wallet and expire at the returned hold expiry. The referenced response schema uses `audit_id`, `fx_rate`, `hold_expiry_at`; the endpoint example uses `auditId`, `exchangeRate`, `expiresAt`. Both are now parsed explicitly, alongside the pre-existing camel-case aliases, with conflicting values rejected. No live USD/SGD legacy response was fetched and the pair-specific legacy compatibility remains a release blocker. |
| Fees and wallet debit versus credit | The Fees guide explicitly says payout fees and FX margins can be debited alongside the transaction amount, and pricing depends on client/customer plans and conditions. Its example USD/SGD flat price is illustrative, not this customer's price. The effective legacy `fx_rate` already accounts for the documented markup; no additional margin or fee was invented. Total wallet debit, NIUM fee and final beneficiary credit remain unavailable without their own authoritative evidence. |
| Genuine CUG purpose | [Purpose Codes](https://docs.nium.com/docs/payouts/transfer-money/purpose-codes) documents meanings such as own-account transfer, family maintenance and goods trade; none is a universal CUG-test code. [Payout Validation Schema](https://docs.nium.com/docs/payouts/transfer-money/payout-validation-schema) states that accepted purposes may depend on remitter/beneficiary account types. The existing global purpose-code API is retained, without choosing or defaulting a test purpose. The actual CUG business facts and SG Corporate-to-Individual purpose restriction are still needed. |

The newly identified authoritative discovery endpoint is:

```text
GET /api/v1/client/{clientHashId}/customer/{customerHashId}/currency/SGD/payout/schema?payoutMethod=LOCAL&destinationCountry=SG
```

It is a payout schema, separate from the existing beneficiary validation-schema endpoint. Only NIUM's public guide and its PHP example were inspected; the actual SG response was not obtained. The PHP example's conditional rules and codes were not reused for SG. No customer/provider account endpoint was called during this work.

**Completed backend work**

`NiumPayoutFxLockContract` validates the authoritative legacy response without performing HTTP or creating records. It requires an integer payout audit ID, a positive rate that fits stored fractional precision, an absolute ISO timestamp with timezone, a valid calendar date and future expiry. Present currency aliases and any response source amount must match. Equivalent aliases are accepted; conflicting audit, rate or expiry aliases fail closed. Modern quote IDs are never substituted.

The adapter uses this parser and records the exact client/customer/wallet/base-URL context captured before the request. Its context is resolved from the matching active, clear, reconciled and identifier-verified provider account. The context and canonical response are stored in existing private quote metadata; there is no migration. Source amounts now remain decimal strings through lock preparation. Final comparison uses canonical decimal strings, catching differences that float conversion could erase.

The transfer service locks and validates the current provider-account identifiers before final FX validation. New locks must match the persisted context, source amount, currencies, rate, audit and expiry. SGD locks require response/context provenance; unbound historical SGD quotes are rejected. Existing non-SGD legacy fixtures without the new metadata retain their previous validation path to preserve compatibility, not to approve an additional corridor. Expiry is rounded down to the existing datetime column's seconds precision, never extended.

The existing legacy adapter retains its historical calculated `FxQuote.target_amount` for compatibility with the pre-existing transport. It is not authoritative payout pricing. No SGD lock can reach that persistence path while the gate is closed. The new SGD customer projection never consumes that calculation or the historical zero-fee placeholder.

The server now reports `payout_admission` for NIUM SGD beneficiaries, with the beneficiary/provider/corridor binding, `allowed=false` and `reason=contract_verification_pending`. This is a report of the hold, not proof of eligibility. Enabling the global FX flag cannot make it true. It performs no schema lookup or FX request.

For USD/SGD customer transfer responses, `payout_amounts` separates the following values:

| Value | Source and current behavior |
| --- | --- |
| USD source amount | Saved source principal; not labeled as total debit. |
| USD total wallet debit | `null`; no applicable fee-plan/pricing evidence. |
| FX rate | Only from an unexpired authoritative payout lock whose full response and current account binding validate; otherwise `null`. |
| SGD conversion amount | Only the explicitly returned provider amount with response amount/currency binding; otherwise `null`. No client or server multiplication supplies it to this projection. |
| NIUM transfer fee | `null`; database `fee_amount=0` is not evidence. |
| Expected final SGD beneficiary credit | `null`; conversion proceeds do not establish final bank credit. |

The SGD resource's older `target_amount`, `fee_amount` and `fee_currency` fields also return `null` to prevent consumers from interpreting placeholders as authoritative values. Its top-level rate uses the validated projection. HK/USD resource shapes and payload fields remain unchanged. Provider-context identifiers and raw quote responses are not exposed in the customer projection.

Approval, ledger reserve/release/settlement, client-reference idempotency, submission-state locking, unknown-outcome handling and provider reconciliation were inspected. Their state machines and accounting amounts were not changed. Existing unknown outcomes cannot acquire replacement locks or repeat the Remittance POST. Fee-aware funds sufficiency/reservation still needs review before admitting an SGD route whose fees debit the wallet alongside principal.

**Completed frontend work**

SG Individual LOCAL selection requires an explicit backend admission record matching the beneficiary, provider, USD source and SG/SGD/LOCAL destination. Missing, denied, mismatched or raw client metadata cannot enable selection. The current server always denies admission. The positive admission test specifies a future capability contract; it does not open a backend route or authorize a payment. Payment-purpose validation still requires the fetched supported list and an explicit supported choice.

Amount entry, review, summary, result and list share `SgdLocalAmountBreakdown`, which labels all six values separately. Backend amount evidence must match the displayed principal and currency pair. It does not consume generic `Transfer.target_amount`, `fee_amount` or `fx_rate` as proof. Unknown fees and total debit stay unavailable, and an explicit conversion amount is not labeled as final credit. No quote/lock request or money-movement action was added to the frontend.

The transfer list additionally explains that an unknown submission outcome must not be resubmitted and requires support reconciliation. Existing NIUM submit controls remain hidden and original approval/retry behavior is preserved. HK/USD/SWIFT eligibility and presentation remain covered by passing tests.

**Changed files in this pass**

Backend paths relative to `api_origin_wallet`:

- `app/Services/Nium/NiumPayoutFxLockContract.php` — new parser, exact decimal and lock-binding validation.
- `app/Services/Nium/NiumPayoutFxLockService.php` — parser integration, captured account context, decimal source amounts.
- `app/Services/Nium/NiumTransferService.php` — final binding validation and exact amount/rate comparisons.
- `app/Services/Nium/NiumSgdLocalPayoutGate.php` — explicit denied admission projection; submission hold unchanged.
- `app/Services/Nium/NiumSgdLocalPayoutAmounts.php` — new authoritative amount projection.
- `app/Http/Resources/User/BeneficiaryResource.php`, `app/Http/Resources/User/TransferResource.php` — admission/amount response fields and unavailable SGD placeholders.
- `tests/Feature/NiumPayoutFxLockContractTest.php`, `tests/Feature/NiumSgdLocalPayoutPresentationTest.php` — new contract/projection tests.
- `tests/Feature/NiumPayoutFxSafetyTest.php`, `tests/Feature/NiumTransferServiceTest.php` — conflicting-response and changed-wallet/no-POST regressions.
- `tests/Fixtures/nium/sg-sgd-local-phase2-evidence.json` — public contract evidence and provenance.
- `docs/nium-sg-sgd-local-phase2.md` — this report.

Frontend paths relative to `origin-wallet-platform`:

- `src/services/moneyMovementService.ts` — typed admission and payout-amount responses.
- `src/pages/account/transferForm.ts` — backend-bound SG admission checks.
- `src/pages/account/SgdLocalAmountBreakdown.tsx` — new common six-value display.
- `src/pages/account/AccountTransfers.tsx` — amount/review/summary/result/list integration and unknown-outcome explanation.
- `src/pages/account/SgdLocalAmountBreakdown.test.tsx`, `src/pages/account/transferForm.test.ts`, `src/pages/account/AccountTransfers.payeeList.test.tsx` — amount/provenance/admission/status regressions.

Pre-existing modifications in `tests/Feature/NiumQuoteServiceTest.php` were verified byte-for-byte against the initial diff. The untracked bidirectional-pricing report was not edited. No dependencies, migrations, Production environment or runtime configuration changed.

**Validation results**

All provider tests use mocked responses and SQLite in memory. New pure contract/presentation tests assert no HTTP was sent. The broader run also uses the existing temporary `/tmp/nium-sg-offline-bootstrap.php` stray-request guard. Persisted SGD quotes in projection tests are isolated database fixtures, not calls to the lock service or NIUM.

| Check | Result |
| --- | --- |
| Focused backend contract/projection/payout safety suite | 139 tests passed before adding the final subsecond-expiry regression; that additional case passes in the final broader run below. |
| Broader backend payout/beneficiary/purpose/KYC/security/idempotency suite | 344 tests, 2100 assertions: 343 pass, one previously documented failure in `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources`, line 167. It expects nested beneficiary `raw_data` to be absent. That field's behavior was not changed. |
| Frontend complete suite | 22 files, 254 tests passed. |
| ESLint | Pass, 22 existing warnings and no errors. |
| TypeScript | The same six pre-existing diagnostics remain: two KYC evidence-property errors, two RFI `replaceAll`/ES2020 errors, and two payload index-signature errors in the unchanged request calls inside `moneyMovementService.ts` (their line numbers shifted after interface additions). No new diagnostics were introduced. |
| Production build/prerender | Pass, with existing Browserslist freshness and bundle-size warnings. |
| PHP syntax and formatting | Changed/new production files and new/safety test files pass. `NiumTransferServiceTest.php` retains existing formatting failures, reproduced on its committed baseline; surrounding tests were not reformatted. Its new changed-wallet test also passes independently. |
| Whitespace and evidence JSON | `git diff --check` passes in both repositories; evidence JSON parses. |

Commands included the Phase 1 broader backend suite plus `NiumPayoutFxLockContractTest.php` and `NiumSgdLocalPayoutPresentationTest.php`, `npm run test`, `npm run lint`, `npx --no-install tsc --noEmit -p tsconfig.app.json`, `npm run build`, and targeted `php vendor/bin/pint --test`. Logs are `/tmp/nium-phase2-backend-validation.log`, `/tmp/nium-phase2-frontend-*.log` and `/tmp/nium-phase2-pint-*.log`.

The regression suite covers HK successful payloads, SG capability/ownership/readiness/method/currency restrictions, unsupported purpose, missing/disabled/failing/expired FX, malformed/conflicting audits and rates, exact amount mismatch, client/customer/wallet/environment binding, duplicate submission, unknown outcomes, hold preservation/release, insufficient funds, missing approval and no unintended Remittance POST. Actual successful SG LOCAL payload/POST coverage remains deferred with its unresolved contract; mocks were not used to disguise that gap.

**Remaining blockers, Production assessment and controlled next steps**

SG/SGD/LOCAL is **not ready for Production submission**. These safe components are reviewable, but actual LOCAL payload generation, admitted customer selection and real legacy USD/SGD lock acquisition remain deliberately unavailable.

1. Obtain the exact SG payout schema for the correct HK Corporate customer, plus its beneficiary schema/provider record. Resolve registered versus inline field paths, `SWIFT` versus `SWIFT CODE`, source-driven versus destination-required amount semantics, minimum SGD amount, precision and accepted purposes. A read-only schema response or explicit NIUM contract clarification can resolve this without creating a beneficiary or payment.
2. Obtain explicit compatibility evidence and a sanitized expected legacy USD/SGD response for this payout flow, including the response shape and account binding. The generic operation supports legacy audit IDs, but a conversational approval to convert does not identify the exact legacy API contract. Do not test this by acquiring a Production lock during this task.
3. Confirm the customer's applicable fee plan, payer, fee currencies, allocation and authoritative total debit/credit evidence. Review balance sufficiency and ledger reservation against the confirmed model. Do not apply the public guide's sample price or treat a zero placeholder as a configured zero fee.
4. Confirm a truthful CUG purpose from actual business facts and the applicable SG Corporate-to-Individual schema. Do not default to own-account, family-maintenance or goods-trade purposes just because they appear in the global list.
5. Implement and review the resolved LOCAL payload and its successful/failure mocks behind explicit corridor admission. Only a later reviewed release can replace the immutable hold and make the server emit `allowed=true`. Global FX enablement alone must not admit unverified corridors. Further provider-assisted verification and any real-money test require separate reviewer authorization.

No configuration change is required now. Keep `NIUM_PAYOUT_FX_ENABLED` disabled and the hard hold intact. No live FX lock, Remittance POST, beneficiary/payment creation, database change outside isolated tests, manual balance/ledger adjustment, commit, push or deployment was performed.
