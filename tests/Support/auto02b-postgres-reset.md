# AUTO-02B: disposable PostgreSQL test reset

Source changes only. No PHP, tests, Artisan, Composer, migration, lint, build,
server or database command was executed. No runtime PASS is claimed. Stop for
Technical Lead review before operator verification.

## Exact root cause

The installed Laravel `RefreshDatabase` trait calls
`$this->artisan('migrate:fresh', ...)` when the process-local
`RefreshDatabaseState::$migrated` flag is false, then starts a transaction for the
test. A new PHPUnit process starts with that flag false. Other test lifecycle
operations can invalidate it too.

Laravel's `migrate:fresh` checks for the migration repository and invokes `db:wipe`
when it exists. The PostgreSQL schema builder emits `DROP TABLE ... CASCADE`;
optional view/type removal still does not drop standalone functions. Dropping the
tables removes their triggers and the `migrations` table. The PL/pgSQL functions
installed by the Nium VA migrations remain in `public`: their string bodies do
not give them the table dependencies needed to be dropped with those tables.
Migration history is gone, so the next migration pass executes the unchanged
`CREATE FUNCTION nium_va_request_insert_guard()` against that orphan and PostgreSQL
raises SQLSTATE 42723. If the repository is already absent, `migrate:fresh` skips
the table wipe altogether; that does not remove the orphan either.

AUTO-02B's separate `DatabaseMigrations` setup runs `migrate:fresh` before EVERY
test. Its stock teardown invokes `migrate:rollback`, which cannot work here:
`2026_10_27_000001_create_nium_va_safety_ledger.php::down()` deliberately throws,
even for empty safety tables. That independent teardown incompatibility also
needs a test-only lifecycle correction. The forward-only migration is correct
and remains untouched.

## Small test-only implementation

`Tests\TestCase::artisan()` intercepts the exact `migrate:fresh` call used by the
installed RefreshDatabase, DatabaseMigrations and initial DatabaseTruncation
paths. For PostgreSQL, it runs a guarded PUBLIC SCHEMA reset before delegating to
the original Laravel command. SQLite retains its original command behavior.
It honors an explicit `--database` connection selection and validates that actual
connection; it does not silently fall back to the approved connection.

The helper atomically drops and recreates `public` with PostgreSQL transactional
DDL. This removes tables, migration history, triggers, functions and other objects
in that disposable schema, including already orphaned functions. Successful reset
invalidates Laravel's migration cache. The original migrations then recreate the
real safety functions and triggers. No CREATE FUNCTION statement, trigger body,
VA invariant or financial application behavior is modified. DDL errors and
guard failures propagate; no exception is suppressed and false DDL results throw.

`DynamicConfirmationDatabaseTestCase` retains DatabaseMigrations setup but replaces
its teardown callback with the same guarded schema reset, avoiding rollback
entirely. It no longer skips an unsuitable database: unsafe configuration fails
explicitly. The earlier AUTO-02B design notes' stock rollback/SQLite-skip behavior
is superseded by this test support lifecycle.

## Checks before any schema reset

- Application environment must explicitly be `testing` and Laravel must report
  unit-test mode.
- The connection driver must be PostgreSQL.
- Both Laravel's database name and connection configuration must equal
  `origin_wallet_auto02b_test` EXACTLY. No prefixes, suffixes, aliases to a
  different database, `origin_wallet`, staging or Production names are accepted.
- Configured host must be `127.0.0.1`, `localhost` or `::1`.
- Laravel transaction depth must be zero and PDO must have no active transaction.
- A read-only identity query on the WRITE PDO connection must report
  `current_database() = origin_wallet_auto02b_test` and `current_schema() = public`.
  Configuration alone is not sufficient; a read replica cannot establish safety.

Unsafe configuration is rejected before opening a connection. Identity mismatches
are rejected after the read-only check but before any DDL. The helper contains
only literal schema DDL and no interpolated database/schema identifiers.

