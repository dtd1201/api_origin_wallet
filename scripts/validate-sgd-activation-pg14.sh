#!/usr/bin/env bash
# DEVELOPMENT HOST ONLY: new disposable containers, synthetic data, no network.
set -euo pipefail
SGD_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SGD_ROOT"
[[ ! -e .env && ! -e .env.testing && ! -L .env && ! -L .env.testing ]]
SGD_EVIDENCE="$SGD_ROOT/docs/sgd-activation/evidence"
mkdir -p "$SGD_EVIDENCE"
SGD_DISPOSABLE="$(mktemp -d /tmp/ow-sgd-compatibility.XXXXXXXX)"
mkdir "$SGD_DISPOSABLE/socket"
chmod 0777 "$SGD_DISPOSABLE/socket"
SGD_PG_CONTAINER="ow-sgd-activation-pg14-$$"
SGD_PG_CREATED=false
sgd_docker() { docker --host unix:///var/run/docker.sock "$@"; }
sgd_cleanup() {
  if [[ "$SGD_PG_CREATED" == true ]]; then
    sgd_docker logs "$SGD_PG_CONTAINER" > "$SGD_EVIDENCE/disposable-pg14.log" 2>&1
    sgd_docker stop --time 30 "$SGD_PG_CONTAINER" >/dev/null
    sgd_docker rm "$SGD_PG_CONTAINER" >/dev/null
  fi
}
trap sgd_cleanup EXIT
sgd_docker image inspect --format '{{.Id}}' postgres:14 nium-customer-retry-php831-r8r15:local > "$SGD_EVIDENCE/compatibility-image-ids.txt"
sgd_docker run --pull never --detach --name "$SGD_PG_CONTAINER" --network none --read-only \
  --cap-drop ALL --security-opt no-new-privileges --user 999:999 \
  --tmpfs /tmp:rw,mode=1777 \
  --tmpfs /tmp/origin-sgd-activation-pg14/data:rw,uid=999,gid=999,mode=0700 \
  --mount "type=bind,src=$SGD_DISPOSABLE/socket,dst=/tmp/origin-sgd-activation-pg14/socket" \
  --mount "type=bind,src=$SGD_ROOT/scripts/start-disposable-sgd-pg14.sh,dst=/start-test-pg.sh,readonly" \
  -e PGDATA=/tmp/origin-sgd-activation-pg14/data \
  --entrypoint /bin/bash postgres:14 /start-test-pg.sh >/dev/null
SGD_PG_CREATED=true
SGD_PG_READY=false
for ((sgd_wait=0; sgd_wait<60; sgd_wait++)); do
  if sgd_docker exec "$SGD_PG_CONTAINER" /bin/sh -c 'test "$(cat /proc/1/comm)" = postgres && pg_isready -h /tmp/origin-sgd-activation-pg14/socket -p 55441 -U sgd_activation_test -d origin_sgd_activation_test' >/dev/null 2>&1; then SGD_PG_READY=true; break; fi
  sleep 0.2
done
[[ "$SGD_PG_READY" == true ]]
sgd_docker run --pull never --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" --workdir /workspace \
  --mount "type=bind,src=$SGD_ROOT,dst=/workspace,readonly" \
  --mount "type=bind,src=$SGD_EVIDENCE,dst=/evidence" \
  --mount "type=bind,src=$SGD_DISPOSABLE/socket,dst=/tmp/origin-sgd-activation-pg14/socket,readonly" \
  --tmpfs /tmp:rw,mode=1777 --tmpfs /workspace/bootstrap/cache:rw,mode=0777 --tmpfs /workspace/storage:rw,mode=0777 \
  -e APP_ENV=testing -e APP_BASE_PATH=/workspace -e APP_CONFIG_CACHE=/tmp/nonexistent-activation-config.php \
  -e DB_CONNECTION=pgsql -e DB_HOST=/tmp/origin-sgd-activation-pg14/socket -e DB_PORT=55441 \
  -e DB_DATABASE=origin_sgd_activation_test -e DB_USERNAME=sgd_activation_test -e DB_PASSWORD= -e DB_URL= \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr \
  -e NIUM_SGD_LOCAL_ZERO_FEE_ENABLED=false -e NIUM_PAYOUT_PILOT_ENABLED=false \
  -e 'PGOPTIONS=-c statement_timeout=30000 -c lock_timeout=10000' \
  --entrypoint /bin/bash nium-customer-retry-php831-r8r15:local -lc '
    set -euo pipefail
    mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs
    for sgd_run in 1 2 3; do
      php vendor/bin/phpunit --do-not-cache-result --bootstrap tests/SgdActivationPostgresBootstrap.php \
        --log-junit "/evidence/php83-pg14-run-$sgd_run.xml" tests/Feature/NiumSgdActivationPostgresTest.php
    done
  '
sgd_docker logs "$SGD_PG_CONTAINER" > "$SGD_EVIDENCE/disposable-pg14.log" 2>&1
