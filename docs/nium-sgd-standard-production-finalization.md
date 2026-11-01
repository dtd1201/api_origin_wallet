# SGD LOCAL standard transfer finalization

Review date: 1 November 2026 (Asia/Bangkok). Repository: `api_origin_wallet_integration`; requested branch: `integration/nium-sgd-standard-flow`. No Git operations, tests, builds, database commands, deployments or NIUM requests were executed for this task. This report describes source inspection and implementation only. Historical test counts in other documents are not validation of this increment.

**Release decision: NO-GO.** The ordinary Customer → independent Admin → one NIUM submission → automatic reconciliation path exists. This increment fixes two synchronization gaps. Financial admission and automatic failure/return accounting remain incomplete for production, as detailed below.

## A. Exact blockers

| Finding | Disposition |
| --- | --- |
| `NiumConfiguredRemittancePricing::quote()` always returns `unavailable`, including after verified client configuration/customer inspection. Consequently `NiumPayoutFinancialControls::assertFor()` blocks the standard SGD Review before a new preparation/FX request, and blocks customer acceptance, checker authorization and dispatch. | Remains blocked. The business owner's current NIUM zero-fee confirmation and Origin's zero fees are accepted as supplied business facts. They do not supply the applicable NIUM source-wallet debit contract described in D. No zero-fee provider quote, verification capture or effective pricing assignment was fabricated. |
| Both audit polling and webhook normalization recognize `RETURNED`, but Singapore's documented `RETURN` fell through to `pending`. | Fixed for USD→SGD only. `RETURN` maps to the existing `failed` lifecycle; existing financial hold protections still apply. |
| Scheduled reconciliation required a nonempty saved system reference, so a crash after successful response capture but before reference persistence required an existing recovery API action even when evidence was already available locally. | Fixed for dispatched USD→SGD `submitting`/`submission_unknown` operations. The scheduler invokes the existing captured-outcome verifier before status GET and wallet-activity reconciliation. Invalid/uncaptured evidence remains unresolved and produces a command failure; there is no POST retry. Failed recovery attempts rotate through the bounded batch using `updated_at`. |
| Dispatched SGD failure/cancellation/rejection retains its hold. The debit reconciler posts only for `completed`, accepts settled debit events, and deliberately rejects credit/reversal/refund events. A return following `completed` also conflicts with the existing terminal-status guard. | Remains blocked for the requested automatic complete lifecycle. Implementing refund or release amounts requires an applicable provider contract and authentic sanitized examples for rejection/no-debit, returned principal, reversal linkage/currency/amount/settlement and any linked fee reversal. A status or absent debit row cannot establish a refund. The new RETURN mapping does not solve this accounting requirement. |
| Source-level regressions for this increment have not been executed, and deployed scheduler/configuration/webhook/database behavior has not been inspected. | Operator validation and release acceptance remain outstanding. |

An uncaptured submission with no provider reference is an exceptional unresolved outcome, not permission to submit again. Existing HK USD 5/10 success is the regression behavior supplied by the task; it is not new evidence for SGD debit or refund semantics. The existing 20-page/2,000-row full-wallet accounting scan also fails closed beyond that bound; its suitability for production wallet history must be validated before release.

## B. Files modified

| File | Purpose |
| --- | --- |
| `app/Services/Nium/NiumTransferService.php` | Pass transfer context to status normalization and recognize SGD `RETURN`. |
| `app/Services/Nium/NiumWebhookService.php` | Apply the same SGD-only status interpretation to authenticated webhooks. |
| `app/Console/Commands/ReconcileNiumTransfers.php` | Select dispatched SGD operations missing references, reuse captured-response recovery and then the existing sync path; rotate unsuccessful recovery attempts without changing their financial state. |
| `tests/Feature/NiumPayoutDebitReconciliationTest.php` | Add the regressions in E using the existing synthetic transport/pricing fixtures. |
| `docs/nium-sgd-standard-production-finalization.md` | Record the lifecycle trace, remaining blockers, validation commands and release/rollback conditions. |

