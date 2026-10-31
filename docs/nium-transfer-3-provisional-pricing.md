# Transfer 3 provisional pricing representation

Baseline: `e98bf13`, branch `release/nium-sgd-local-usd5`.

The user-approved task "Origin Wallet — Scoped Provisional NIUM Pricing Policy" supplies internal assumptions: DEFAULT applies to the intended customer, the 0.13% FX markup is already in the locked rate, expected additional USD fees are zero and Transfer 3's expected USD wallet debit is 5. These statements are not NIUM-authenticated evidence or an authorization to dispatch, reserve unbounded fees or change the locked FX rate.

Existing `business_policy`, `expected_fee` and `nium_actual_debit` already distinguish internal expectations from actual accounting. They do not record this particular policy's version, exact binding and explicit acknowledgement. The addition supplies only that missing metadata; it reuses existing actual-debit reconciliation.

## Recording and scope

`POST /api/user/users/{user}/transfers/3/sgd-local-provisional-pricing` uses existing token authentication, route ownership and profile middleware. The request must contain:

```json
{
  "policy_id": "transfer-3-provisional-pricing-v1",
  "fx_quote_id": "<current attached FX quote ID, sent as an integer>",
  "confirm_provisional_pricing": true
}
```

The quote ID is supplied by the existing transfer response; it is not inferred from historical Production IDs. The example is a request shape, not an instruction to invoke the endpoint now.

Only the active original maker can acknowledge an undispatched Transfer 3 draft, with USD 5 principal, the original NIUM USD/SGD LOCAL intent and a matching current FX lock. Existing scope, ownership, provider integrity and FX contract validation apply. A mismatched quote ID, changed intent/account, expired/consumed lock, payment operation, external payment reference, started approval or existing transfer ledger entry rejects the action.

The method locks the transfer row and appends to `raw_data.nium_provisional_pricing_history`. It records the complete versioned internal policy, provenance, original account/beneficiary/principal/purpose/FX snapshot, the authenticated maker's explicit acknowledgement and timestamp, uncertainty, reconciliation requirement and expiry. No transfer status, financial terms, approval row, provider evidence, FX quote, ledger entry or environment configuration is changed. Duplicate acknowledgement of the same snapshot preserves the original record. New locks require a new explicit confirmation, preserving previous records.

Validity ends at the acknowledged FX lock's expiry. This is a conservative local validity rule, not a provider pricing expiry guarantee. A changed binding is inapplicable. The application-key HMAC protects the internal record from alteration, with canonical object ordering for PostgreSQL JSON. It is not a NIUM signature. Application-key rotation makes old seals invalid; it never promotes them to provider evidence or alters their stored bytes.

## Presentation and accounting

The workflow response adds `payout_workflow.provisional_pricing` only when a record exists for Transfer 3. Its `expected` namespace and `provider_verified=false` / `financial_authorization=false` labels remain distinct from `payout_amounts.transfer_fee`, `total_wallet_debit` and `actual_debit`. An invalid seal returns an invalid marker without expected values. Expired or mismatched records remain historical, explicitly labelled.

No pricing-adapter interface, financial admission, maker-checker snapshot, pilot authorization, dispatch, replay or reconciliation implementation changes. Provisional metadata cannot produce confirmed pricing or enable a pilot. Real fees and total debit remain unavailable until their existing authoritative paths establish them.

Existing reconciliation persists authenticated observed principal, fees, wallet debit and evidence references, including delayed fee adjustments and unexpected-charge states. The new read-only comparison uses that result only when its original dispatched approval matches the acknowledged snapshot. It reports an observed difference from the expected USD 0 fees / USD 5 debit; a currently empty fee scan is never complete-charge proof. Historical comparison remains possible after FX expiry. Expected figures never populate actual figures or settle the ledger, and repeated reconciliation retains the existing idempotency rules.

## Validation to run separately

No test, build, database operation, provider request or Production access was performed for this change. The regression sources cover explicit acknowledgement, unchanged financial admission, scope/ownership isolation, mismatches, expiry/restart history, quote and metadata integrity, object-key ordering, actual-fee discrepancy reporting and repeated reconciliation. Their transport and positive execution pricing are explicitly synthetic fixtures, not provider proof.

Run only in the isolated in-memory test environment:

```bash
cd /home/dtd1201/projects/api_origin_wallet_sgd_phase3
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
php vendor/bin/phpunit --configuration phpunit.xml \
  tests/Feature/NiumProvisionalPayoutPricingTest.php \
  tests/Feature/NiumConfiguredRemittancePricingTest.php \
  tests/Feature/NiumSgdLocalExecutionTest.php \
  tests/Feature/NiumSgdLocalPayoutWorkflowTest.php \
  tests/Feature/NiumPayoutPilotPolicyTest.php \
  tests/Feature/NiumPayoutDebitReconciliationTest.php

php vendor/bin/pint --test \
  app/Services/Nium/NiumProvisionalPayoutPricing.php \
  app/Services/Nium/NiumSgdLocalPayoutWorkflow.php \
  app/Http/Controllers/Api/User/NiumSgdLocalPayoutController.php \
  routes/user.php \
  tests/Feature/NiumProvisionalPayoutPricingTest.php
```

Production remains HOLD. This patch neither records an acknowledgement on Production Transfer 3 nor changes its state. Applicable authenticated customer pricing, complete charges and source-wallet debit allocation remain prerequisites for ordinary admission. There is no new UI in this scoped backend change.
