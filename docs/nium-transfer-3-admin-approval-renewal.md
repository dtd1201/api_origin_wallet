# Transfer 3: explicit pilot renewal before one-click Admin approval

This local source change is based on `de2c88b`, branch `release/nium-sgd-local-usd5`.
Production remains **HOLD**. No provider calls, database operations, test runs,
activation, deployment, commits or pushes were performed for this change.

## Supported sequence

The existing Admin `POST /api/admin/transfers/3/approve` already approves and
dispatches in one intentional action when its prerequisites pass. It retains
the independent checker, current maker review, financial admission, current
owned source-wallet balance, atomic principal hold, operation fence and single
provider submission. A timeout remains `submission_unknown`; another Approve
does not repeat the provider POST. Existing outcome recovery and actual-debit
reconciliation remain the resolution paths.

An expired FX/risk acceptance cannot be repaired by that approval action:

1. The maker explicitly restarts the expired undispatched review and requests
   a fresh FX lock through the existing workflow, retaining the same Transfer ID.
2. An active independent Admin with `transfers.approve` reviews the fresh FX and
   explicitly accepts the unknown, uncapped additional NIUM charges again.
3. The maker explicitly reviews the new FX and pilot terms and requests approval
   with the current financial-terms token. A previous token is rejected.
4. The independent Admin uses the existing Approve action once, before expiry.

The Admin cannot create the maker's review or silently replace its rate. The
remaining UI integration requires an authorized Admin frontend checkout; only
the specified backend worktree was changed. Full one-action preparation from
the expired state is blocked by these necessary independent confirmations.

## Renewal API contract

The existing Admin transfer detail response adds `pilot_authorization_renewal`
for Transfer 3 and an authorized viewer. When renewal is possible it includes
`renewal_allowed`, the original authorization UUID, predecessor revision, exact
current FX quote/rate/expiry and an actor-bound `confirmation_token`. Blocked
prerequisites return a reason. Previewing never acquires FX or dispatches.

The UI must show the current FX terms and this explicit risk meaning: additional
NIUM charges are unknown, uncapped and not reserved. Expected USD 0 is an
internal expectation only. The total wallet debit and provider fee remain
unverified/null. Accepting this policy does not verify pricing.

`POST /api/admin/transfers/3/pilot-authorization/renew` requires:

```json
{
  "request_id": "<new UUID retained for a repeated click>",
  "confirmation_token": "<64-character token from current preview>",
  "authorization_reference": "<reference for this new explicit acceptance>",
  "accept_uncapped_charges": true,
  "risk_acknowledgement": "additional_nium_charges_are_unknown_uncapped_and_not_reserved"
}
```

No client-supplied binding or expiry is accepted. The service locks the transfer,
configured authorization and owned balance. It only renews an expired, unrevoked,
unconsumed `one_time_uncapped` authorization for Transfer 3, user 12, beneficiary 9,
exactly USD 5 to SGD LOCAL. The original payment intent and provider wallet must
remain unchanged. Fresh owned FX, current balance covering principal, enabled
ledger, no operation/external reference, no transfer ledger entries and no
nonhistorical approval are required. Existing pilot activation gates are unchanged.

An identical repeated request produces one acceptance receipt. A reused UUID
with changed terms, an old preview, or replacement of a still-active acceptance
is rejected. Each receipt records its own UUID, predecessor, request UUID,
authenticated authorizer, acceptance reference/time/basis, risk acknowledgment,
full transfer/FX binding and FX-bound expiry. It is appended under
`verification.one_time_renewals`; the original binding, expiry, verifier,
verification fields and historical approvals/reviews are preserved. Financial
terms include the new revision, requiring a fresh maker review. The configured
root UUID remains the single consumption fence. History is validated before use.

Actual provider evidence remains separate. Existing reconciliation records
actual principal, fees and observed debit after expiry without promoting the
USD 0 expectation into actual fees or rewriting the principal. Renewal cannot
change the original source wallet used by that reconciliation.

## Migration and validation

No new migration is required: renewal receipts use the existing authorization
JSON column. This assumes the baseline's existing pilot migrations are present;
no migration or configuration change was performed.

Only PHP syntax and whitespace checks were performed. Regression tests were
added for renewal, history preservation, stale terms, expiry, insufficient or
stale balance, scope/permission failures, consumed FX/grant, existing operation
and ledger entries, duplicate clicks, unknown outcomes and delayed fee
reconciliation. The existing guarded PostgreSQL suite gains a two-worker race
for renewal followed by approval. All provider fixtures are synthetic and HTTP
is blocked unless faked; none establishes real NIUM pricing or live readiness.

Run from the backend worktree, using disposable test databases only:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php \
  tests/Feature/NiumPayoutPilotRenewalTest.php \
  tests/Feature/NiumPayoutPilotPolicyTest.php \
  tests/Feature/NiumSgdLocalExecutionTest.php \
  tests/Feature/NiumPayoutDebitReconciliationTest.php \
  tests/Feature/NiumSgdLocalPayoutWorkflowTest.php \
  tests/Feature/NiumProvisionalPayoutPricingTest.php

# Requires the existing disposable cluster, socket and database; recreates its schema.
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=/tmp/origin-wallet-p0-pg \
DB_PORT=55439 DB_DATABASE=nium_p0_execution_test DB_USERNAME=dtd1201 DB_PASSWORD='' DB_URL='' \
vendor/bin/phpunit --bootstrap tests/PayoutPostgresBootstrap.php \
  tests/Feature/NiumRemittanceExecutionPostgresTest.php

vendor/bin/pint \
  app/Services/Nium/NiumPayoutPilotPolicy.php \
  app/Http/Controllers/Api/Admin/TransferController.php \
  app/Http/Resources/Admin/TransferDetailResource.php \
  routes/admin.php \
  tests/Concerns/CreatesRenewableNiumPilotFixtures.php \
  tests/Feature/NiumPayoutPilotRenewalTest.php \
  tests/Feature/NiumRemittanceExecutionPostgresTest.php
git diff --check
```

Remaining dispatch prerequisites are successful user-run validation, frontend
integration, a fresh explicit FX/maker review and independently accepted risk
revision, current separately synchronized balance and the existing operational
activation requirements. No live prerequisite was verified in this task.
