**NIUM SG/SGD/LOCAL payout preparation and Production safety report**

Date: 2026-10-30 (Asia/Bangkok). Repositories: `api_origin_wallet` and `origin-wallet-platform`.

SG/SGD/LOCAL remains **unavailable for real-money submission**. This pass implements read-only candidate validation, a non-configurable contract hold, FX audit validation, and customer messaging/tests. It does not implement an executable LOCAL remittance payload. The existing HK/USD/SWIFT payload and approval/idempotency/unknown-outcome protections remain covered by passing regression tests.

The task supplies Production evidence of one SG/SGD/LOCAL, CORPORATE-to-INDIVIDUAL corridor with SWIFT routing. That evidence establishes corridor capability. No Production API was called to reproduce it, and no customer, beneficiary, wallet or transfer identifiers were hardcoded as an eligibility bypass.

**Authoritative evidence and mandatory inspection**

Public documents were downloaded using documentation-only GETs:

- [NIUM OpenAPI](https://docs.nium.com/oas/nium.yaml).
- [Transfer Money guide](https://docs.nium.com/docs/payouts/transfer-money).

Exact relevant OpenAPI excerpts, their source URL, retrieval date and SHA-256 of the downloaded specification are preserved in `tests/Fixtures/nium/sg-sgd-local-contract-evidence.json`. These public examples are illustrative; they are not sanitized Production responses or evidence of client-specific entitlement. The downloaded full specification and guide are under `/tmp`, outside either repository.

| Inspection | Evidence and conclusion |
| --- | --- |
| 1. Exact SGD Local Remittance payload | POST `/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance` references `RemittanceTransactionsRequestDTO` and `Payout`. Its `LocalPayout` example sends `beneficiary.id`, `payout.sourceAmount`, `payout.sourceCurrency`, purpose, source of funds and comments. `Payout` does **not** declare `payoutMethod` or `destinationCurrency`. The guide instead associates payout method/currency/routing with a registered or inline beneficiary. This does not verify the exact explicit LOCAL request requested by this task. Do not transplant fields from SWIFT or another endpoint. |
| 2. `swiftFeeType` on LOCAL | It is an optional field in the generic `Payout` schema, described specifically as SWIFT charge allocation, defaulting to SHA for SWIFT. The LOCAL example omits it. There is no explicit statement that it is prohibited on LOCAL, and no reason to send it for this new route. No executable LOCAL payload was added. |
| 3. USD `sourceAmount` meaning | The remittance description requires the source currency to be held in the wallet and balance to cover the amount **and fees**. `sourceAmount` is the remittance source amount, denominated by `sourceCurrency`. This supports USD source denomination, but does not establish that this amount is the complete fee-inclusive USD wallet debit. The public schema permits two decimal places; SG amount/rounding enforcement awaits the executable contract. |
| 4. Legacy lock compatibility | GET `lockExchangeRate` explicitly says to pass its `auditId` as the Remittance payout `auditId` or `audit_id`. It accepts source/destination currency query parameters. This proves the generic legacy API relationship; it does not prove this HK client's USD/SGD LOCAL pair entitlement. No real lock was requested. |
| 5. Lock response | The referenced `FxHoldLockResponseContent` uses `audit_id`, `fx_rate`, `hold_expiry_at`, optional currency/hold/markup/status fields. The endpoint's illustrative example instead uses `auditId`, `exchangeRate`, `expiresAt`. The existing adapter supports snake case and `auditId`/`fxRate`/`holdExpiryAt`; no undocumented response shape was added. A verified USD/SGD response fixture is still required. |
| 6. Expiry and fees | Legacy audit IDs are valid for the same client/customer/wallet until expiry. `fx_rate` is effective after `markup_rate`; this is not proof of zero payout fees. The lock response has no authoritative final recipient-credit or total wallet-debit field. Validation now rejects non-positive rates, expired/invalid expiry, inactive responses and conflicting returned currency pairs. Existing local fee/target-amount placeholders are not provider fee or final-credit evidence. |
| 7. SGD beneficiary fields/routing | The supplied Production corridor establishes SWIFT routing. The public `LocalSgdSwift` example also uses SG/SGD/LOCAL, INDIVIDUAL and `DBSSSGSGXXX`, but is under **accountVerification**, not beneficiary creation or remittance. Its example account/address values must not be treated as SGD requirements. A successful, dimension-matched beneficiary validation schema and reviewed provider beneficiary evidence are still needed. Candidate validation requires a well-formed SWIFT/BIC and matching Supported Corridors routing evidence. |
| 8. Current beneficiary creation | `NiumBeneficiaryService` is already corridor/schema driven; it can represent SG/SGD/LOCAL and map SWIFT routing into `routingCodeTypeN`/`routingCodeValueN`. It requires provider readiness, Supported Corridors and trusted validation-schema/preflight checks, then persists the successful payout method. No new beneficiary creation behavior or SGD requirement assumptions were added. Existing beneficiary regression tests pass. This is structural support, not a verified SGD creation contract. |
| 9. Payment Purpose and genuine CUG test | GET `/api/v1/remittance/purposeCodes` has no corridor/customer query parameters and returns supported code descriptions. The current service uses that authoritative list and caches it; payout policy retains exact-code validation. No supplied provider response or business facts identify a truthful CUG penny-drop purpose. No code was selected, no goods-trade purpose was reused as a test purpose, and mock `TEST_PURPOSE` is test-only. |
| 10. HK Corporate/provider account checks | Existing payout policy requires an approved/verified business KYC profile resolving to HK; transfer eligibility checks user verification and account readiness, while final submission additionally requires active/clear/reconciled, verified customer/wallet identifiers and no security conflict. Corridor lookup previously used general-profile `user_type`. It now prefers KYC `applicant_type`, retaining the existing profile fallback for older beneficiary flows. The SG candidate gate requires KYC plus reconciled provider/wallet readiness, ownership, active status, external beneficiary ID, and an Individual account. |

The current public schema also documents a distinct modern `quoteId` alternative, mutually exclusive with `auditId`. This task intentionally preserves the legacy payout audit mechanism. No modern quote identifier is converted to an audit ID, and modern quote behavior was not changed.

**Implemented behavior**

`NiumSgdLocalPayoutGate::assertCandidate()` is a read-only capability check for an HK Corporate sender and an owned, active, provider-synced SG/SGD Individual beneficiary whose authoritative payout method is LOCAL and whose routing is SWIFT. It matches all five Supported Corridors dimensions and routing, and fails closed on unavailable/missing/mismatched evidence. Passing it does not authorize a transfer.

`assertSubmissionAllowed()` always throws a clear contract-pending error. It is deliberately not an environment flag. Transfer creation/policy, provider payload generation, pre-FX submission checks and direct SGD legacy-lock creation cannot cross this hold. Tests exercise the paths with the global FX flag both disabled and enabled. No configuration value alone can activate this route.

Submission now runs customer/beneficiary/purpose/amount policy validation before preparing an FX lock. Final validation, row locking and the existing one-submission state machine remain in place. Invalid purpose or beneficiary configuration therefore cannot initiate an FX-lock request. Existing lock expiry, owner/provider/pair/amount/rate checks remain, with expiry at the current instant rejected as well.

`NiumPayoutAuditId` requires a positive, representable integer and equality between persisted `quote_ref` and authoritative `raw_data.audit_id`, with `provider_fx_type=payout_fx_lock`. Decimal/exponent/overflow identifiers and modern quotes are rejected before remittance. Tests cover wrong customer/provider, source amount, currency, rate, expiry and audit binding without replacement locks or remittance POSTs.

Supported Corridors caching now includes NIUM client ID, base URL and endpoint, preventing a cached capability result from being reused across configured clients/environments. Missing routing evidence can no longer satisfy an explicit routing check.

The frontend keeps SG/SGD/LOCAL recipients visible but disabled with a clear temporary-unavailability reason. It does not introduce a frontend enablement switch. Amount, review and summary presentations identify USD funding and SGD destination, with unavailable/pending FX and recipient amount, and unknown fees. LOCAL messages and prepared client metadata omit SWIFT SHA fee handling. Existing HK/USD/SWIFT behavior remains selectable. The parent wizard's server creation, review/admin approval, submission and retry flow is unchanged.

No new executable payout country/currency was enabled. Dormant legacy FX transport fixtures using USD/EUR remain regression coverage for the pre-existing mechanism, not evidence or approval for those payouts. This work does not certify any additional Production corridor.

**Changed files in this pass**

Backend paths are relative to `api_origin_wallet`; frontend paths to `origin-wallet-platform`.

| File | Change |
| --- | --- |
| `app/Services/Nium/NiumSgdLocalPayoutGate.php` | New narrow candidate validation and immutable submission hold. |
| `app/Services/Nium/NiumPayoutAuditId.php` | New authoritative integer audit-ID validation. |
| `app/Services/Nium/NiumTransferPolicy.php` | SG candidate/hold integration and reusable pre-FX validation. |
| `app/Services/Nium/NiumTransferService.php` | Pre-FX policy checks and stronger existing-lock checks. |
| `app/Services/Nium/NiumPayoutFxLockService.php` | Block SGD locks; validate returned legacy audit, rate, expiry, status and currencies. |
| `app/Services/Nium/NiumSupportedCorridorService.php` | KYC customer type, cache isolation and required routing evidence. |
| `tests/Feature/NiumSgdLocalPayoutGateTest.php` | SG capability, ownership/readiness/routing failures, closed submission paths and preserved balances. |
| `tests/Feature/NiumPayoutFxSafetyTest.php` | Malformed/failing locks and audit-ID provenance tests. |
| `tests/Feature/NiumTransferServiceTest.php` | Added mismatch and invalid-purpose tests; all pre-existing file content/edits preserved. |
| `tests/Fixtures/nium/sg-sgd-local-contract-evidence.json` | Verbatim public contract excerpts and provenance. |
| `docs/nium-sg-sgd-local-payout-safety.md` | This report. |
| `src/pages/account/transferForm.ts` | Closed SG eligibility messaging, correct destination/pending presentation, LOCAL metadata/fee distinction. |
| `src/pages/account/AccountTransfers.tsx` | Details, review, result/list and summary presentation. |
| `src/pages/account/transferForm.test.ts` | SG unavailable/payload/pending tests and existing HK regression. |
| `src/pages/account/AccountTransfers.payeeList.test.tsx` | Disabled SG selection and details/review/summary consistency tests. |

The pre-existing changes in `tests/Feature/NiumQuoteServiceTest.php` and `docs/nium-payout-bidirectional-pricing-inspection.md` were not edited. The pre-existing transfer-test content was compared with its initial snapshot and preserved exactly outside the added methods. No Production `.env`, configuration, database schema or dependency files changed.

**Validation**

All backend tests use SQLite in memory and mocked NIUM responses. New tests call `Http::preventStrayRequests()`; the broader run additionally uses `/tmp/nium-sg-offline-bootstrap.php` to enforce that guard after each Laravel test setup. No real NIUM money-movement request was made.

| Check | Result |
| --- | --- |
| Focused SG gate, FX safety and transfer regression | 100 tests passed. |
| Broader backend payout/beneficiary/purpose/security/idempotency suite | 305 tests, 1982 assertions; 304 pass and one previously documented failure. `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources`, line 167, expects nested beneficiary `raw_data` to be absent although the unchanged resource includes it. See the pre-existing bidirectional-pricing report for the same failure. |
| Frontend complete test suite | 21 test files, 246 tests passed. |
| Frontend lint | Pass; 22 existing warnings, no errors. |
| Frontend Production build/prerender | Pass; existing Browserslist freshness and large-bundle warnings. |
| Additional frontend TypeScript check | Fails in unchanged `AccountKyc.tsx` (2 diagnostics), `AccountRfi.tsx` (2 ES2020 `replaceAll` diagnostics), and `moneyMovementService.ts` (2 payload index-signature diagnostics). No diagnostics in the changed files. |
| PHP syntax | All six changed/new service files and three test files pass. |
| Pint | Changed/new service files and both new test files pass. The existing `NiumTransferServiceTest.php` retains pre-existing formatting failures, reproduced against its initial snapshot; it was not reformatted around user edits. |
| Whitespace | `git diff --check` passes in both repositories. |

The broader backend command was:

```sh
php vendor/bin/phpunit --bootstrap /tmp/nium-sg-offline-bootstrap.php \
  tests/Feature/NiumSgdLocalPayoutGateTest.php \
  tests/Feature/NiumPayoutFxSafetyTest.php \
  tests/Feature/NiumTransferServiceTest.php \
  tests/Feature/NiumPayoutFxLockServiceTest.php \
  tests/Feature/NiumQuoteServiceTest.php \
  tests/Feature/NiumBeneficiaryServiceTest.php \
  tests/Feature/NiumBeneficiaryRequirementsServiceTest.php \
  tests/Feature/NiumBeneficiaryProductionSchemaTest.php \
  tests/Feature/NiumBeneficiaryLifecycleTest.php \
  tests/Feature/NiumPurposeCodeApiTest.php \
  tests/Feature/NiumTransferKycProfileStatusTest.php \
  tests/Feature/CustomerTransferSecurityTest.php \
  tests/Unit/TransferClientReferenceIdempotencyTest.php
```

Frontend commands: `npm run test`, `npm run lint`, `npm run build`, and `npx --no-install tsc --noEmit -p tsconfig.app.json`. Detailed logs are in `/tmp/nium-sg-*-validation.log`, `/tmp/nium-sg-frontend-*.log` and `/tmp/nium-sg-pint-*.log`.

**Production assessment and follow-up evidence**

SG/SGD/LOCAL is **not Production ready**. Actual LOCAL payload generation/submission, verified USD/SGD locks, authoritative destination/fee/debit pricing, CUG purpose selection and customer selection of this route remain intentionally unavailable. The added tests prove the safety boundary and mock integration behavior, not NIUM Production compatibility or a completed penny-drop.

Before a later implementation can open this route, obtain and review:

1. A client-appropriate SG/SGD/LOCAL Individual beneficiary validation schema and provider evidence tying the registered beneficiary to the method, currency and routing.
2. The exact registered-beneficiary Remittance request for this route, resolving where method/currency/routing belong, LOCAL fee-field treatment, two-decimal precision/rounding and reference constraints.
3. Explicit compatibility of legacy USD/SGD `lockExchangeRate` with this HK customer's LOCAL payout, a sanitized response with authoritative audit/rate/expiry, and client/customer/wallet binding evidence.
4. Fee currencies/allocation and source-amount versus total wallet-debit semantics; any authoritative recipient amount must distinguish conversion proceeds from final delivered credit.
5. A supported purpose description and the actual business facts supporting a genuine CUG test. Code availability alone is not evidence that a purpose truthfully describes the transaction.

No configuration change is required or appropriate now. In a later reviewed release, replace the hard hold only after implementing the verified LOCAL contract, add explicit corridor-scoped server admission/capability reporting, and verify that enabling payout FX cannot admit unverified routes. Only then consider the required scoped gate and `NIUM_PAYOUT_FX_ENABLED` setting. Merely setting the existing global flag must never serve as route approval. Approval delays and expiry must still fail safely, and an unknown submission outcome must remain reconciliation-only.

No Production environment change, live FX lock, live beneficiary/transfer creation, manual database change, commit, push or deployment was performed.