No customer/admin route, request field, response field or frontend source was changed. HK payload construction, approval logic and ledger policy were left intact. Existing non-SGD status normalization is preserved. The business policy and production pricing adapter were not changed to force admission.

## C. Lifecycle trace and final sequence

The HK USD 5 and USD 10 baseline follows these existing source paths:

1. Customer `POST /api/user/users/{user}/transfers` validates ownership, eligible provider account, beneficiary, supported purpose and source-funded USD intent. `NiumTransferPolicy::normalizeCreate()` preserves USD principal, no FX lock and SWIFT SHA. Client reference idempotency returns the same instruction or rejects a conflict. `TransferApprovalService::initialStatusFor()` applies the existing approval configuration.
2. Independent Admin `POST /api/admin/transfers/{transfer}/approve` calls `TransferApprovalService::approve()`. Maker/checker guards run under the transfer lock; approval commits before dispatch. An `approval_required` NIUM transfer auto-submits through `ProviderTransferManager`.
3. `NiumTransferService::submitTransfer()` revalidates eligibility, creates a durable operation key and owned ledger reservation atomically, and sets `submitting`. `NiumService::post()` consumes dispatch authority once and passes the UUID as `x-request-id`. The HK body retains `sourceAmount`, USD/USD, SWIFT, SHA and reference fields. Transport does not automatically retry or follow redirects for remittance.
4. Accepted responses with a system reference become `pending`; acceptance is not payment completion. Ambiguous responses/exceptions become `submission_unknown` with funds held. Definitive rejection follows the existing HK terminal/release path. A webhook may win the race; late submit responses cannot replace a resolved outcome.
5. Authenticated webhooks and `nium:reconcile-transfers` apply status under the transfer lock and reject stale regressions/conflicting terminal outcomes. HK completion settles the original principal reservation once; failures release it according to the existing baseline policy. Wallet-data synchronization refreshes provider balance/transaction snapshots while preserving audited local balances.

SGD uses the same operational handoff, with the already-existing FX/consent safeguards:

1. The existing Customer Review API calls `NiumStandardTransferAdapter::review()`. It validates ownership/eligibility, checks financial admission before acquiring a new lock, and uses `NiumSgdLocalPayoutWorkflow::prepareReview()` to create/reuse a durable preparation, validate funding, acquire a bound legacy FX lock and return an encrypted review token. This is the existing review interaction, not an added business step.
2. Customer Submit at the standard create endpoint validates that token and recorded instruction, then persists customer acceptance and `approval_required`. It does not reserve funds, approve or send a remittance. Replaying a matching accepted submission returns the recorded receipt. The customer `/submit` dispatch endpoint rejects this workflow.
3. Independent Admin approval is required even if the global approval switch is disabled. Approval rechecks the exact customer receipt, receiving account, principal, purpose, financial terms, source wallet and fresh FX audit/rate/expiry. It records approval and automatically enters the existing manager. There is no second customer/admin dispatch action on the normal path.
4. Backend atomically claims one operation, reserves the approved debit, consumes the bound `auditId`, and sets `submitting`. Final dispatch again validates the approved snapshot, exact request and owned hold. SGD sends the registered provider beneficiary ID, `payout.sourceAmount`, `sourceCurrency=USD`, numeric legacy `auditId`, selected purpose and source of funds. No SWIFT fee/method fields, destination amount or modern quote ID are introduced.
5. NIUM acceptance produces `pending`; unknown outcomes keep the original hold and operation. Webhooks update lifecycle/transaction projection. The scheduler now also recovers a missing reference when the existing encrypted successful response proves it, then continues GET-based reconciliation. Genuinely missing/unverifiable captures remain unknown, without a replacement lock or remittance retry.
6. Status polling additionally captures authenticated source-wallet activity. A completed payout with the exact bound settled USD principal debit consumes the owned reservation once; separately evidenced charges append adjustments once. Provider snapshots do not double-apply a debit. Status alone cannot settle SGD accounting. The scheduler revisits terminal SGD transfers for delayed evidence and charges. Returns/failures retain unresolved accounting as described in A.

