# GREENTECH to Andy: one USD 5 NIUM LOCAL pilot

**Superseded for the explicitly authorized uncapped one-time test:** use [the current release and activation report](nium-usd5-one-time-live-release.md). The fee-ceiling and separate-reserve prerequisites below describe the earlier funded policy, which is retained for existing authorizations; they do not apply to the new `one_time_uncapped` authorization. The local staging command's current signature is documented in the new report.

**Can Admin Approve dispatch a real NIUM Remittance POST after pilot activation? YES, when all prerequisites below pass.** The implementation reaches the existing `TransferApprovalService` → `ProviderTransferManager` → `NiumTransferService` → `NiumService::post(operation: transfer_money)` path. The offline Admin endpoint test asserts the exact LOCAL payload: `payout.sourceAmount = 5`, `sourceCurrency = USD`, and the attached numeric NIUM `auditId`. The locked effective rate is unchanged. NIUM performs the conversion; no FX markup is added to principal.

**Current live readiness: not activated or verified.** No live NIUM API, Production database/configuration change, deployment, Git commit or push occurred. All provider responses and the reserve/charge ceiling used in tests are synthetic. The work is confined to the existing Phase 3 backend/frontend worktrees. Earlier worktree changes are preserved.

## Exact blocker and scoped exception

The ordinary gate is `NiumSgdLocalPayoutGate::assertSubmissionAllowed()` → `NiumPayoutFinancialControls::assertFor()`. It requires an unexpired intent-bound confirmed quote, complete charges, known source-wallet allocation, zero source-wallet fees and a total wallet debit equal to principal. `NiumConfiguredRemittancePricing::quote()` intentionally returns `unavailable`: Fee Details does not establish effective customer pricing or the complete wallet debit. Missing fee rows and stored/expected zero fees do not satisfy admission. That adapter and the ordinary admission requirements are unchanged.

The exception requires **both** `NIUM_PAYOUT_PILOT_ENABLED=true` (default false) and the UUID of one durable authorization in `NIUM_PAYOUT_PILOT_AUTHORIZATION_ID`. A flag by itself grants nothing. Only the specifically authorized existing transfer may use it. Other transfers continue through ordinary Financial Admission.

The authorization is restricted to GREENTECH user **12**, Andy beneficiary **9**, USD → SGD payout, registered LOCAL beneficiary, and positive principal **at most USD 5**. These identities come from the existing controlled-test runbook and must be confirmed against Production before use. It additionally binds the local transfer ID/number, client reference, current authenticated client/customer/wallet/base URL, owned external beneficiary, purpose, exact principal, FX quote, audit, rate and expiry. A second grant for this exercise or reuse of its funding credit is prevented by database uniqueness. A copied transfer, another principal/reference or a refreshed FX audit cannot inherit it.

The local authorization command records an independent authorized operator's verification of **settled separate funding and a binding upper bound covering every possible additional USD charge to this same source wallet**. It stores funding/charge-bound references and the SHA-256 of the reviewed evidence document. This is explicitly `independent_operator_attestation`, **not** an automatically authenticated NIUM pricing response. The operator must verify the original evidence; a document hash or an arbitrary claimed buffer is not external proof.

Staging checks an existing positive posted USD funding credit in that exact owned wallet, requires sufficient current available balance for **principal plus reserve**, and atomically moves the charge ceiling from available to reserved balance under a separate `nium_payout_pilot` ledger hold. It does not mint funds or count USD 5 twice. The funding entry is fingerprinted, and authorization, ownership, reserve integrity, aggregate reserved funds and balance freshness (under five minutes) are checked again during review/approval/dispatch.

The existing maker review, active independent Admin with `transfers.approve`, verified account/beneficiary/corridor/purpose, FX expiry, idempotency and transport checks remain in force. The pilot UUID is consumed in the same transaction as the existing operation claim and **principal-only transfer hold**. The final existing pre-POST check verifies the unchanged approved snapshot and both funded holds. Normal submit, duplicate Approve or a timeout cannot produce a second POST.

## Charges and recovery

Pilot fee and total-debit fields remain **null/unconfirmed**, including the customer UI. Principal reservation is a separate field; it is never displayed as a confirmed all-in wallet debit. The existing business expectation of zero remains labelled as an expectation.

Existing authenticated wallet-activity reconciliation records principal and each distinct actual fee separately, with its original provider event identity/evidence. For pilot fees, each append-only adjustment also records how much used the separate reserve and how much was uncovered. The principal debit is not rewritten. Duplicate fee evidence cannot consume reserve twice. Any charge above the verified ceiling is still recorded as actual liability and raises `pilot_charge_ceiling_breached` in the API/UI. Absence of fee rows never confirms zero fees or final charge completeness.

