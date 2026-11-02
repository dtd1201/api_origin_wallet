#!/usr/bin/env bash
# LOCAL ONLY. Uses existing images, network none, :memory: data and private tmpfs.
set -euo pipefail
SGD_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SGD_MODE="${1:-full}"
SGD_SOURCE="$SGD_ROOT"
if [[ "$SGD_MODE" == bridge ]]; then SGD_SOURCE="$(dirname "$SGD_ROOT")/api_origin_wallet_sgd_fence_bridge"; fi
[[ ! -e "$SGD_SOURCE/.env" && ! -e "$SGD_SOURCE/.env.testing" && ! -L "$SGD_SOURCE/.env" && ! -L "$SGD_SOURCE/.env.testing" ]]
mkdir -p "$SGD_ROOT/docs/sgd-activation/evidence"
docker --host unix:///var/run/docker.sock run --pull never --rm --network none --read-only \
  --cap-drop ALL --security-opt no-new-privileges --user "$(id -u):$(id -g)" --workdir /workspace \
  --mount "type=bind,src=$SGD_SOURCE,dst=/workspace,readonly" \
  --mount "type=bind,src=$SGD_ROOT/docs/sgd-activation/evidence,dst=/evidence" \
  --tmpfs /tmp:rw,mode=1777 --tmpfs /workspace/bootstrap/cache:rw,mode=0777 --tmpfs /workspace/storage:rw,mode=0777 \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= \
  -e APP_CONFIG_CACHE=/tmp/nonexistent-sgd-config.php -e "SGD_VALIDATION_MODE=$SGD_MODE" \
  --entrypoint /bin/bash nium-customer-retry-php831-r8r15:local -lc '
    set -euo pipefail
    mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs
    case "$SGD_VALIDATION_MODE" in
      full) bash scripts/validate-sgd-payout-release.sh --do-not-cache-result --log-junit /evidence/php83-final.xml ;;
      focused) php vendor/bin/phpunit --do-not-cache-result --bootstrap tests/PayoutOfflineBootstrap.php \
        --log-junit /evidence/php83-admission-rollback-guards.xml tests/Feature/NiumSgdAdmissionFenceTest.php \
        tests/Feature/NiumSgdAdmissionBridgeTest.php tests/Unit/SgdActivationPostgresSafetyTest.php ;;
      bridge) php vendor/bin/phpunit --do-not-cache-result --bootstrap tests/PayoutOfflineBootstrap.php \
        --log-junit /evidence/php83-baseline-bridge.xml tests/Feature/NiumSgdAdmissionBridgeTest.php \
        tests/Feature/NiumSwiftCheckerAuthorizationTest.php ;;
      *) exit 2 ;;
    esac
  '
