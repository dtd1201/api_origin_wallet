# NIUM Remittance P0 execution safety

30 October 2026. This increment fixes reservation ownership and Remittance dispatch safety. It does not enable SGD LOCAL. `NiumSgdLocalPayoutGate` remains unchanged and unconditionally denies financial admission.

## Operation and reservation ownership

The NIUM provider service validates and claims a transfer under its row lock. One transaction stores a new `nium-<UUID>` operation identity, execution metadata, an owned ledger hold and `submitting` status. A missing or disabled reservation ledger rolls back the claim. An existing operation identity cannot be reused even if a caller resets the status. The shared manager does not independently reserve or release NIUM funds when a competing attempt throws.

Ledger settlement/release reloads the locked transfer and compares the caller's captured operation identity, current terminal state, hold owner and terminal execution proof. It refuses opposite terminal entries and incomplete reservations. Accounting uses the original held amount. A pre-dispatch legacy hold can be adopted under the transfer lock; historical ownerless terminal holds retain their existing reconciliation path with current-identity/state and conflicting-entry checks.

Administrative direct edits/deletion are refused for claimed NIUM operations and controlled SGD drafts. Customer cancellation rechecks state and absence of an operation claim under the lock. Provider reconciliation and webhooks attach terminal proof under the transfer lock. Late submit responses cannot overwrite a terminal result.

## One dispatch attempt

Before the actual Remittance POST, `NiumService::post()` requires no open outer database transaction, verifies the durable UUID, submitting state and owned live hold, and atomically consumes `dispatch_started_at`. That transaction commits before HTTP. Only one caller can consume that identity. A crash after consumption can leave an uncertain operation even if no bytes were sent; safety requires reconciliation rather than another attempt.

The operation UUID is sent as `x-request-id`. Existing request/response evidence and correlation remain in place. NIUM `transfer_money` POST disables redirects and uses one HTTP attempt. Timeout, transport/evidence exceptions, redirects, 408/409/425/429/5xx and success without a system reference retain the reservation in `submission_unknown`. Other client rejection responses preserve the existing USD/SWIFT failure behavior and release only through the owned terminal transaction. There is no automatic Remittance retry. Unrelated NIUM API transport behavior is unchanged.

Known-reference reconciliation binds the queried reference and operation identity before applying status. The command includes stranded `submitting` operations with a reference. Missing-reference operations cannot be discovered by guessing a status URL or repeating the POST.

## SGD approval and financial lifecycle

An independent checker is required for SGD even when global approval controls are disabled. The existing authorization snapshot binds the stored beneficiary, USD 5 principal, purpose, provider context and fresh FX audit/rate/expiry. Expired locks cannot authorize dispatch and are never refreshed automatically.

New SGD approval persistence and reservation remain blocked. No guessed fee or principal-only SGD reservation is introduced. The existing fee presentation continues to show provider charges and all-in debit as unknown. The following inputs are still required:

1. Authoritative effective pricing for the same client/environment and GREENTECH customer, including active segment/default/override assignment, LOCAL `REMIT_BANK_FEE`, currencies and USD 5 rules, FX markup allocation, other applicable charges and who pays them. A missing fee row or stored zero does not prove zero.
2. Confirmed all-in USD debit and whether any charge is deducted from beneficiary proceeds. The narrow CUG can admit only an evidenced zero USD fee and debit USD 5; nonzero or changed terms require a separate review.
3. Authoritative registered-beneficiary LOCAL supplementary/remitter requirements and truthful purpose applicability for Corporate-to-Individual. Existing sourceAmount=5, omission of destinationAmount and legacy auditId confirmations remain credited; no speculative payload fields are added.
4. Supported authoritative recovery evidence/lookup for uncertain FX and missing payment references. Saved local correlation alone does not prove acceptance or rejection.

After those inputs are verified, a separately reviewed implementation must bind pricing evidence/version and all-in debit to a persisted approval, reserve that debit atomically, finish the SGD-specific policy branch and controller/UI success path, and implement evidence-backed recovery/review/reapproval. Removing the immutable denial or changing an environment flag alone is unsafe and is outside this task.

The protected `sgd-local-recovery-review` action stores an idempotent local review request for uncertain FX, a missing payment reference or expired approved FX. It never changes an unknown outcome, releases funds, creates another lock or sends a payment. The UI exposes this escalation and suppresses blind reconciliation without a reference. Outcome resolution and end-to-end UI completion remain blocked pending authoritative evidence; a review request is not financial recovery.

## Verification and acceptance

The inherited stale-submission race was reproduced before the fix: an unknown USD 10 submission lost its hold when the stale attempt failed. The regression now passes. Final focused backend checks pass 395 tests / 2,227 assertions; disposable PostgreSQL concurrency checks pass seven tests / 104 assertions. They cover competing submissions/approvals, accepted/rejected/unknown responses, terminal reconciliation conflicts and shared-balance reservations. Offline transport tests cover 301/302/307/308, timeout, durable correlation, dispatch-token reuse and the outer-transaction guard. Synthetic responses are test fixtures, not NIUM contract evidence.

The frontend suite passes 283 tests in 24 files. Formatting, PHP syntax, frontend lint and both production builds are checked separately in the accompanying review artifacts. Existing backend problem classes reproduce the prior 28 errors, 91 failures and one risky test; this is not a green full-suite claim.

P0 code safety: accepted by these offline checks. SGD LOCAL business contract: blocked. Production release: NO-GO. No production database/configuration change, live NIUM request, real Remittance POST, commit, push or deployment was performed.
