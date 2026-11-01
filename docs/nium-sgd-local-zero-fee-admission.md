# SGD LOCAL business-confirmed zero-fee admission

1 November 2026 (Asia/Bangkok). Source implementation only. No tests, builds, migrations, database commands, Git mutations, deployments or NIUM requests were executed. Historical test results do not validate this increment.

## A. Root cause and fix

`NiumConfiguredRemittancePricing` intentionally cannot turn client fee configuration into a complete provider debit quote. `NiumPayoutFinancialControls` previously accepted only its verified-provider contract or the existing pilot path. The Standard Transfer Adapter therefore rejected the normal SGD Review despite the newly supplied complete business terms.

The business owner now explicitly confirms NIUM fee USD 0, Origin fee USD 0, total NIUM source-wallet debit exactly equal to the submitted USD principal, and no additional debit. This implementation accepts those current terms as an **operator-activated business authorization**, without promoting them to independently verified API evidence.

The minimal internal contract adjustment is a separate `financial_terms.admission_basis=business_owner_confirmation` and a persisted `business_confirmation` record. The provider pricing interface and `NiumConfiguredRemittancePricing` remain unchanged. This policy does not return a fabricated provider quote, `charges_complete`, `provider_verified`, pricing assignment, or API capture. Provider price inspection may still report unavailable while this separately authorized business policy permits admission.

`NiumSgdLocalZeroFeePolicy` validates the exact existing registered SG/SGD/LOCAL source-funded USD intent and the pinned provider environment/client/customer/wallet. It validates an explicit confirmation reference, confirmer, version and effective period. It computes `total_wallet_debit` directly from the exact normalized USD principal, with no float arithmetic or configurable nonzero fee. Scope/terms are fixed to this flow. Invalid, expired or malformed activation fails closed; it cannot fall through to a pilot or another admission source while enabled. A disabled policy retains the previous behavior, including the Standard Adapter's existing refusal of pilot admission.

The existing version/expiry/intent fields remain in financial terms. A digest of the complete business record changes on every confirmation/scope/period edit, even without a version bump. The record is bound into existing Customer acceptance, independent checker snapshot and owned reservation. Changing or expiring terms invalidates an undispatched review; it never silently upgrades a receipt or approval.

The normal sequence remains: Customer Review/Submit → `approval_required` → independent Admin approval → atomic operation/principal reservation/audit consumption → one remittance dispatch attempt → webhook and scheduled status/accounting reconciliation. Review and Submit are the existing interactions. There are no new customer/admin actions or endpoints. Original FX freshness, `auditId`, funds, authorization, idempotency and unknown-outcome controls remain active.

## B. Files changed in this increment

- `app/Services/Nium/NiumSgdLocalZeroFeePolicy.php`: bounded local business authorization and traceable record.
- `app/Services/Nium/NiumPayoutFinancialControls.php`: select that explicit basis for configured USD→SGD admission; retain provider and legacy paths otherwise.
- `app/Services/Nium/NiumStandardTransferAdapter.php`: update comments to describe both authorized term sources; no behavior or public schema change.
- `config/services.php`: disabled policy configuration, explicit confirmation/scope fields and immutable zero-fee terms.
- `.env.example`: disabled activation and empty operator-supplied record fields.
- `tests/Feature/NiumSgdLocalZeroFeePolicyTest.php`: focused policy and standard-flow regressions using synthetic records/transport and the real production admission path.
- `docs/nium-sgd-local-zero-fee-admission.md`: this activation and review report.

HK SWIFT execution, ledger, reconciliation and Customer/Admin request/response fields were not changed. Existing public fee/readiness values remain compatible: `confirmed` indicates an available admitted fee amount, not independent API verification. The authoritative basis/reference lives in the internal financial authorization; no internal provider identity or business confirmation is added to the public customer projection. Legacy provider-derived terms retain their existing schema.

## C. Operator activation requirements

Configuration remains disabled and no actual activation record has been invented. Supply all of the following from the actual current business confirmation and intended release environment:

| Environment variable | Requirement |
| --- | --- |
| `NIUM_SGD_LOCAL_ZERO_FEE_ENABLED` | Explicit boolean `true` to activate; defaults to `false`. Malformed/non-boolean enabled values are refused. |
| `NIUM_SGD_LOCAL_ZERO_FEE_VERSION` | Nonempty business policy revision. |
| `NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMATION_REFERENCE` | Traceable reference to the actual business-owner confirmation; not an API evidence identifier. |
| `NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMED_BY` | Actual confirming business owner/recorded identity. |
| `NIUM_SGD_LOCAL_ZERO_FEE_CONFIRMED_AT` | Actual confirmation timestamp. |
| `NIUM_SGD_LOCAL_ZERO_FEE_EFFECTIVE_FROM` | Explicit start of the authorized current terms. |
| `NIUM_SGD_LOCAL_ZERO_FEE_EXPIRES_AT` | Explicit finite end of authorization; no indefinite default or automatic renewal. |
| `NIUM_SGD_LOCAL_ZERO_FEE_BASE_URL` | Exact NIUM environment origin matching the existing bound context. |
| `NIUM_SGD_LOCAL_ZERO_FEE_CLIENT_ID` | Exact configured NIUM client. |
| `NIUM_SGD_LOCAL_ZERO_FEE_CUSTOMER_ID` | Exact verified customer covered by the confirmation. |
| `NIUM_SGD_LOCAL_ZERO_FEE_WALLET_ID` | Exact verified source wallet covered by the confirmation. |

