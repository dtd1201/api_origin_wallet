#!/usr/bin/env bash
# LOCAL ONLY. Creates a fresh Unix-socket-only cluster, never reuses a database.
set -euo pipefail
SGD_BACKEND_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$SGD_BACKEND_ROOT"
if [[ -e .env || -e .env.testing || -L .env || -L .env.testing ]]; then
  echo 'Refusing environment files; use the clean local release checkout.' >&2
  exit 1
fi
export APP_ENV=testing APP_BASE_PATH="$SGD_BACKEND_ROOT"
export APP_CONFIG_CACHE="$SGD_BACKEND_ROOT/bootstrap/cache/sgd-postgres-validation-config.php"
if [[ -e "$APP_CONFIG_CACHE" || -L "$APP_CONFIG_CACHE" ]]; then exit 1; fi
export DB_CONNECTION=pgsql DB_HOST=/tmp/origin-sgd-release-pg/socket DB_PORT=55440
export DB_DATABASE=origin_sgd_release_test DB_USERNAME=sgd_release_test DB_PASSWORD='' DB_URL=''
unset DATABASE_URL PGHOST PGHOSTADDR PGSERVICE PGSERVICEFILE PGDATABASE PGPORT PGUSER PGPASSWORD
export CACHE_STORE=array SESSION_DRIVER=array MAIL_MAILER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr
export NIUM_SGD_LOCAL_ZERO_FEE_ENABLED=false NIUM_PAYOUT_PILOT_ENABLED=false
export PGOPTIONS='-c statement_timeout=30000 -c lock_timeout=10000'
SGD_PG_BIN=/usr/lib/postgresql/18/bin
SGD_PG_ROOT=/tmp/origin-sgd-release-pg
SGD_PG_REPETITIONS="${SGD_POSTGRES_REPETITIONS:-1}"
if [[ ! "$SGD_PG_REPETITIONS" =~ ^[1-9]$ ]]; then exit 1; fi
# Atomic creation fails for existing directories/symlinks. Never remove an existing cluster.
umask 077
mkdir -m 700 "$SGD_PG_ROOT"
sgd_cleanup() {
  if [[ -e "$SGD_PG_ROOT/data/postmaster.pid" ]]; then
    "$SGD_PG_BIN/pg_ctl" -D "$SGD_PG_ROOT/data" -m fast -w stop
  fi
  # Keep the stopped, owned cluster and logs for investigation; no recursive deletion.
}
trap sgd_cleanup EXIT
mkdir -m 700 "$DB_HOST"
"$SGD_PG_BIN/initdb" -D "$SGD_PG_ROOT/data" -U "$DB_USERNAME" --auth-local=trust --auth-host=reject --no-locale -E UTF8 > "$SGD_PG_ROOT/initdb.log"
"$SGD_PG_BIN/pg_ctl" -D "$SGD_PG_ROOT/data" -l "$SGD_PG_ROOT/postgres.log" -w start \
  -o "-k $DB_HOST -p $DB_PORT -c listen_addresses='' -c max_connections=20 -c unix_socket_permissions=0700"
"$SGD_PG_BIN/createdb" --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" "$DB_DATABASE"
for ((sgd_run=1; sgd_run<=SGD_PG_REPETITIONS; sgd_run++)); do
  vendor/bin/phpunit --bootstrap tests/SgdReleasePostgresBootstrap.php \
    --log-junit "$SGD_PG_ROOT/run-$sgd_run.xml" "$@" tests/Feature/NiumSgdReleasePostgresTest.php
done
