# Bidirectional payout pricing inspection

Date: 2026-10-29. Scope: local source code, repository contracts and HTTP-faked fixtures in `api_origin_wallet` and `origin-wallet-platform`. No live NIUM requests or Production configuration inspection/changes were performed. This establishes the existing integration's behavior, not NIUM account entitlement or provider-wide API support.

## Decision

Stop application implementation at the requested verification boundary. The active frontend corridor is USD/USD, HK destination, SWIFT SHA. No verified contract in this integration prices that corridor's provider fees, fee-inclusive sender debit or destination-driven payout. Renaming the current transfer amount to **You send — total wallet deduction** would assert unverified financial semantics.

No wizard, transfer payload, submission flow, configuration, beneficiary, virtual account, wallet sync or KYC application code changed. Both editable fields, recalculation, quote UI states and submission blocking for the proposed flow remain unimplemented. Implementing a new gate on the existing submission flow would also conflict with the instruction to leave Production payout submission unchanged.

## Capabilities established from code and local fixtures

| Capability | Existing behavior and evidence | Suitability for the proposed wizard |
| --- | --- | --- |
| Source-driven FX quote | `app/Services/Nium/NiumQuoteService.php` sends POST `/api/v1/client/{clientHashId}/quotes` with `sourceCurrencyCode`, `destinationCurrencyCode`, `sourceAmount`, `customerHashId`, `quoteType=payout`, `conversionSchedule=immediate`, `executionType=manual`, `lockPeriod=5_mins`, `quoteIntent=EXECUTABLE`. Requires positive source amount and different currencies. `NiumQuoteServiceTest` verifies the request and response mapping. | Verified locally for FX conversion only; no proof the input includes payout fees or total debit. |
| Quote response | Maps `id`, `expiryTime`, `sourceAmount`, `destinationAmount`, `exchangeRate`, `netExchangeRate`; stores `provider_fx_type=modern_quote`. | Provider destination amount is an FX quote amount, not a guaranteed beneficiary credit under SHA. |
| Destination-driven quote | Generic API accepts optional `target_amount`, but requires `source_amount`. Nium service ignores `target_amount` and never sends `destinationAmount`. A destination-only service call is rejected without HTTP. | Unsupported by the existing integration. Do not divide by an FX rate, iterate forward quotes or derive fees to simulate reverse quoting. |
| Fee-inclusive source quote | Both NIUM quote services persist `fee_amount=0` themselves. No payout fee endpoint, fee currency mapping, fee allocation contract or total debit response is implemented. | Unverified. Stored zero is not a provider-confirmed zero fee. |
| Legacy payout FX lock | `NiumPayoutFxLockService` GETs `/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/lockExchangeRate` with `sourceCurrency` and `destinationCurrency`; no amount is sent. Maps `audit_id`, `fx_rate`, `hold_expiry_at` and optional hold metadata; computes local destination amount as source × rate. Same currencies are rejected. | A rate lock, not a fee-aware payout quote or verified reverse quote. |
| Expiry and revalidation | Modern quote persists `expiryTime`; legacy lock persists `hold_expiry_at`. Submission checks expiry, ownership, provider, pair, source amount, rate and lock type. Fresh legacy locks are acquired server-side for eligible cross-currency transfers lacking a lock. Existing expired locks are rejected. | Server safeguards exist for legacy FX locks. No wizard quote freshness/binding exists. |
| Quote refresh endpoint | `services.nium.quote_fetch_endpoint` configures `/api/v1/client/{clientHashId}/quotes/{quoteId}`. No execution path uses it to fetch/revalidate a quote. User GET quote endpoints read local records. | Configuration alone does not verify provider revalidation. |
| USD/USD SWIFT pricing | Both FX services reject same-currency input. Same-currency submission deliberately has no FX lock or rate. No USD/USD fee quote fixture exists. | Unsupported for this feature. Do not display a fabricated rate of 1, equal recipient amount or zero fees. |
| Corridor capability lookup | `NiumSupportedCorridorService` uses configured fixtures or GET `/api/v3/client/{clientHashId}/supportedCorridors`, matching country, currency, method, account/customer type and routing types. | Verifies beneficiary corridor compatibility only; contains no reverse-quote or fee-inclusive pricing capability check. |

