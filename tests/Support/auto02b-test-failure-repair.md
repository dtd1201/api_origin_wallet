# AUTO-02B test failure repair — Technical Lead review

Source inspection and test-only edits only. No PHP, PHPUnit, Artisan, Composer,
migration, lint, build, server or database command was executed. No runtime PASS
is claimed. Existing dirty and untracked work was preserved.

## Findings grouped by root cause

### 1. Incomplete shared KYC fixture: corrected

`CreatesDynamicConfirmationFixtures::createDynamicFixtures()` inserted an approved
individual KYC profile without `address_line1` OR `city`. Both columns are
non-null, without defaults, in
`2026_04_29_000027_create_kyc_platform_tables.php`. Supplying only the first field
would leave a second NOT NULL violation. `country_code` was already present.
`address_line2`, `state` and `postal_code` are nullable; the corrected fixture also
supplies a synthetic UK postal code. No schema constraint was relaxed.

The trait is used by both persistence and process-concurrency tests. A setup
exception can therefore prevent many independent cases from reaching their
assertions. That is a source-verified shared failure mechanism, not proof that
every reported error belongs to this group.

The new persistence regression reads the KYC record back from the database,
asserts its address, ownership-linked completed/clear AML screening, and verifies
that fixture setup alone creates no intent, reservation, outbox or ledger entry.
The existing successful confirmation tests still exercise actual atomic writes.

### 2. Legacy FX binding after PostgreSQL JSONB storage: unresolved production blocker

The visible legacy HK/EUR test acquires a real application FX lock against an
explicit Laravel HTTP fake. It does NOT hand-build a stale lock snapshot. The
fixture's exact provider context matches the configured account:

| Field | Expected value |
| --- | --- |
| client | `client_hash_test` |
| customer | `customer-test` |
| wallet | `wallet-test` |
| base_url | `https://gateway.sandbox.nium.test` |

`NiumPayoutFxLockService::context()` constructs that associative array in the order
client, customer, wallet, base_url. The service persists it in
`fx_quotes.raw_data`, a PostgreSQL **jsonb** column. JSONB does not preserve object
key insertion order. `FxQuote` casts the returned JSON into a PHP array.
`NiumPayoutFxLockContract::assertMatchesTransfer()` uses `$binding !== $context`;
PHP array identity requires the SAME key order as well as identical keys, values
and types. Thus a database round trip can reject unchanged provider context before
the expected remittance HOLD is reached. This mechanism is established from the
schema, casts and call path; its reproduction has not been executed here.

Expiry is not an identified fixture defect: `parse()` rounds it down to seconds
before storage. Audit ID, currencies, source amount and rate are produced by the
existing parser for this test. No verified wrong client/customer/wallet value was
found to correct.

A new focused integration regression proves that the original in-memory quote
validates, checks every persisted provider-context and response-snapshot member
with strict scalar assertions, and then requires the persisted quote to validate
against the SAME context. It uses one explicit fake GET, asserts no remittance
POST and leaves the transfer draft without an operation key. Its final validation
is expected to remain RED on PostgreSQL with the current production comparator.
The original successful lock-acquisition/submission-HOLD expectation is retained.

Neither test catches the unchanged-context validation failure as success. No
test-only model cast, JSONB normalization, service replacement, missing-binding
fallback, schema alteration or relaxed validator was introduced to mask it.
Resolving this blocker requires a separately authorized production-contract
review. Any eventual correction must retain exact keys, scalar types, values and
all quote bindings while accounting for JSON object ordering.

The contract tests additionally check seven changed response-snapshot members:
version, audit ID, source/target currencies, source amount, rate and expiry. Each
starts with a valid control and requires the precise binding exception after one
mutation. Existing client/customer/wallet/environment rejection cases now also
validate the control and require that precise exception.

### 3. Other operator errors: not classified without the full report

The operator supplied 305 tests / 2050 assertions / 51 errors but only two error
details. No full text or JUnit report was available during inspection. The local
PHPUnit cache includes defects from older runs and other suites, with no exception
details or per-run provenance; it is not evidence of this run's 51 root causes.
Do not report a remaining error count or claim all 51 are repaired from this patch.

