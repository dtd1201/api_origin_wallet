# SGD Local Phase 3 review

Date: 2026-10-30, Asia/Bangkok.

**Decision: the candidate is available for technical review; SGD Local money movement remains blocked.** The executable, customer-specific USD-to-SGD legacy lock, registered-beneficiary remittance, pricing and purpose contracts have not been established. This report supersedes earlier phase reports where they describe integration state or test results.

**1. Existing code versus the supplied Production commits**

| Repository | Original checkout | Supplied Production | Ancestry and candidate |
| --- | --- | --- | --- |
| Backend | `main`, `5a737b5`, with uncommitted Phase 2, beneficiary and pricing work | `30e5460` | Common ancestor `dfb096b`; original HEAD has one unique commit, Production has two. Candidate: `/home/dtd1201/projects/api_origin_wallet_sgd_phase3`, detached at `30e5460`. |
| Frontend | `main`, `fc4a074`, with uncommitted Phase 2 and beneficiary work | `1711f82` | Common ancestor `e6291f6`; each side has one unique commit. Candidate: `/home/dtd1201/projects/origin-wallet-platform_sgd_phase3`, detached at `1711f82`. |

The original branches, dirty files and other worktrees were preserved. No fetch, merge, rebase, reset, commit, push or deployment was performed. Production identifiers are the user's supplied deployment references; no deployment host or Production database was queried.

The candidate starts from the deployed beneficiary hotfixes and copies the existing Phase 1/2 payout work onto that base. It deliberately retains Production's beneficiary controller, write service, safe diagnostic exception, routes and beneficiary workflow behavior. Copying the original dirty checkout wholesale would have removed the safe failure diagnostics introduced by `30e5460`. Production beneficiary tests are retained, with one additional mocked registration integration test. Frontend beneficiary implementation and existing beneficiary tests remain at `1711f82`.

The cumulative difference from Production includes the existing fail-closed SGD gate, legacy audit validation and lock binding, conservative customer amount projection, backend admission metadata, and frontend SGD presentation. The unrelated bidirectional-pricing report and existing pricing regression additions were copied without changing their behavior. Their unresolved fee-inclusive/reverse-pricing boundary remains intact.

**2. Completed Phase 3 changes**

- Added stronger registration checks to `NiumSgdLocalPayoutGate`: current customer and provider ownership, active NIUM provider, verified HK corporate KYC, active/clear/reconciled provider account and verified provider identifiers, active local beneficiary, SG/SGD/LOCAL Individual dimensions, BIC routing, and exact Supported Corridors matching remain required.
- Registration additionally requires the latest local `beneficiary_create` audit for the same user, provider and selected local beneficiary; a successful POST in the configured client/environment; captured, readable response evidence with matching SHA-256; an explicit provider `ACTIVE` status; the exact stored external beneficiary ID; and successful LOCAL request evidence from that same audit. Missing, incomplete, pending, failed, unreadable or mismatched evidence fails closed. Client metadata cannot replace it.
- Audit URLs redact client/customer identifiers. The check follows that existing format, using the log's user/provider/local-beneficiary binding, configured client, environment/endpoint and response beneficiary ID. It does not claim that the redacted URL independently proves an historical external customer ID. Fresh provider-side ownership/status confirmation remains a pre-admission requirement if that binding cannot otherwise be established.
- The registration check reads existing local evidence only. It creates no beneficiary, requests no lock, sends no payment, and does not change the Production beneficiary write flow. Existing registrations without sufficient captured evidence stay blocked. The captured creation response is historical evidence, not a fresh provider-status lookup; the current local status and provider account must also pass.
- Tests cover selected local beneficiary IDs **9 and 37** to demonstrate dynamic ID use. These are synthetic in-memory fixtures, not a re-verification of Production beneficiary 9 or user 12. The existing transfer relationship resolves the chosen local beneficiary and its stored external provider ID; business logic contains no special case for ID 9.
- Added `fx_lock_expires_at` to the conservative SGD amount projection, populated only after full legacy lock validation. Missing, expired, mismatched or unbound locks expose no current rate, conversion amount or expiry.
- Added frontend expiry display and timed expiry invalidation. Rate and conversion values disappear when the displayed lock expires, or when expiry is missing/invalid. Funding principal, unknown total debit, provider-reported fees and unknown final credit remain distinct.
- Kept NIUM Submit controls hidden, including while provider metadata is unavailable. SGD approval messaging now explains that admin approval cannot open the blocked corridor.
- Added a delayed-admin-approval regression: an expired attached lock preserves the approval and quote identity, releases the temporary reservation, and sends neither a replacement lock request nor a remittance. It uses the existing legacy non-SGD transport fixture because SGD cannot pass the immutable route hold.
- Added `tests/PayoutOfflineBootstrap.php`, which requires testing mode and in-memory SQLite and rejects unfaked Laravel HTTP during payout regressions.

