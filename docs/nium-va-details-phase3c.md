# Phase 3C: safe VA detail persistence — implementation handoff

Implementation and test authoring only. No tests, syntax checks, formatters, Git
checks, migrations or application commands were executed for this task. Validation
is pending the user's manual execution. No live banking API requests, Production
access, activation, staging, commit, push or deployment occurred.

## Exact files changed by this task

Existing local Phase 1–3B changes were retained. The task changes these eight files:

| File | Change |
| --- | --- |
| app/Services/Nium/NiumVirtualAccountDetailService.php | Extend the existing GET/enrichment service with frozen-target proof checks, lossless numbered pairs, conservative merges and concurrency protection. |
| app/Models/NiumVirtualAccountDetail.php | Add provider-fact fields, hide the internal version, retain the existing bank-name display fallback. |
| app/Services/Nium/NiumVirtualAccountSafetyLedger.php | Add read-only verifiedAssignmentResponse(), reusing existing evidence verification/fingerprint logic. No ownership transition, POST or dispatch code changed. |
| database/migrations/2026_10_27_000005_extend_nium_virtual_account_details.php | New additive detail columns; migration authored, not executed. |
| tests/Fixtures/nium/virtual-account-details-v2.json | New synthetic response using documented fields; contains no real customer banking details. |
| tests/Feature/NiumVirtualAccountDetailServiceTest.php | New isolated SQLite/HTTP-fake tests. |
| tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php | Add two opt-in, separate-worker detail races to the existing isolated PostgreSQL harness. |
| docs/nium-va-details-phase3c.md | This handoff and manual validation commands. |

No route, controller, frontend, configuration, webhook, reconciliation, KYB, RFI
or transfer file was changed. No file was staged by this task. No Git status or
index command was run; this list describes task edits, not the full pre-existing
dirty worktree. The protected file tatus --short was not read, edited or staged.

## Provider contract inspected

Public documentation inspected on 2026-10-27:

- [VA guide](https://docs.nium.com/docs/payins/virtual-account-number).
- [GET V2 reference](https://docs.nium.com/api#tag/customer-virtual-accounts/GET/api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds).
- [Official OpenAPI](https://docs.nium.com/oas/nium.yaml):
  GET /api/v2/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentIds,
  operationId virtualAccountDetailsV2, VirtualAccountRequestDTO,
  VirtualAccountResponseDTO.content -> WalletPaymentIdsResponseDTO.

The current GET response schema documents fullBankName. A different schema,
PaymentIdsDTO, spells a full-name field bankNameFull; it is not the response
schema referenced by this GET operation, so no speculative alias was added.
Existing legacy response-envelope and Payment ID aliases remain legacy-only.

The guide documents both numbered pairs and shows JPM_SG using SWIFT plus an
Intermediary Bank Code. It also shows pair 2 being a bank code or ABA (Wire) for
other accounts. Those examples establish field roles, not a customer's actual
bank details. Application code contains no hardcoded JPM routing values.

The GET response schema has numbered pairs 1 and 2; it has no dynamic routingCodes
array. Only those documented pairs are supported. Unknown metadata/tags, wallet
labels, credentials and raw evidence are not copied into the detail model.

## Storage and additive API behavior

| Provider fact | Existing or additive storage |
| --- | --- |
| uniquePaymentId / virtual account number | Existing VA.provider_payment_id; checked against the GET candidate, never reassigned or duplicated in details. |
| currencyCode | Existing VA.currency; explicit contradictions rejected, no detail copy. |
| accountName | Existing detail.account_name. |
| bankName | New detail.provider_bank_name; exact returned identifier. |
| fullBankName | Existing detail.bank_name. |
| bankAddress | Existing detail.bank_address; multiline addresses retained. |
| accountType | New detail.provider_account_type, without funding-rail translation. |
| accountCategory | New detail.provider_account_category; no inferred value. An explicit nonempty category must agree with an existing category. |
| routingCodeType1 / routingCodeValue1 | Existing detail.routing_code_type / routing_code_value. |
| routingCodeType2 / routingCodeValue2 | New detail.routing_code_type_2 / routing_code_value_2. |

The migration adds five nullable provider-fact columns and one internal unsigned
enrichment_version counter (default 0). Existing rows need no rewrite.
VA.account_type and VA.account_category remain the existing application metadata.
GLOBAL is retained as a provider fact while WIRES remains local intent.

The existing authorized VA list already serializes the detail relation, so it
exposes the five added provider-fact fields without changing the route or old
field names. bank_name still displays the full name when available, otherwise
the returned bank identifier. For new rows the fallback is computed instead of
duplicating the identifier into the full-name column. Existing legacy bank_name
values are preserved conservatively. enrichment_version is hidden.

Raw provider exchanges continue through the existing encrypted evidence store
and sanitized API request log. Evidence, frozen targets and credentials are not
serialized by the detail model. The service does not persist arbitrary response
JSON or introduce a second evidence store.

## Lifecycle and integrity rules

1. sync() requires an existing assigned Nium VA with a syntactically valid Payment
   ID. It rejects pending/unresolved VAs and open transactions before HTTP.
2. A ledger-enrolled VA requires exactly one linked enrolled operation, matching
   terminal assigned projections, target/payload hashes and scope identity. The
   original terminal POST evidence must still verify, including its recorded
   fingerprint, exact target, request/response body hashes, original header UUID
   and returned Payment ID.
   The original POST is never reissued.
3. The read path is GET V2 for both V1 and V2 assignments, as in Phase 3A. The
   immutable client/customer/wallet/origin is used for enrolled reads; current
   configuration must still match it. No V1 GET is invented.
4. Before and after HTTP, lock order is account -> scope -> request -> operation
   -> assignment evidence -> VA -> detail. HTTP runs outside those transactions.
   A terminal request's scope may already hold a later operation; enrichment
   neither requires an idle scope nor changes its owner.
5. The existing read-only ProviderHttpClient mode disables redirects and repeated
   attempts, forbids token-acquisition authentication POSTs and preserves normal
   encrypted evidence capture. Only GET is issued by this service.
6. The exact Payment ID is selected, not the first listed account. Explicit
   client/customer/wallet, currency, category or Payment ID contradictions are
   rejected. A frozen bank code is checked against bankName only, never against
   a display name. No account-type/funding-rail mapping is performed.
7. Documented pagination is followed up to 20 pages. Repeated identities,
   malformed entries, invalid/changing counts and incomplete listings fail without saving.
   A missing match returns null; it does not detach the existing VA.
8. Nonempty routing types and values are accepted only as complete same-response
   pairs. Empty/missing pairs do not clear existing values. No pair-2 label is
   inferred from position, bank or currency.
9. Null, empty and whitespace-only facts are ignored. Invalid types/control
   characters and oversized values reject the whole response. Normal string facts
   are limited to 255 bytes; bank addresses to 16,000 bytes, allowing line breaks.
10. Before saving, target, ownership/proof and the pre-GET detail version are
    checked again under locks. A changed version rejects an overlapping response;
    there is no automatic retry or merge from that stale snapshot.
11. Identical responses do not update timestamps/version. Missing facts may be
    filled on a later GET. The documented schema has no authoritative update
    sequence/timestamp: any changed nonempty stored fact requires review, even
    from a sequential request. A newer local request is not proof of newer
    provider data. Conflicting values are never silently replaced.
12. HTTP 404, timeouts, malformed responses, mismatches and persistence failures
    leave existing detail fields and assignment state intact. Call sync() again
    to retry GET. It never resets a request or changes pending/manual-review
    semantics, and cannot prove or create assignment ownership.

Legacy VAs retain their existing already-assigned-row/current-target basis and
legacy envelopes/ID aliases. Narrow corrections require a valid assigned ID,
reject detected collisions and contradictory identity, prevent destructive
merges, and apply the same read-only transport/concurrency protections. No frozen
historical identity is manufactured for legacy rows.

## Enrichment trigger decision / dependency

No new automatic ledger enrichment trigger is installed in this patch. The
existing legacy webhook call to this detail service remains after its assignment
transaction and retains its existing exception handling.

The minimum shared hook for enrolled assignments is inside the existing
resolveFromPostEvidence() attachment branch, after the operation is linked to its
new VA: register an after-commit callback (or an existing queue job dispatched
after commit) that reloads that VA and calls this same service. It must be
discarded on rollback, run no HTTP inside the ownership transaction, and contain
its failures so a committed assignment cannot be downgraded. A process crash
between commit and callback also needs a reviewed read-only retry policy.

Installing that hook changes the shared path used by direct POST resolution,
reconciliation and webhook recovery. Under section E of the task, that integration
is reported for technical-lead review instead of independently changing those
ownership paths. The only ledger change here is a read-only proof accessor.
There is no new job, scheduler, enrichment engine or feature activation. Therefore
automatic enrichment for enrolled assignments remains an explicit dependency,
not a claimed completed behavior.

## Tests authored — not executed

NiumVirtualAccountDetailServiceTest covers:

- V1 and V2 frozen assignments read via GET V2; synthetic JPM_SG-style SWIFT and
  intermediary pairs; exact provider type versus internal WIRES.
- Pair 2 with a different actual type; missing pair 2; full-name fallback and later
  full-name enrichment.
- Changed frozen client, origin, customer, wallet and user before GET; target
  change during GET; contradictory envelope/item targets, bank, currency,
  category, inactive status and Payment ID aliases.
- Terminal-proof fingerprint rejection; a VA changed by an uncontrolled writer
  cannot borrow the operation's Payment ID proof; detected cross-account
  collisions; pending VA and in-transaction retrieval rejection; an older
  assignment's enrichment leaves a later unresolved scope untouched.
- Missing/mismatched Payment ID selection, later-page selection, duplicate IDs
  across pages, partial fills, empty/null preservation,
  404, timeout, redirect, malformed responses and read-only retry.
- Duplicate idempotence; sequential conflicting routing; incomplete routing pairs;
  deterministic overlap where a newer response saves before the older one.
- Legacy non-JPM account selection and the existing list's old/additive fields;
  hidden version, no evidence/credential exposure, anonymous and other-user
  access rejection.
- Every test forbids provider methods other than GET.

The existing PostgreSQL suite adds two actual forked-worker cases, one inserting
a first detail row and one updating an existing partial row. A socket barrier
ensures both pre-GET snapshots occur before either fake response completes.
Exactly one saves; the other receives the stale-version error. The partial-row
case also exercises a later GET filling the loser's missing facts. All Nium
responses are mocked. Existing disposable-database opt-in guards remain intact.

## Exact commands for the user to run

These commands have NOT been run by Codex. Run from the repository root.

Formatting/syntax assessment of the changed PHP files:

~~~bash
vendor/bin/pint --test \
  app/Models/NiumVirtualAccountDetail.php \
  app/Services/Nium/NiumVirtualAccountDetailService.php \
  app/Services/Nium/NiumVirtualAccountSafetyLedger.php \
  database/migrations/2026_10_27_000005_extend_nium_virtual_account_details.php \
  tests/Feature/NiumVirtualAccountDetailServiceTest.php \
  tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php
~~~

Focused SQLite and related regression selection, bypassing any cached application
configuration. Tests create only their isolated in-memory SQLite schemas.

~~~bash
NIUM_DETAILS_REVIEW_DIR="$(mktemp -d /tmp/nium-va-details-review.XXXXXX)"
mkdir -p "$NIUM_DETAILS_REVIEW_DIR/views"
APP_ENV=testing \
APP_CONFIG_CACHE="$NIUM_DETAILS_REVIEW_DIR/config.php" \
APP_ROUTES_CACHE="$NIUM_DETAILS_REVIEW_DIR/routes.php" \
VIEW_COMPILED_PATH="$NIUM_DETAILS_REVIEW_DIR/views" \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
vendor/bin/phpunit \
  tests/Feature/NiumVirtualAccountDetailServiceTest.php \
  tests/Feature/NiumVirtualAccountSafetyAuditTest.php \
  tests/Feature/NiumPaymentIdServiceTest.php \
  tests/Feature/NiumVirtualAccountV1AdapterTest.php \
  tests/Feature/NiumVirtualAccountSingleDispatchTest.php \
  tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php \
  tests/Feature/NiumVirtualAccountLedgerWebhookTest.php \
  tests/Unit/NiumVirtualAccountAssignmentContractTest.php
~~~

PostgreSQL race validation in a NEW disposable socket-only cluster. Run this
block in a separate shell so its exported database configuration does not affect
other commands. It requires PostgreSQL 18 binaries and PHP pcntl/pdo_pgsql.
The only migration command below targets the newly created isolated database.

~~~bash
set -e
NIUM_DETAILS_PG_BIN=/usr/lib/postgresql/18/bin
NIUM_DETAILS_PG_RUN="$(mktemp -d /tmp/nium-va-ledger-pg.XXXXXX)"
mkdir -p "$NIUM_DETAILS_PG_RUN/views"
export APP_ENV=testing
export APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
export APP_CONFIG_CACHE="$NIUM_DETAILS_PG_RUN/config.php"
export APP_ROUTES_CACHE="$NIUM_DETAILS_PG_RUN/routes.php"
export VIEW_COMPILED_PATH="$NIUM_DETAILS_PG_RUN/views"
export DB_CONNECTION=pgsql DB_HOST="$NIUM_DETAILS_PG_RUN" DB_PORT=55439
export DB_DATABASE=nium_va_ledger_test_hardened DB_USERNAME="$(id -un)"
export DB_PASSWORD='' DB_URL='' CACHE_STORE=array SESSION_DRIVER=array
export LOG_CHANNEL=stderr QUEUE_CONNECTION=sync NIUM_VA_LEDGER_PG_TESTS=1
"$NIUM_DETAILS_PG_BIN/initdb" -D "$NIUM_DETAILS_PG_RUN/data" -A trust --no-locale >/dev/null
"$NIUM_DETAILS_PG_BIN/pg_ctl" -D "$NIUM_DETAILS_PG_RUN/data" \
  -l "$NIUM_DETAILS_PG_RUN/server.log" \
  -o "-k $NIUM_DETAILS_PG_RUN -p $DB_PORT -c listen_addresses=''" -w start
trap '"$NIUM_DETAILS_PG_BIN/pg_ctl" -D "$NIUM_DETAILS_PG_RUN/data" -m fast -w stop' EXIT
"$NIUM_DETAILS_PG_BIN/createdb" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE"
php artisan migrate --database=pgsql --force
vendor/bin/phpunit tests/Feature/NiumVirtualAccountSafetyLedgerPostgresTest.php \
  --filter test_overlapping_detail_reads_cannot_overwrite_a_committed_winner
~~~

The full existing PostgreSQL class can be run against a separate freshly
prepared disposable database by removing the filter. No test result or assertion
total is claimed for this patch.

## Follow-up: reported focused-suite failures

The user reported 46 tests / 271 assertions, with 1 error and 24 failures in the
focused SQLite suite. The following corrections are based on source inspection;
no CLI commands, tests, migrations, formatters or Git checks were run for this
follow-up. A passing result is not claimed.

Files changed by this follow-up only:

- app/Services/Nium/NiumVirtualAccountDetailService.php
- tests/Feature/NiumVirtualAccountDetailServiceTest.php
- docs/nium-va-details-phase3c.md

Root causes and corrections:

1. **Production return-shape defect.** Eloquent's newly inserted detail instance
   did not contain unprovided nullable columns until reloaded. The bank_name
   accessor could supply a display fallback when accessed directly, while that
   absent column was omitted from toArray(). The existing service now refreshes
   a saved detail row before returning it. A new first-insert regression compares
   the returned object's own attributes with the persisted map and checks that
   bank_name is serialized, its raw full-name value remains null, the provider
   identifier supplies the display fallback, omitted routing fields remain null,
   and the internal version remains hidden. The migration is unchanged.
2. **Test snapshot defects.** Strict attribute-array comparisons mixed newly
   created in-memory models with reloaded rows. Database defaults and newly
   populated nullable ledger metadata changed the key set; incidental column
   ordering also made equivalent maps compare unequal. rowSnapshot() now reads
   the full persisted row, and canonicalFields() sorts its keys. Exact values,
   types, timestamps, versions and every column remain in the comparison.
   This applies to banking details, parent VA identity, terminal assignment and
   the later unresolved request/scope preservation checks. No protected fields
   are filtered out and no strict value comparisons are relaxed.
3. **Test HTTP-fake contamination.** Repeated Factory::fake() calls append
   callbacks, so the first catch-all callback could handle subsequent phases.
   A purported complete second response could still be the first partial
   response, and a purported 404/timeout/malformed response could still be the
   initial successful fixture. fakeHttp() installs a fresh HTTP factory per
   phase and restores stray-request prevention. Earlier recorded requests are
   retained for the teardown no-POST audit. Failure-path tests additionally
   check the request log's actual HTTP status (or null for timeout); successful
   later enrichment explicitly checks both the full name and secondary pair.

The sole production correction is post-save hydration. Frozen identity checks,
terminal evidence verification, conservative merges, stale-version checks,
ownership and scope rules, routing interpretation, dispatch and trigger paths
are unchanged. The schema, fixtures and PostgreSQL tests were not changed by
this follow-up.

User rerun command, using the same isolated SQLite setup as the reported run:

~~~bash
vendor/bin/phpunit tests/Feature/NiumVirtualAccountDetailServiceTest.php
~~~

Phase 3C remains unapproved pending rerun results and technical-lead review.

## Remaining blockers

- Validation is pending; implementation is not a test-pass or deployment claim.
- The enrolled-assignment after-commit trigger and durable read-only retry
  integration remain the explicit shared-path dependency above.
- H2 remains unresolved: known literal-ID collisions are rejected, but Nium's
  ownership/uniqueness domain and database-enforced enforcement across every
  historical/legacy/raw writer are not established.
- Provider account types remain provider facts. No GLOBAL -> WIRES or display-name
  -> JPM_SG mapping is approved by this patch.
- Conflicting existing details require a reviewed correction path; no forced
  overwrite, historical rewrite or assumed provider freshness is added.
- Legacy identity history, old-writer fencing, environment/secret routing, evidence
  retention and separately approved rollout remain Production/cutover concerns.
- Existing assignment gates remain disabled/testing-only. Phase 3D is not started.

Stop for technical-lead review.
