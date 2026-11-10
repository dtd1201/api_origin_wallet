# AUTO-02C Production fee-only runner — preparation for review

Status: source prepared; offline verification NOT executed; live inspection NOT
authorized or executed. This document grants no Production execution permission.
The staging runner's 53 tests / 217 assertions are an operator-reported baseline,
not a result obtained during this task.

## Scope and exact changed-file manifest

Four new files only:

1. `tools/nium-fee-evidence/ProductionFeeInspection.php`
2. `tools/nium-fee-evidence/collect-production.php`
3. `tests/Unit/NiumProductionFeeInspectionTest.php`
4. `docs/nium-auto02c-production-fee-runner.md`

No existing file is changed by this task. In particular, the staging runner,
shared projection, shared isolated transport, application, migrations, tests
already present, financial contracts and feature flags remain at their existing
state. Pre-existing dirty and untracked work is outside this manifest.

The new entry point can attempt only
`GET /api/v3/client/{clientHashId}/fees`. It does not bootstrap Laravel, read
`.env`, load cached application configuration, resolve `NiumService`, use a
database, dispatch jobs or use a payout/quote endpoint. It builds a temporary
private configuration repository and restores the previous Container in a
`finally` block. The Production configuration is never written to disk.

The isolated `NiumEntitlementEvidenceTransport` and
`NiumEntitlementEvidenceScope` are reused without modification. The existing
`FeeConfigurationProjection` supplies strict JSON decoding and allowlisted
projection. Only the staging helper's generic final redactor is reused; its
staging configuration and request paths are not used for Production.

## Risk assessment and boundaries

This reads real client-level Production pricing configuration. A GET still
creates provider-side access/audit activity, consumes an API request, discloses
the key to the approved provider over verified TLS and can encounter network or
provider failures. Redacted pricing output remains commercially sensitive.
Keep it in an approved restricted evidence location, not a public ticket or
general application log. Do not run with shell tracing or HTTP wire tracing.

The only local write by the runner is a permanent private approval-use receipt.
It is intentionally required to prevent reuse. There are no database, wallet,
transfer, beneficiary, quote, queue, application file or configuration writes.
Directory creation and approval preparation are separate operator actions.

The HTTP factory has no application events or global middleware. The request
uses verified TLS, fixed GET/path, fresh UUID request correlation, finite
timeouts, no cookies, no debug output, no redirects and no alternate endpoint.
The existing transport's `retry(1, 0)` specifies one total attempt, not one retry.
HTTP errors, redirects, connection errors and parsing errors fail closed; no
fallback request is attempted. If local validation fails there is no request.
Otherwise there is at most one application HTTP attempt. A timeout cannot prove
whether the provider received that GET; it never permits replay with the same
approval.

The independent private factory uses Guzzle underneath. The PHPUnit Laravel
HTTP facade stray-request guard is not a universal guard for private factories,
raw Guzzle/cURL, other clients or the operating system. The new offline tests
explicitly inject a guarded fake into this transport. No other transport is
introduced. This tool does not establish a host-wide network sandbox.

## Production-only configuration and credential loading

Required process environment names:

| Variable | Requirement |
| --- | --- |
| `APP_ENV` | Exactly `production` |
| `NIUM_BASE_URL` | Exact approved HTTPS Nium origin; optional single trailing slash only |
| `NIUM_CLIENT_ID` | Existing approved Production client UUID |
| `NIUM_API_KEY` | Existing Production API key matching the approved SHA-256 fingerprint |
| `NIUM_REGULATORY_REGION` | Exact approved uppercase two-letter region |
| `NIUM_FEE_PRODUCTION_APPROVAL_FILE` | Exact canonical private approval path described below |
| `NIUM_AUTH_MODE` | Optional; if supplied must be `header`; default `header` |
| `NIUM_AUTH_HEADER_NAME` | Optional; if supplied must identify `x-api-key`; default `x-api-key` |