The prior recorded selection in `auto02b-postgres-reset.md` includes reset safety
and repeated migration tests; dynamic boundary, persistence and concurrency tests;
offline HTTP guard tests; AUTO-02A confirmation/financial/quote tests; FX safety;
legacy transfer service; and SGD LOCAL gate tests. This is the source selection
reviewed, not a verified copy of the operator's exact invocation. The command below
also includes `NiumPayoutFxLockContractTest` and the new cases. Its test count will
differ from 305.

## Isolation and lifecycle review

- Laravel's installed `RefreshDatabase` uses a process migration-cache flag and
  wraps ordinary feature tests in rollback transactions. The guarded shared
  `migrate:fresh` interception is unchanged.
- Dynamic persistence/concurrency tests use the existing `DatabaseMigrations`
  support, fresh migrations per test, and the guarded schema reset at teardown,
  avoiding the intentionally forbidden production rollback. They retain real
  committed transactions for forked workers.
- The reset helper still requires testing/unit-test mode, PostgreSQL, loopback
  host, configured AND actual write-PDO database exactly
  `origin_wallet_auto02b_test`, public schema and no active transaction. It still
  propagates errors and invalidates `RefreshDatabaseState::$migrated` after reset.
- Concurrency workers still purge/reconnect their own PDOs and are joined before
  teardown. No reset was added on worker startup, reconnect or application refresh.
- The installed Laravel teardown clears Carbon clocks and the application;
  fixture clock/config/fakes are recreated per test. Offline HTTP guard tests
  restore their shared enablement state. No new static fixture state or global
  configuration was added. No independent test-order root cause was verified.
- Atomic reservation, fees, insufficient-funds, cancellation ownership, ambiguous
  payout, rollback, historical Transfer #5 and legacy HK isolation cases remain
  intact. The new operator command uses a reproducible random order to exercise
  mixed database lifecycles; order independence has not been executed here.

## Exact changed-file manifest for this repair

Modified existing files (including pre-existing dirty/untracked contents):

1. `tests/Concerns/CreatesDynamicConfirmationFixtures.php` — complete KYC address.
2. `tests/Feature/NiumDynamicConfirmationPersistenceTest.php` — persisted fixture regression.
3. `tests/Feature/NiumTransferServiceTest.php` — isolated unchanged FX binding round-trip regression.
4. `tests/Feature/NiumPayoutFxLockContractTest.php` — response mutation cases and stronger context rejection controls.

Added:

5. `tests/Support/auto02b-test-failure-repair.md` — this review record.

No application, migration, financial contract, wallet, dispatch, feature-flag
configuration, reset support or concurrency-worker source was changed by this
repair. No commits or pushes were made.

## One operator-only verification command

After Technical Lead review, run from the repository root using the already
provisioned disposable local database and approved test credentials. Use one
runner at a time; internal forked concurrency tests remain included. This command
DELETES/recreates the disposable public schema through the existing guarded test
lifecycle. It was NOT executed by Codex. The timestamp/PID report path avoids
overwriting an earlier report. Preserve its full console output and JUnit report
for classification of any remaining errors. The current FX blocker is expected
to prevent an all-green result.

```sh
APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=origin_wallet_auto02b_test DB_URL= ./vendor/bin/phpunit --bootstrap tests/nium-transfer-offline-bootstrap.php --fail-on-warning --fail-on-risky --fail-on-skipped --order-by=random --random-order-seed=20261110 --log-junit "/tmp/origin-wallet-auto02b-repair-$(date -u +%Y%m%dT%H%M%SZ)-$$.xml" --filter 'Auto02bPostgres(TestReset|RefreshDatabase)|NiumDynamicConfirmation(Boundary|Persistence|Concurrency)Test|NiumTransferOfflineHttpGuardTest|NiumDynamicPayoutConfirmationTest|NiumDynamicFinancialTermsTest|NiumDynamicQuoteFoundationTest|NiumPayoutFx(Safety|LockContract)Test|NiumTransferServiceTest|NiumSgdLocalPayoutGateTest'
```

Recommendation: **FAIL for sign-off**. The KYC fixture is corrected in source;
runtime verification is prohibited, the FX storage/comparison blocker remains
outside the test-only edit scope, and the complete operator error report is still
needed. Stop after these source changes for Technical Lead review.