Flags `NIUM_PAYOUT_FX_ENABLED` and `NIUM_WALLET_FX_ENABLED` default to false in `config/services.php`. Actual Production settings were not read or changed. Neither flag establishes reverse pricing. `config/integrations.php` registers `NiumQuoteService` as the generic quote provider.

Application pricing endpoints are POST/GET `/api/user/users/{user}/fx-quotes` and GET `/api/user/users/{user}/fx-quotes/{fxQuote}` (`routes/user.php`, `FxQuoteController`). Public/authenticated `provider-rates` previews may use managed or market reference prices (`app/Services/Quotes/PublicProviderRateService.php`); those are not verified payout prices and cannot supply the requested calculation.

The older `docs/nium-integration-playbook.md` describes optional destination amounts and passing quotes into transfers. The current executable policy rejects client `fx_quote_id`; modern quote IDs cannot be used as legacy payout `auditId`. The current code and fixtures take precedence over those older examples. No authoritative provider specification or fixture establishing fee-inclusive or reverse payout pricing was found in the reviewed repositories.

## Current amount and submission semantics

- Frontend `src/pages/account/AccountTransfers.tsx` renders one **Transfer amount** input, validates its amount and available wallet balance, and shows recipient amount as **Determined during processing**. Review, result and summary use the same presentation; provider fees are **Calculated during processing**, with a general SHA fee notice. No quote is requested by this wizard.
- `src/pages/account/transferForm.ts` enables active HK/USD/SWIFT beneficiaries and USD funding only. It preserves the entered amount as `source_amount`; NIUM `target_amount` and `fx_rate` are null and `fee_amount` is zero. The zero is an internal placeholder.
- Backend `NiumTransferPolicy::providerPayload()` sends that same source amount unchanged as `payout.sourceAmount`, `sourceCurrency=USD`, the beneficiary's destination currency, `payoutMethod=SWIFT`, `swiftFeeType=SHA` and the reference as `tradeOrderID`. It also sends beneficiary ID, purpose, `sourceOfFunds=Corporate Account` and comments. Cross-currency payout alone adds a numeric legacy `auditId`.
- Backend policy requires an approved HK corporate customer and active synced HK SWIFT beneficiary matching provider/user/currency. Cross-currency drafts are conditionally supported by the backend flag but are not enabled by this frontend's corridor policy. Modern quote creation does not establish payout corridor availability.
- `TransferEligibilityService` checks synced available balance against `source_amount`; `LedgerService` reserves and settles `source_amount`, without provider fee pricing. This proves the application's reservation amount, not NIUM's eventual all-in wallet deduction.
- Approval checks remain server-authoritative. The transfer manager validates eligibility before reserving; NIUM locks the transfer row before marking `submitting`. Duplicate/uncertain submissions cannot repeat the remittance POST. Unknown outcomes retain their hold for reconciliation; definitive failures release holds.
- Creation idempotency uses the customer/provider/client reference and rejects conflicting payloads. The frontend generates a new reference for each create mutation invocation, so existing idempotency is not a guarantee against repeated create attempts with different references. This behavior was inspected and left unchanged.
- Approval can trigger submission; any future binding between a reviewed quote and submission must account for approval delay and quote expiry. The present flow has no fee-inclusive reviewed quote to bind.

SWIFT SHA permits intermediary and beneficiary-bank deductions, so final received credit cannot be guaranteed by the available FX response. No local contract quantifies these deductions or establishes whether a particular provider fee is added to wallet debit or deducted from transfer proceeds. Do not silently subtract fees from `sourceAmount`.

## Changes and validation