The scheduler definitions are already present in `routes/console.php`: transfer reconciliation every five minutes and wallet-data sync every ten minutes, behind their existing configuration switches. No additional scheduler or workflow was introduced. This normal-path sequence remains conditional on resolved financial admission; it is not a claim that production currently admits SGD.

## D. Financial Admission and remaining evidence

Current business terms are zero NIUM fees and zero Origin Wallet fees, as supplied by the business owner. The existing principal-only policy is consistent with those terms. No pricing engine, pilot, arbitrary reserve or risk-acceptance step was added.

The exact missing fact is that, for the configured NIUM client/customer/source USD wallet and this registered SGD LOCAL source-funded request, **`sourceAmount=P` results in total USD wallet debit exactly `P`, with no separate additional payout/FX/other source-wallet debit**. The saved source-amount/legacy-audit confirmation specifies request construction; the rate-lock response is not an all-in debit quote. A missing fee row, null customer segment/default plan candidate or stored zero cannot complete that mapping. The locked `fx_rate` is already net of markup and must be used unchanged.

An applicable NIUM contract confirmation establishing this debit rule and the scope/effectiveness of the zero-fee terms can resolve the evidence gap; a new pricing engine, special document bundle or guaranteed final beneficiary credit is not required. Such confirmation must be represented truthfully in the existing admission adapter and bound authorization, rather than manufacturing `charges_complete`, provider verification or transaction evidence from a business expectation. No available confirmed debit contract was found to implement that mapping in this task. Accordingly admission remains closed. Future fee changes must invalidate reviewed terms rather than silently increasing debit.

Automatic failure/return accounting separately needs the real event contract described in A. Successful settled debit evidence cannot establish a returned credit. No account balance difference or remittance status should be converted into a fabricated refund.

## E. Regression sources

Added to `NiumPayoutDebitReconciliationTest`:

- SGD RETURN through polling and authenticated webhook, duplicate delivery, terminal scheduler revisit and retention of the original hold.
- RETURN after PAID through both channels preserves the settled ledger and exposes the existing terminal conflict instead of inventing a refund.
- The same token retains the existing HK USD behavior through both channels.
- Scheduled recovery of captured success from `submitting` with null reference and `submission_unknown` with empty reference; it continues through one settlement even after original FX/pricing expiry and repeated reconciliation.
- Missing response, invalid hash, mismatched captured request principal and changed wallet cannot recover, release or repost.
- An unrecoverable missing reference rotates out of a batch with limit one so another known-reference payout can be reconciled.

Existing `NiumStandardTransferAdapterTest` covers Customer acceptance → independent checker → single dispatch, global approval disabled, changed instructions, unknown outcomes and exact HK USD 5/10 payload/settlement regressions. Existing execution, authorization, debit, webhook and command tests remain the baseline. New and existing fixtures are synthetic, not provider proof. **No tests, syntax checks or runtime checks were run.**

## F. Operator-only validation commands

These commands were not executed. Use a review checkout with test dependencies and a disposable test environment. The guarded test bootstrap requires `testing` and in-memory SQLite; feature tests create/migrate that disposable database internally. Do not use production database credentials or cached production configuration. Network access should be disabled for this offline regression run.