Existing Production credentials must remain on the Production host. Use the
already approved secret-injection mechanism to make them available only to this
manual process. The runner does not fetch secrets, source files, inspect `.env`
or inherit Laravel configuration as a fallback. If process environment injection
is not already available, preparation is blocked until an approved secure
method exists. Do not copy a key or real client ID to local development or put
credentials on a command line, into shell history, this document, the approval
file or test fixtures. Do not dump the environment to diagnose missing values.

The request sends `x-api-key`, `x-request-id` and JSON acceptance headers. No
header, URL containing a client ID, raw response, exception message or trace is
printed by the entry point. The final shared redactor also removes occurrences
of the supplied key/client value from the projected output. Error output is a
fixed sanitized failure envelope, including when PHP emits a warning: warnings
are converted into failure exceptions, not suppressed or accepted as success.

## Approval storage, schema and one-use ownership

The CLI fixes its control directory to:

`/var/lib/origin-wallet/nium-fee-inspection`

There is no CLI override. The directory override on the helper's `open` method
exists exclusively for offline tests with synthetic identities and temporary
directories.

Before any separately approved execution, the operator must provision that
canonical directory outside the checkout and every web-served directory, owned
by the execution UID with mode `0700`. It must not be a symlink. The approval
must be a regular non-symlink direct child, mode `0600`, owned by that UID. The
runtime UID must differ from the declared web runtime UID. Independently verify
web-server aliases and all web/PHP runtime identities; mode checks and the
`outside_web_roots` attestation cannot discover deployment-specific aliases or
prove separation from every privileged identity. If this cannot be established,
do not execute. Do not relax the path/permission guards to fit a deployment.

Use a durable local filesystem with reliable exclusive creation (`O_EXCL`),
file `fsync` and directory `fsync`. Network filesystems, ephemeral containers, receipt deletion,
directory replacement or restoration of an old control-directory snapshot can
defeat the local one-use boundary and are not supported. Preserve receipts and
the directory identity. This is a single-host approval mechanism, not a
distributed authorization service.

The operator prepares one approval after Technical Lead authorization. The
file name is `approval-<approval_id>.json`. Exactly the following 20 members are
accepted; additional or missing members fail. The template below is deliberately
not runnable: substitute approved values on the Production host only.

```json
{
  "version": 1,
  "operation": "nium.production.fee_configuration.read.v1",
  "method": "GET",
  "path_template": "/api/v3/client/{clientHashId}/fees",
  "request_limit": 1,
  "app_environment": "production",
  "provider_environment": "production",
  "origin": "<independently approved exact HTTPS Nium Production origin>",
  "client_sha256": "<64 lowercase hex characters>",
  "api_key_sha256": "<64 lowercase hex characters>",
  "regulatory_region": "<approved uppercase two-letter region>",
  "approval_id": "<fresh lowercase UUIDv4, unrelated to the client ID>",
  "issued_at_utc": "<UTC timestamp YYYY-MM-DDTHH:MM:SSZ>",
  "expires_at_utc": "<UTC timestamp YYYY-MM-DDTHH:MM:SSZ>",
  "operator_uid": 1001,
  "web_runtime_uid": 33,
  "host_sha256": "<SHA-256 of the exact runtime hostname>",
  "approval_reference_sha256": "<SHA-256 of the separately approved review reference>",
  "outside_web_roots": true,
  "manual_execution_only": true
}
```

The UIDs shown are examples, not approved identities. Fingerprints must be
computed/verified without echoing secrets; their preparation is not automated
by the runner. The issue time must be current, expiry strictly in the future,
and total approval lifetime at most 900 seconds. The approval binds operation,
endpoint, one-attempt limit, both environments, exact origin, exact credential
and client fingerprints, region, hostname and operator UID. UUID and scalar
types are strict. JSON duplicate members and ambiguous structures are rejected.
No credential or full client ID belongs in the approval file.

