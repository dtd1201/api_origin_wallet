#!/usr/bin/env bash
# LOCAL disposable validation only. Never run in an installed production/staging release.
set -euo pipefail
SGD_BACKEND_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SGD_BACKEND_ROOT"
if [[ -e .env || -e .env.testing ]]; then
  echo 'Use a clean local checkout without environment files for offline payout validation.' >&2
  exit 1
fi
export APP_ENV=testing APP_BASE_PATH="$SGD_BACKEND_ROOT"
export APP_CONFIG_CACHE="$SGD_BACKEND_ROOT/bootstrap/cache/sgd-offline-validation-config.php"
if [[ -e "$APP_CONFIG_CACHE" ]]; then
  echo 'Unexpected test configuration cache; refusing to load it.' >&2
  exit 1
fi
export DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=''
unset DATABASE_URL
export CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr
export NIUM_SGD_LOCAL_ZERO_FEE_ENABLED=false NIUM_PAYOUT_PILOT_ENABLED=false
# These production financial policy/ledger/config files must remain byte-identical to the reviewed baseline.
sha256sum --check --status docs/sgd-activation/financial-policy.sha256
payout_tests=(
  tests/Feature/NiumSgdAdmissionBridgeTest.php
  tests/Unit/SgdActivationPostgresSafetyTest.php
  tests/Feature/NiumSgdAdmissionFenceTest.php
  tests/Unit/NiumPayoutQuoteContractTest.php
  tests/Unit/PayoutPostgresSafetyTest.php
  tests/Unit/SgdReleasePostgresSafetyTest.php
  tests/Feature/NiumSgdLocalZeroFeePolicyTest.php
  tests/Feature/NiumStandardTransferAdapterTest.php
  tests/Feature/NiumCustomerSubmitTest.php
  tests/Feature/NiumAutomaticReviewTest.php
  tests/Feature/NiumReviewBalanceFreshnessTest.php
  tests/Feature/NiumSgdLocalExecutionTest.php
  tests/Feature/NiumPayoutAuthorizationSecurityTest.php
  tests/Feature/NiumSwiftCheckerAuthorizationTest.php
  tests/Feature/NiumRemittanceExecutionSafetyTest.php
  tests/Feature/NiumPayoutDebitReconciliationTest.php
  tests/Feature/NiumConfiguredRemittancePricingTest.php
  tests/Feature/NiumProvisionalPayoutPricingTest.php
  tests/Feature/NiumPayoutPilotPolicyTest.php
  tests/Feature/NiumPayoutPilotRenewalTest.php
)
vendor/bin/phpunit --bootstrap tests/PayoutOfflineBootstrap.php "$@" "${payout_tests[@]}"