All timestamps must be valid absolute ISO-8601 timestamps with a timezone. The policy requires `confirmed_at <= effective_from <= now < expires_at` and a nonempty period. Record and identity strings must be nonempty, at most 255 bytes and free of surrounding whitespace/control characters. The policy scope cannot follow an environment/customer/wallet change implicitly. Beneficiary ownership, captured registration and HK corporate eligibility are still checked locally by the existing scope service; supported-corridor/purpose preflight remains in the normal workflow.

Operators must distribute the reviewed configuration consistently to all request/worker processes using the established deployment procedure. Do not activate using placeholders, test identities or synthetic confirmation records. Retain the referenced confirmation and configuration revision as operational records; the application also persists the exact accepted policy snapshot with each customer receipt/approval/hold.

Keep existing wallet ledger controls, fresh source funding, FX locking, independent checker authorization, authenticated webhook and scheduled reconciliation operational. The standard policy needs no pilot authorization or pricing refresh action. Future nonzero fees or another debit basis require separate implementation and review; changing the fixed terms array makes this policy invalid. Disabling or expiring it stops new admission but does not block reconciliation of an already dispatched operation or clear its hold. Idempotent replay of an already accepted customer receipt remains a read of that receipt, not a new grant of dispatch authority.

## D. Test coverage added

All new transport and confirmation records are explicitly synthetic, not NIUM evidence. Tests use the real `NiumPayoutFinancialControls` branch and existing production pricing adapter, without installing a synthetic confirmed-price adapter.

- Default disabled; customer metadata cannot activate the policy; active authorization does not create provider captures or provider verification/completeness claims.
- Exact decimal USD debit, zero NIUM/Origin/additional charges and retained confirmation record.
- Missing/malformed configuration, invalid calendars/relative dates, future/not-yet-effective/expired/reversed periods and context/term mismatches deny admission before funds are reserved.
- Unsupported source/destination/rail/recipient/funding intent is refused, including HK USD SWIFT exclusion from this policy.
- Standard customer receipt/replay, mandatory independent checker even with global approval disabled, exact principal hold and one remittance attempt.
- Changed confirmation, expiry or disabling between Review, Submit, checker approval and manager dispatch invalidates authority.
- Insufficient funds or expired FX cannot be overridden by business terms.
- Timeout preserves unknown status and owned hold with no repost from repeated approval or reconciliation.
- Scheduled settlement remains idempotent after the business policy and FX expire, including after policy deactivation; actual financial evidence is still required.

Existing Standard Adapter, provider-pricing, FX, authorization, HK USD 5/10 and accounting/reconciliation suites remain required regression coverage. No runtime PASS is claimed.

## E. Operator-only validation

These commands were not run. Use a review checkout, disposable test configuration, in-memory SQLite and no outbound network access. The guarded bootstrap refuses other database modes; feature tests migrate only that disposable in-memory database internally. Do not use cached production configuration.

```bash
cd /home/dtd1201/projects/api_origin_wallet_integration

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php \
  tests/Feature/NiumSgdLocalZeroFeePolicyTest.php

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
php vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php \
  tests/Feature/NiumSgdLocalZeroFeePolicyTest.php \
  tests/Feature/NiumConfiguredRemittancePricingTest.php \
  tests/Feature/NiumStandardTransferAdapterTest.php \
  tests/Feature/NiumSgdLocalExecutionTest.php \
  tests/Feature/NiumPayoutFxLockContractTest.php \
  tests/Feature/NiumPayoutFxSafetyTest.php \
  tests/Feature/NiumPayoutAuthorizationSecurityTest.php \
  tests/Feature/NiumSwiftCheckerAuthorizationTest.php \
  tests/Feature/NiumPayoutDebitReconciliationTest.php \
  tests/Feature/ReconcileNiumTransfersCommandTest.php
```

Production-engine concurrency/configuration validation belongs to the operator's separately authorized release process. SQLite results alone cannot establish production locking.

## F. Remaining release blockers

The earlier requirement for a complete applicable debit authorization is now implemented against the explicitly supplied business-confirmed terms. An independent provider pricing API quote is not an additional prerequisite for this business-policy admission path. The actual activation record and finite period must still be supplied by the operator; the code does not invent them.

This increment has not been executed or validated. Deployment/configuration review, focused/full regression results, production-engine concurrency, authenticated webhook/scheduler operation and real provider response/accounting compatibility remain operator release acceptance items. The existing full-wallet activity scan is bounded at 20 pages/2,000 rows and must be suitable for the intended wallet history.

Existing automatic failure/refund/return accounting limitations remain as documented in `nium-sgd-standard-production-finalization.md`: dispatched failed/returned payouts retain unresolved funds without verified financial evidence, and returns after a settled terminal outcome require resolution. This task does not implement the expressly excluded refund subsystem. An uncaptured unknown submission cannot be safely reposted or resolved by inventing a reference. The new business confirmation authorizes expected debit, not fabricated actual settlement, refund or delivered SGD credit.

## G. GO/NO-GO

Admission implementation is complete at source level and remains **disabled by default**. **NO-GO for production activation now**: operator validation and the actual reviewed activation record are outstanding, and the existing exceptional failure/return accounting limitations remain. No runtime PASS, real zero-fee settlement, deployment or independent provider verification is asserted. Reassess release readiness after those operator acceptance items are satisfied; do not infer production approval from this source implementation.