Unknown/rejected outcomes retain both holds and the consumed authorization. Existing capture recovery, read-only status/financial reconciliation and no-POST-retry behavior remain intact. Accounting continues after the pilot flag is disabled or the FX/grant expires. Unused reserve remains held until an audited financial close establishes that no further charges can arrive. Deactivation, expiry and status-only success/failure do not release it. Do not delete/reset the authorization or edit operation/audit identities to retry.

## Exact remaining prerequisites

1. Independently confirm Production user 12 is GREENTECH and owned beneficiary 9 is Andy, with current authenticated customer/wallet mapping, active verified/reconciled corporate account, captured active LOCAL registration, SG/SGD corridor and truthful supported purpose.
2. Obtain and independently verify a binding **all-additional-charges USD ceiling and same-source-wallet allocation**, or an equivalent binding guarantee supporting that ceiling. Exact final fees need not be known. An unbounded fee exposure, client Fee Details, missing fee rows, an arbitrary cash buffer or post-payment evidence alone is insufficient. This task did not establish the real ceiling or its supporting document/reference.
3. Separately fund that reserve in the actual source wallet through the existing audited funding process; identify its distinct posted credit ledger entry and original provider funding evidence. Reconcile the current provider balance and existing reservations with Origin's ledger. This task did not fund or verify a live reserve. The USD 2 test reserve is **only a fixture**, not a recommended or verified real amount.
4. Have a trusted local operator and an active independent authorized verifier review the original evidence, including actual available funds of at least USD 5 plus the ceiling before staging. A current owned wallet balance and enabled reservation ledger are required. The verifier's identity/reference/hash is an auditable human sign-off, not a cryptographic NIUM attestation.
5. Deploy this cumulative Phase 3 candidate and migration with the pilot disabled; configure the existing NIUM credentials, transfer/FX endpoints, capabilities and reconciliation. Confirm all payment runtimes use the reviewed candidate. Deployment, fresh live FX locking and the single real-money transfer require the appropriate operational authorization; none were executed here.
6. Prepare exactly one USD 5 draft with a durable client reference, explicitly acquire a fresh server NIUM USD/SGD lock, stage this exact authorization, activate its UUID, refresh the maker's review, and obtain independent Admin approval **before expiry**. Staging freezes that FX audit and expiry; an expired grant cannot be silently renewed or moved to another transfer. Such an aborted exercise requires a separately reviewed resolution, without deleting the history or automatically releasing reserve.

## Minimal deployment and activation procedure

1. Review/deploy the existing cumulative backend/frontend candidate with `NIUM_PAYOUT_PILOT_ENABLED=false`. Apply the additive migration (`php artisan migrate --force`), rebuild configuration and refresh API/worker runtimes through the existing release procedure. Build/deploy the frontend. Leave the ordinary admission policy intact.
2. Complete prerequisites 1–4. Enable the existing `NIUM_PAYOUT_FX_ENABLED` only in the authorized environment. In Origin Wallet enter **USD 5**, select Andy and a truthful supported purpose, and use the existing preparation workflow to save one draft and explicitly obtain its fresh FX lock. Reuse its client reference for recovery.
3. Stage the authorization locally, before requesting maker approval. Substitute real reviewed values; `VERIFIED_CEILING` is **not** a guessed test buffer:

   ```bash
   php artisan nium:authorize-payout-pilot TRANSFER_ID FUNDING_LEDGER_ID VERIFIER_USER_ID VERIFIED_CEILING /secure/review-document \
     --funding-reference=EXACT_POSTED_CREDIT_REFERENCE \
     --charge-bound-reference=ORIGINAL_BINDING_CHARGE_BOUND_REFERENCE \
     --attest
   ```

   This command performs local checks and reserves already funded money. It calls no provider endpoint and grants no payment approval. Retain the reviewed document and original funding/NIUM charge-bound evidence securely. Keep the printed authorization UUID.
4. Set `NIUM_PAYOUT_PILOT_AUTHORIZATION_ID` to that UUID and `NIUM_PAYOUT_PILOT_ENABLED=true`; rebuild configuration and refresh payment runtimes. Refresh the existing Origin preparation screen. It must show the separately funded reserve while fees/total debit remain unconfirmed. The maker explicitly submits that review for Admin approval. If balance freshness or FX expiry fails, stop; never change timestamps/audits to bypass it.
5. An active independent Admin uses the existing Approve action. **This action dispatches the real Remittance POST.** Do not add another Submit action. Disable the pilot flag after the single attempt and refresh configuration/runtimes; keep the authorization and ledger history.
6. Follow existing no-retry outcome recovery and read-only reconciliation to authoritative payout status, actual USD principal/fees and Andy's SGD receipt. Review any extra debit or ceiling breach separately. Keep unused charge reserve held until an audited financial close authorizes its release. Do not roll back the migration while an authorization exists.