No executable SGD remittance payload or speculative fee/reservation calculation was added. Admin approval, ledger reservation/settlement/release, idempotency, submission row locking, definitive-failure handling and unknown-outcome reconciliation retain their existing state machines. Tests cover HK/USD/SWIFT SHA, duplicate submission and unknown outcomes. An unknown remittance outcome must never automatically retry the POST.

**3. Confirmed NIUM contract evidence**

Evidence inspected in this task consists of the existing public-document excerpts in `tests/Fixtures/nium/sg-sgd-local-contract-evidence.json` and `tests/Fixtures/nium/sg-sgd-local-phase2-evidence.json`, their source URLs and recorded document hashes. No fresh provider-account response or live lock/remittance was obtained. The customer's active registration, Supported Corridors V3 match and NIUM CUG authorization are accepted as supplied facts, separate from executable payload evidence.

| Evidence | Established behavior | Limit |
| --- | --- | --- |
| [Transfer Money](https://docs.nium.com/docs/payouts/transfer-money) and public OpenAPI | A pre-created beneficiary is referenced through `beneficiary.id`, using its provider beneficiary ID. Generic LOCAL example includes payout `sourceAmount` and `sourceCurrency`. | This does not settle the exact customer's registered SG/SGD/LOCAL USD-funded request. |
| [Singapore SGD payments](https://docs.nium.com/docs/payouts/country-and-regional-guides/sgd-payments-to-singapore) | LOCAL bank-account payout uses BIC routing, with rail selection by NIUM. Guide states SGD minimum 1 and two decimal places. | Its required snake-case destination/method/amount fields are not precisely mapped to the generic registered-beneficiary request. `SWIFT` versus `SWIFT CODE`, and the applicable source-driven amount/minimum rules, still require verification. |
| Legacy `lockExchangeRate` excerpt | Legacy numeric audit ID, rate and absolute hold expiry; audit applies to the same client/customer/wallet. | No executable pair-specific USD/SGD evidence was acquired. Modern quote IDs are never substituted for legacy audit IDs. |
| [Fees](https://docs.nium.com/docs/fees-and-limits/fees) | LOCAL remittance has its own fee category, and amount plus fees can affect wallet debit. Pricing depends on the applicable plan. | No applicable customer fee, fee payer, fee currency, all-in debit or guaranteed final credit was established. Example prices and internal zero-fee placeholders are not evidence. |
| [Payout Validation Schema](https://docs.nium.com/docs/payouts/transfer-money/payout-validation-schema) | Corridor/customer-specific payout schema provides field constraints and allowed purposes. | The actual customer's SG schema is absent. A beneficiary validation schema or global purpose list is insufficient. |

The legacy adapter maps source USD to destination SGD explicitly, validates source principal exactly, requires a positive rate, validates a numeric audit ID and future absolute expiry, and binds stored locks to user/provider/client/customer/wallet/environment. Existing invalid or expired locks are rejected rather than silently refreshed. SGD lock acquisition stays blocked even with the global FX flag enabled. Existing non-SGD historical compatibility behavior is retained.

The legacy GET locks a rate and does not send an amount. Its source amount is a local binding to the transfer, not proof of a provider amount quote. The adapter's historical calculated target and zero fee are not used as authoritative SGD customer values. Explicit provider conversion proceeds still do not establish final beneficiary credit.

**4. Remaining provider-specific blockers**

1. Obtain the actual customer's read-only payout schema or explicit NIUM clarification for `GET /api/v1/client/{clientHashId}/customer/{customerHashId}/currency/SGD/payout/schema?payoutMethod=LOCAL&destinationCountry=SG`. Confirm a complete registered-beneficiary request, placement/derivation of method and destination currency, USD source principal versus SGD destination amount, BIC token, purpose, minimum and precision. Do not build a LOCAL payload by copying HK SWIFT fields. `swiftFeeType` is SWIFT-specific and must not be carried into LOCAL without applicable evidence.
2. Obtain explicit compatibility evidence and a sanitized expected legacy USD/SGD lock response: numeric audit ID usable by this remittance API, currency mapping, rate, expiry and customer/wallet binding. No live Production lock is authorized by this task.
3. Confirm the actual fee plan, fee currencies, fee payer and debit allocation, rounding, total wallet debit and recipient-credit semantics. Current eligibility and ledger reservation cover source principal; they do not reserve an unverified additional provider fee. Implement and review fee-aware sufficiency/reservation only after that model is verified.
4. Confirm a truthful CUG payment purpose from the transaction's actual business facts and applicable Corporate-to-Individual SG constraints. Do not select a public example code as a universal test purpose.
5. Inspect beneficiary 9's existing registration evidence and, if necessary, obtain fresh read-only provider confirmation of ownership, external ID, corridor and active status. Do not recreate or modify it to satisfy the new check. Historical/missing capture or redacted external-customer context must be resolved through authoritative evidence, not fabricated metadata.
6. Establish accepted/rejected/unknown SG remittance responses and the authoritative status/reconciliation contract. Until then there is no mocked claim of successful SGD submission; SGD tests prove eligibility inspection and denial before money movement. Generic and HK unknown-outcome tests preserve the no-retry rule.

**5. Validation results**

| Check | Result |
| --- | --- |
| Backend payout/beneficiary/security/reconciliation/idempotency regression selection | **294 tests, 1,516 assertions; 293 pass, one pre-existing failure.** All provider operations are mocked; SQLite is in memory and stray Laravel HTTP is blocked. |
| Existing failure reproduced at pristine supplied Production backend `30e5460` | `CustomerTransferSecurityTest::test_transfer_list_and_detail_use_customer_safe_nested_resources`, line 167: expects `beneficiary.raw_data` absent, while Production's resource returns an allowlisted metadata object. Same failure on candidate and Production. No unrelated API contract change was made. |
| Frontend complete Vitest suite | **23 files, 269 tests pass.** |
| Frontend ESLint | Passes with 0 errors and 22 existing warnings. |
| Frontend production build | Passes; existing large-bundle and outdated Browserslist warnings. |
| Backend Vite asset build | Passes with locked dependencies; no package or lockfile change. Dependencies were installed from the public npm registry with lifecycle scripts disabled. |
| PHP syntax | All 18 changed/new PHP files pass. |
| Pint on Phase 3 gate/projection/bootstrap/registration/presentation/workflow files | Passes. |
| Pint on cumulative payout selection | Existing formatting failures remain in `NiumTransferServiceTest.php`; also reproduced on its pristine `30e5460` version. Production's large legacy test file was not globally reformatted. |
| Diff whitespace checks | Pass in both candidates. |

The backend selection includes `NiumSgdLocalPayoutGateTest`, `NiumSgdLocalPayoutPresentationTest`, `NiumPayoutFxLockContractTest`, `NiumPayoutFxSafetyTest`, `NiumPayoutFxLockServiceTest`, `NiumTransferServiceTest`, `NiumSgdBeneficiaryWorkflowTest`, `NiumBeneficiaryLifecycleTest`, `NiumBeneficiaryServiceTest`, `NiumBeneficiaryProductionSchemaTest`, `NiumQuoteServiceTest`, `NiumPurposeCodeApiTest`, `CustomerTransferSecurityTest`, `ReconcileNiumTransfersCommandTest` and `TransferClientReferenceIdempotencyTest`. Run with `php vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php` followed by those file paths. This is a selected backend regression run, not a claim that the entire backend suite passed.

Review patches, file manifests, commands and logs are in `/home/dtd1201/projects/origin-wallet-sgd-phase3-review`. Patches include new untracked files; plain `git diff` alone omits those files. Original checkouts remain unchanged. The additional `/tmp/origin-wallet-sgd-phase3-production-baseline` worktree was used only to reproduce the existing test/style failures.

**6. Production readiness**

Ready for technical review of the cumulative safe preparation and Phase 3 hardening. **Not ready for SGD Local real-money submission or a Production lock.** The server always emits denied admission and the immutable gate rejects submission/direct SGD lock acquisition. Existing USD/SWIFT behavior is covered by passing payout regressions. The existing backend security-test contract mismatch and inherited test formatting issue remain visible review findings.

No Production database/configuration change, live NIUM request, real beneficiary create/update, live FX lock, remittance POST, money movement, global FX enablement, commit, push or deployment occurred. Mocked beneficiary creation exists only inside isolated tests.

**7. Minimal controlled steps before the first penny-drop**

1. Technical Lead reviews this candidate and the existing baseline test discrepancy. Resolve the provider blockers above using read-only schema/status evidence and explicit NIUM contract confirmation. Agree a test amount respecting the verified SGD minimum; do not assume a literal one-cent transfer is permitted.
2. Implement the verified LOCAL payload, numeric legacy audit serialization, amount precision, fee-aware balance sufficiency/reservation and response/status handling behind a narrowly scoped corridor/customer admission. Add success, rejection and unknown-response mocks. Preserve HK/USD/SWIFT regression results and the no-retry rule for unknown remittance outcomes.
3. Review and validate delayed approval behavior against the final financial contract. The current behavior rejects an expired attached lock and does not auto-refresh it. If fresh locking or re-review is needed, design and authorize that lifecycle explicitly before opening the route; do not silently change approved economics.
4. Obtain a separately authorized release and controlled CUG run for user 12 and existing beneficiary 9. Verify provider identifiers/status, approved purpose, precise funding principal, applicable fees, total debit, sufficient reserved balance and a still-valid legacy lock immediately before the single submission. Do not broadly enable FX or rely on the global flag as route approval.
5. Submit once under admin approval with a durable operation identity and audit capture. Reconcile any uncertain outcome with provider GET/status evidence; never automatically repeat the POST. Verify final provider lifecycle, wallet debit, fee postings, reservation settlement/release and beneficiary receipt before considering further admission.

These are prerequisites for a later authorized exercise, not authorization to execute it now.