This is a private operator-controlled approval capability, not a digitally
signed Technical Lead authorization. A process with the same UID, or root, can
alter approvals or receipts. Independent review, host access control and receipt
retention remain required. A fingerprint of a review reference does not verify
the review's signature or authenticity.

After opening the approval, the runner requires a real interactive terminal and
an exact challenge containing only the safe approval UUID:

`INSPECT PRODUCTION FEES <approval_id>`

It rechecks identity and expiry after the operator responds. Before the GET it
atomically creates `used-<approval_id>.json` with exclusive creation and mode
`0600`, writes and synchronizes the receipt and its parent directory, then
performs the request. If either synchronization fails, it stops without a GET
and leaves the approval consumed. A stale
second process cannot claim the same UUID. The receipt contains only operation,
approval UUID, claim time and the meaning
`one_provider_read_attempt_claimed_not_proof_of_http_dispatch`.

Once claimed, the approval is permanently consumed, including after a redirect,
invalid response, timeout, write failure, crash or unsuccessful GET. The runner
never removes or resets a receipt. The receipt is not proof of provider receipt,
HTTP success or financial correctness. Any new attempt requires separate review
and a new expiring approval UUID. A failure before consumption permits another
manual invocation only while the unchanged approval remains current; no network
attempt has then been authorized by a successful claim.

## Sanitized output

The output is JSON. It includes no raw response body or sensitive response
metadata. The following describes the schema; values are explanatory.

```text
schema_version: 1
status: OBSERVED | INCOMPLETE | FAILED
environment: production
endpoint_template: /api/v3/client/{clientHashId}/fees
approval_id: safe approval UUID (after a valid approval was opened)
approval_consumed: boolean
http_status: integer | null
request_id: fresh correlation UUID | null
retrieved_at_utc: UTC timestamp | null
failure: fixed sanitized code | null
fee_configuration: allowlisted projected plan/product/fee/rule objects
missing_fields: safe schema paths/reasons
unsupported_fields: safe schema paths/reasons
fee_collection: {status: UNVERIFIED}
financial_authority:
  customer_applicability: UNKNOWN
  transaction_fee: UNKNOWN
  nium_wallet_debit: UNKNOWN
  origin_wallet_debit: UNKNOWN
  admission: DENIED
  dispatch: DENIED
```

Pre-opening failures use a smaller envelope with no approval UUID and null
HTTP/correlation fields. Unknown failures are never printed as exception text.
The exit codes are `0` for OBSERVED, `2` for INCOMPLETE, `1` for FAILED.
OBSERVED means only that configuration was read and projected; it grants no
admission or dispatch authority and does not prove applicable/charged fees.

The shared projection hashes plan labels, allowlists product and fee names,
preserves exact decimal strings and exposes supported price type/currency/value
and applicability conditions without selecting a fee or tier. Unknown metadata
values and names are omitted in favor of safe unsupported-field diagnostics.
Numeric, ambiguous, malformed or unsupported amounts are never converted into
authoritative financial terms. Strict parsing rejects duplicate JSON members,
object/list substitution and oversized/deep structures. Projection has a
256-KiB input limit; the existing transport also limits response bodies, after
buffering. This is not a streaming hard memory-limit defense against an
arbitrarily large hostile provider response.

The currently documented v3 configuration does not establish fee collection
mode. Unknown extensions, even if present, are unsupported until their schema
is reviewed; the runner does not infer real-time or invoiced collection.
Configured client-level fees do not prove customer assignment, executed fees,
fee allocation, an exact Nium wallet debit or an Origin Wallet customer debit.
No fee is assumed zero or automatically added to principal; no FX margin is
assumed to be separately added to the quoted rate.

## Offline verification — operator only