Only documentation and regression tests changed:

1. `docs/nium-payout-bidirectional-pricing-inspection.md` — this capability and boundary report.
2. `tests/Feature/NiumQuoteServiceTest.php` — blocks stray HTTP, checks source-only request mapping, rejects USD/USD and destination-only pricing before HTTP, and verifies that an explicit provider destination amount is used instead of client destination input or a recomputed amount.
3. `tests/Feature/NiumTransferServiceTest.php` — verifies expiry prevents remittance and replacement locks while releasing the hold; insufficient funds and missing approval prevent HTTP and holds.

All six new tests pass. The selected backend suite runs with SQLite in memory, HTTP fakes and a temporary PHPUnit bootstrap that calls `Http::preventStrayRequests()` at each test's Prepared event. No real provider POST was issued. Command:

```sh
php vendor/bin/phpunit --bootstrap /tmp/origin-wallet-payout-offline-bootstrap.php \
  tests/Feature/NiumQuoteServiceTest.php \
  tests/Feature/NiumPayoutFxLockServiceTest.php \
  tests/Feature/NiumTransferServiceTest.php \
  tests/Feature/CustomerTransferSecurityTest.php \
  tests/Unit/TransferClientReferenceIdempotencyTest.php
```

The temporary bootstrap loads the repository autoloader and registers a `PHPUnit\Event\Test\PreparedSubscriber` that calls `Illuminate\Support\Facades\Http::preventStrayRequests()` after each Laravel test's setup. It is local test tooling, not application configuration.

Results: 64 backend tests, 318 assertions, 63 pass and one failure in unchanged `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources` at line 167 (`beneficiary.raw_data` is present although the assertion expects it absent). This unrelated response-contract issue was left unchanged. Existing passing tests include USD/USD payloads, SHA selection, approval and single-submission safeguards, uncertain-outcome retries, and client-reference idempotency.

Frontend: `npm run test -- src/pages/account/transferForm.test.ts src/pages/account/AccountTransfers.payeeList.test.tsx` passes all 18 tests. `npm run lint` passes with 22 existing warnings. `npm run build` passes with a bundle-size warning. No frontend files changed.

PHP syntax checks pass for both changed test files. Pint passes `NiumQuoteServiceTest.php`; `NiumTransferServiceTest.php` retains pre-existing formatting failures (also reproduced on its HEAD version), which were not reformatted outside the added tests. Diff whitespace checks pass.

An initial attempt to disable PHP HTTP transport functions prevented Guzzle from constructing even mocked requests. Those harness errors were resolved by using Laravel's stray-request guard for the reported test run.

No new UI editing, stale-response/debounce, quote UI expiry, accessibility or Review/Submit quote-consistency tests were added because the proposed UI/pricing flow is blocked and unimplemented. Existing frontend payload/recipient-presentation tests were run; they do not validate the proposed bidirectional feature.

## Required evidence before implementation can resume

- A verified NIUM contract and sanitized local fixtures for USD/USD HK SWIFT SHA fee pricing, including amount meanings, fee currencies, debit allocation, precision/rounding and the authoritative total wallet deduction.
- A verified destination-driven payout quote request/response for each allowed corridor, or explicit evidence of non-support so the UI can explain and block that mode.
- A compatible quote identifier and freshness/revalidation contract for the existing remittance API, including review, delayed approval and submission. Modern FX quote IDs must not be substituted for legacy audit IDs.
- A documented provider distinction between quoted/estimated beneficiary proceeds and guaranteed delivered credit, with explicit SHA intermediary-fee uncertainty.

Remaining financial risks are the unresolved provider fee/debit allocation, uncertain SHA recipient credit, existing zero-fee placeholders and FX amount/rate fallbacks, local-only quote expiry checks, and create retries with new references. None were reinterpreted or changed. Do not enable bidirectional pricing or claim a fee-inclusive amount from the currently verified contracts. No further task work should proceed before review.