## Files changed in this increment

Backend (`api_origin_wallet_sgd_phase3`):

- `app/Console/Commands/AuthorizeNiumPayoutPilot.php` — local audited staging command.
- `app/Models/NiumPayoutPilotAuthorization.php` — guarded durable permission.
- `database/migrations/2026_10_30_230000_create_nium_payout_pilot_authorizations_table.php` — unique transfer/funding/reserve/exercise identities and consumption history.
- `app/Services/Nium/NiumPayoutPilotPolicy.php` — exact scope/binding, verified funding reserve, one-use consumption and fee allocation.
- `app/Services/Nium/NiumPayoutFinancialControls.php` — selected pilot branch; ordinary quote checks preserved.
- `app/Services/Wallet/LedgerService.php` — explicit principal reservation field and atomic pilot consumption.
- `app/Services/Nium/NiumService.php` — existing final POST check accepts the explicit reservation field.
- `app/Services/Nium/NiumSgdLocalPayoutWorkflow.php` — maker review/projection without pretending fees are confirmed.
- `app/Services/Nium/NiumSgdLocalPayoutAmounts.php` — unknown fee/total-debit projection and reserve breach fields.
- `app/Services/Nium/NiumPayoutDebitReconciliation.php` — separate actual-fee reserve accounting and breach flag.
- `config/services.php` — disabled-by-default pilot activation and exact authorization UUID.
- `tests/Feature/NiumPayoutPilotPolicyTest.php` — focused scope, one-use, funding/expiry, unknown-outcome and fee-accounting coverage.
- `tests/Feature/NiumRemittanceExecutionPostgresTest.php` — one real row-lock concurrency test for competing pilot approvals.
- `docs/nium-usd5-principal-only-pilot.md` — this report/runbook.

Frontend (`origin-wallet-platform_sgd_phase3`):

- `src/services/moneyMovementService.ts` — typed pilot authorization and reserve breach projection.
- `src/pages/account/SgdLocalPayoutWorkflow.tsx` — permit explicitly authorized principal review with unknown fees; show separate reserve.
- `src/pages/account/SgdLocalPayoutWorkflow.test.tsx` — authorized review and changed-principal rejection.
- `src/pages/account/SgdLocalAmountBreakdown.tsx` — visible actual-charge ceiling breach.

## Focused validation

All checks below passed against the final candidate:

| Check | Result |
| --- | --- |
| Guarded offline SQLite selection: pilot policy, SGD execution, actual-debit reconciliation, maker workflow, amount presentation and Remittance execution safety | **145 tests, 1,188 assertions** |
| Disposable PostgreSQL execution/concurrency suite, including competing pilot Admin approvals | **19 tests, 310 assertions** |
| Frontend workflow and amount-breakdown selection | **44 tests across 2 files** |
| Pint on the 13 PHP files changed in this increment | Pass |
| ESLint on the 4 frontend files changed in this increment | Pass |
| Frontend production build/prerender | Pass; existing bundle-size and Browserslist warnings |
| Both repositories' whitespace checks | Pass |
| Local staging command registration/help | Pass |

The backend selection used `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=` and `vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php` with `NiumPayoutPilotPolicyTest.php`, `NiumSgdLocalExecutionTest.php`, `NiumPayoutDebitReconciliationTest.php`, `NiumSgdLocalPayoutWorkflowTest.php`, `NiumSgdLocalPayoutPresentationTest.php` and `NiumRemittanceExecutionSafetyTest.php` from `tests/Feature/`.

The PostgreSQL run used the existing `tests/PayoutPostgresBootstrap.php` guard and `NiumRemittanceExecutionPostgresTest.php`, solely against the disposable `/tmp/origin-wallet-p0-pg` socket, port 55439, database `nium_p0_execution_test`. The cluster had TCP disabled and was **stopped after testing**. Both bootstraps reject unfaked HTTP. Frontend validation used `npm test -- src/pages/account/SgdLocalPayoutWorkflow.test.tsx src/pages/account/SgdLocalAmountBreakdown.test.tsx`, focused ESLint and `npm run build`.

This is a focused regression result, not a full backend-suite claim. Tests cover default-off admission, one exact authorized POST, unchanged principal/audit wire semantics, independent maker/checker, copied/changed intent denial, funding/reserve tampering, expiry/revocation, consumed authorization, duplicate and concurrent approval, unknown-outcome hold retention, delayed actual fee reconciliation, duplicate fee accounting and visible ceiling breach. No fixture constitutes live NIUM acceptance, actual reserve funding or verified Production pricing.
