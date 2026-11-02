#!/usr/bin/env bash
# Called ONLY inside a newly created network-disabled test container as UID 999.
set -euo pipefail
test "${PGDATA:?}" = /tmp/origin-sgd-activation-pg14/data
test "$(id -u)" = 999
test ! -e "$PGDATA/PG_VERSION"
SGD_TEST_SOCKET=/tmp/origin-sgd-activation-pg14/socket
initdb -D "$PGDATA" -U sgd_activation_test --auth-local=trust --auth-host=reject --no-locale -E UTF8
pg_ctl -D "$PGDATA" -w start -o "-k $SGD_TEST_SOCKET -p 55441 -c listen_addresses='' -c unix_socket_permissions=0777"
createdb -h "$SGD_TEST_SOCKET" -p 55441 -U sgd_activation_test origin_sgd_activation_test
pg_ctl -D "$PGDATA" -m fast -w stop
exec postgres -D "$PGDATA" -k "$SGD_TEST_SOCKET" -p 55441 -c listen_addresses= -c unix_socket_permissions=0777