Prepared tests cover exact approval/environment/fingerprint binding, missing
and extra approval members, expiry and lifetime, filesystem permissions,
symlinks, duplicate JSON members, filename binding, one GET, correlation UUID,
TLS/redirect/debug options, error/redirect/connection/JSON failures, consumption
before HTTP, stale-object replay, persisted replay rejection, final credential
redaction, exact decimal strings, container restoration and forbidden application
autoloads. All credentials and identifiers in these tests are synthetic. Only
test-created temporary directories are cleaned up.

Shared successful fee projection and staging rejection regressions remain in
the existing staging suite. The new tests do not independently establish that
the real Production payload matches the documented schema. No live request or
CLI subprocess belongs in offline verification. No new process-concurrency
test was executed: stale-reader coverage exercises the shared exclusive-claim
boundary, while deployment filesystem semantics still require operator review.

Proposed offline command, for the operator after source review (NOT executed):

```bash
./vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-warning --fail-on-risky --fail-on-skipped tests/Unit/NiumProductionFeeInspectionTest.php tests/Unit/NiumStagingFeeInspectionTest.php
```

Run with no real Production credentials. This command uses isolated HTTP fakes,
does not use the application's database and does not execute the CLI runner.
Any warning, skipped/risky test or failed guard is a verification failure.

## Proposed live procedure — not authorized by this task

1. Obtain Technical Lead review of these four files and the offline operator
   result. Separately authorize one Production fee read, exact host/origin/client
   and credential fingerprints, execution UID, evidence destination and window.
2. On that approved Production host only, verify the reviewed artifacts and
   dependencies are available. This document does not authorize copying code,
   deploying, installing dependencies, restarting an application, editing cache
   or configuration, or changing any feature flag.
3. Verify directory/receipt durability, canonical paths, ownership, permissions,
   web exclusion, actual runtime identities, correct UTC clock, approved secret
   injection and the fresh private approval file. Retain previous receipts.
4. Use a manual interactive terminal, never cron, a job worker, deployment
   automation, a web route or piped confirmation. The proposed invocation from
   the reviewed repository is:

   ```bash
   php tools/nium-fee-evidence/collect-production.php --inspect-production-fees-once
   ```

5. Inspect the safe approval UUID prompt and confirm only the independently
   approved operation. Store only the sanitized output and exit status in the
   approved evidence location. Retain the private consumed receipt. Do not
   enable wire logs or print the environment/raw body to diagnose failures.
6. On any failure, stop and report the sanitized code/correlation information.
   Never delete the receipt or silently retry. Have the Technical Lead review
   evidence before any newly approved attempt or subsequent implementation.

## Remaining blockers and recommendation

Offline tests, PHP syntax/runtime compatibility, real host isolation, exact
Production identity approval, private storage provisioning, secure environment
injection and the actual provider schema remain unverified in this task.
POSIX identity/TTY functions, `fsync` support, local filesystem semantics, CA
trust and reviewed dependency availability are prerequisites. The test seams do
not independently prove deployment network behavior or absence of all privileged
instrumentation on the Production host.

Even successful collection cannot remove the authoritative-admission blockers:
fee allocation/collection, customer/corridor applicability, exact debit evidence
and applicable contractual interpretation still need verified authoritative
evidence. AUTO-02B quote uniqueness/idempotency, default-deny admission,
default-OFF automatic dispatch, HK SWIFT behavior, SGD LOCAL HOLD and historical
Transfer #5 HOLD are unchanged. No admission adapter is implemented.

Recommendation: PASS for preparation-only Technical Lead review; NO-GO for live
execution until offline verification and separate explicit authorization. No
runtime PASS is claimed.

Execution disclosure: an initial read-only shell batch opened the attached
request and inspected status/file names before its shell prohibition was seen.
That mistake was disclosed in commentary. No further shell commands were used;
subsequent source preparation used patch tools only. No PHP, tests, provider
requests, database operations, deployments, commit or push were executed. The
no-shell restriction also prevented a fresh shell-based baseline reread, diff
or checksum verification. Existing-source details retained from prior inspection
and the inherited work summary guided reuse; operator source review remains
necessary.