```bash
cd /home/dtd1201/projects/api_origin_wallet_integration

php -l app/Services/Nium/NiumTransferService.php
php -l app/Services/Nium/NiumWebhookService.php
php -l app/Console/Commands/ReconcileNiumTransfers.php
php -l tests/Feature/NiumPayoutDebitReconciliationTest.php

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php \
  tests/Feature/NiumPayoutDebitReconciliationTest.php \
  tests/Feature/NiumStandardTransferAdapterTest.php \
  tests/Feature/NiumSgdLocalExecutionTest.php \
  tests/Feature/NiumConfiguredRemittancePricingTest.php \
  tests/Feature/NiumPayoutAuthorizationSecurityTest.php \
  tests/Feature/NiumPayoutFxLockContractTest.php \
  tests/Feature/NiumPayoutFxSafetyTest.php \
  tests/Feature/NiumRemittanceExecutionSafetyTest.php \
  tests/Feature/NiumSwiftCheckerAuthorizationTest.php \
  tests/Feature/NiumTransferServiceTest.php \
  tests/Feature/NiumWebhookApiTest.php \
  tests/Feature/ReconcileNiumTransfersCommandTest.php
```

After an independently authorized release and resolved blockers, operators can validate existing scheduling and run a bounded reconciliation. The reconciliation command below makes authenticated provider reads and updates local state; it is not an offline check and is not authorized for execution by this task:

```bash
php artisan schedule:list
php artisan nium:reconcile-transfers --limit=10
```

Review production-database JSON query compatibility, real row-lock concurrency and the existing PostgreSQL safety harness separately in an authorized disposable environment. SQLite tests alone cannot establish production concurrency. No build, database administration, Git or deployment commands are included or executed here.

## G. Deployment checklist and rollback

- Keep production SGD execution closed until D is resolved, the existing adapter has a defensible debit mapping, and automatic failed/returned payout accounting has been implemented against verified event semantics.
- Obtain code review and operator validation results, including the exact HK USD 5/10 body/approval/ledger regressions and production-engine concurrency. This increment has no schema migration or new environment variable.
- Preserve original customer acceptance, checker snapshots, FX audits, operation keys, encrypted request/response evidence and append-only ledger entries. Confirm production owns verified customer/wallet/beneficiary identities and a source balance covering the authorized debit.
- Confirm ledger controls remain enabled; standard flow uses no pilot authorization (`NIUM_PAYOUT_PILOT_ENABLED=false`). Existing FX and automatic synchronization switches must be set deliberately: `NIUM_PAYOUT_FX_ENABLED`, `NIUM_TRANSFER_RECONCILIATION_ENABLED`, `NIUM_WALLET_DATA_SYNC_ENABLED`. Validate the cron/scheduler, shared overlap-lock store and authenticated webhook configuration. Leave HK reconciliation operating during any SGD pause.
- Confirm the exact wallet-activity endpoint, event linkage/settlement fields, read capacity/history bound and actual production response formats. Monitor command failures, old unknown submissions, retained holds, unexpected charges and financial-evidence lag; a completed lifecycle is not sufficient proof of accounting completion.
- Deployment/money movement requires a separately authorized release. Validate resulting status, exact USD debit, zero fees and settled reservation using real evidence. Do not present synthetic tests or this source review as provider acceptance.
- If rollback is required, stop new SGD admission and return application code to the previously reviewed artifact. Do not delete transfers, clear operation keys, unconsume FX audits, release unknown holds or repost remittances. Continue compatible status/accounting reconciliation for already dispatched transfers. The existing FX switch alone does not disable all SGD preparation/submission interactions or financial admission; do not rely on it as a complete kill switch. Use the established release controls.
- Rolling back these fixes restores the old missing-reference and RETURN limitations; retain the new audit evidence and provide an authorized operational recovery path for existing operations. Ledger/evidence writes already committed remain valid records and are not undone by reverting code.

## H. Final assessment

**NO-GO for production SGD execution.** Two code-level sync gaps were repaired. The fee/debit admission contract, verified automatic failure/return financial handling, and operator/runtime validation remain unresolved. Customer/Admin contracts and the HK operational implementation were preserved at source level. No runtime PASS or production/provider verification is claimed.