## Regression coverage

- `Auto02bPostgresTestResetSafetyTest`: 14 unsafe environment/driver/database/host/
  schema/transaction cases prove no DDL is attempted; unsafe configuration cannot
  even open PDO. A positive guard test checks that real identity uses write PDO.
- `Auto02bPostgresTestResetTest`: two consecutive full fresh-migration cycles
  reconnect disconnected PDOs, remove an orphan probe function and reinstall real VA guard and mirror functions;
  a single enabled insert trigger remains; invalid initial requests still fail
  with the exact VA invariant and SQLSTATE 23514. Separate tests cover cache
  invalidation/rebuild and the unchanged forward-only down() exception.
- `Auto02bPostgresRefreshDatabaseTest`: two test cases use the ACTUAL installed
  RefreshDatabase trait, invalidate its migration cache and rebuild committed
  functions from the preceding cycle. Its ordinary per-test transaction remains
  active after setup.
- The operator command also includes the existing AUTO-02B process-concurrency
  tests. Those remain outside an enclosing test transaction. Schema reset occurs
  only for migration setup/teardown, never application creation, database reconnect
  or worker startup. Existing tests join their forked workers before teardown.

## Limits and operator verification

Only the named disposable LOCAL database is authorized. Reset deletes EVERYTHING
in its public schema, not merely Nium functions, and recreates public owned by the
connected test role. That role must have schema drop/create privileges; custom
public grants, extensions or objects are not preserved. Database configuration,
roles and objects in other schemas are not recreated. No attempt is made to repair
a different schema or a missing/non-public current schema.

Run one PHPUnit runner against this database at a time. Forked workers within the
existing concurrency tests are supported; independent simultaneous runners or
ParaTest database suffixes are not. Explicit fresh calls inside an active test
transaction fail, rather than destroying data beneath the transaction.

The interception covers `$this->artisan('migrate:fresh', $parameters)` in the shared
test case. Standalone Artisan commands, direct `Artisan::call`, tests outside that
base, alternate command-string forms and raw SQL resets are outside this fix.
Explicit rollback remains forbidden by the production migration. No automatic
reset runs merely because an application/container or worker connection refreshes.

After Technical Lead review, from the repository root, using the already provisioned
disposable database and approved local test credentials, run this ONE operator-only
command. It resets that database during test setup/teardown. NOT executed by Codex:

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=origin_wallet_auto02b_test DB_URL= ./vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php --fail-on-warning --fail-on-risky --fail-on-skipped --filter 'Auto02bPostgres(TestReset|RefreshDatabase)|NiumDynamicConfirmation(Boundary|Persistence|Concurrency)Test|NiumTransferOfflineHttpGuardTest|NiumDynamicPayoutConfirmationTest|NiumDynamicFinancialTermsTest|NiumDynamicQuoteFoundationTest|NiumPayoutFxSafetyTest|NiumTransferServiceTest|NiumSgdLocalPayoutGateTest'
```

Recommendation: **FAIL / unverified for runtime sign-off** until the operator
reports passing tests with zero errors, warnings, risky tests and skips, including
repeat-cycle and process-concurrency coverage. The same command can be rerun to
verify recovery between PHPUnit processes. No application, production migration,
AUTO-02B accounting, reservation, outbox or dispatch code was edited. No commits
or pushes were made.

## Exact changed-file manifest for this fix

Modified (existing dirty/untracked contents preserved):

1. `tests/TestCase.php`
2. `tests/Support/DynamicConfirmationDatabaseTestCase.php`

Added:

3. `tests/Support/Auto02bPostgresTestReset.php`
4. `tests/Unit/Auto02bPostgresTestResetSafetyTest.php`
5. `tests/Feature/Auto02bPostgresTestResetTest.php`
6. `tests/Feature/Auto02bPostgresRefreshDatabaseTest.php`
7. `tests/Support/auto02b-postgres-reset.md`
