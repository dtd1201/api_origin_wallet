# Minimal GREENTECH NIUM V1 routing — technical-lead review

Status: implementation prepared; **real assignment remains blocked**. No tests,
builds, live NIUM requests, Production activation, commit, push or deployment were
performed for this change. Existing Phase 1–3D work is preserved. This is not
Phase 4.2A or the production-readiness remediation project.

## Why the normal request selected V2

`ProviderAccountController::assignVirtualAccount()` passes currency, category,
local account type, a null bank, the existing Idempotency-Key and
`autoSelectBank: true`. It does not pass an API version. Previously,
`NiumPaymentIdService::assign()` defaulted to `v2`; the legacy assignment endpoint
configuration also defaults to V2. The existing V1 adapter was reachable only by
an explicit service argument. The ordinary bank resolver only maps HK/USD to
DBS_HK, so it did not select JPM_SG either. Changing the global endpoint alone
would retain the wrong V2 body and affect unrelated requests.

## Exact change boundary

Only these files are changed by this task, relative to the inherited dirty tree:

- `app/Services/Nium/NiumPaymentIdService.php`: narrow server-side selection for
  new operations; omitted versions resume an enrolled operation's frozen version.
- `config/services.php`: an unset-by-default GREENTECH account selector.
- `tests/Feature/NiumGreentechV1RoutingTest.php`: synthetic identity, private
  in-memory SQLite and mock HTTP coverage; stray outbound requests are forbidden.
- `docs/nium-greentech-v1-review.md`: this review handoff.

No controller, adapter, ledger, webhook, migration or frontend change is needed.
No gate is weakened. The file named `tatus --short` is untouched.

## Selection and request

Selection requires all of the following for a **new** operation with no explicit
internal version override:

- The current eligible account's exact `user_provider_accounts.id` equals server
  configuration `services.nium.greentech_v1_account_id`, sourced from
  `NIUM_GREENTECH_V1_ACCOUNT_ID`.
- Currency USD, category SELF_FUNDING_ACCOUNT, and existing local intent WIRES.
- No explicitly supplied bank, or the exact bank JPM_SG.

The selector fixes the effective bank to JPM_SG before entering the existing
single-dispatch orchestrator. No customer-name matching, request-supplied account
selector, global V1 default, new identity registry or bank resolver change is used.
Other accounts/currencies/categories/rails/banks retain the existing V2 behavior.
Explicit internal adapter calls retain their prior version validation behavior.

GREENTECH's verified account ID could not be established from source. The selector
has therefore **not been configured**. Review must supply the existing verified
row ID; do not infer it from a fixture, company display name or staging account.
Customer and wallet IDs continue to come from that row's verified identifiers;
client ID and provider origin continue to use existing validated configuration.

The frontend continues to send its saved Idempotency-Key, expected account ID,
currency, category and local account type. For this narrow corridor, select
**Wires** in the existing form (the current form default is Local). This retains
the existing adapter's reviewed USD/JPM_SG/Wires intent without assuming additional
bank/rail support. The local WIRES value is frozen in the identity and operation;
it is not a V1 provider field or proof of the rail NIUM actually provisions.

The existing V1 adapter constructs:

```http
POST /api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId
```

```json
{"accountCategory":"SELF_FUNDING_ACCOUNT","currencyCode":"USD","bankName":"JPM_SG"}
```

The body contains exactly these three fields. JSON key order is immaterial. There
is no `accountType` or `currency` field. There is no GET before this POST.

## Durable operation and evidence behavior

The existing orchestrator prepares the immutable target/payload/request UUID,
consumes dispatch authorization with a durable top-level commit, then reaches its
single POST site. Request/response evidence and resulting state remain persisted
through the existing code. A valid, consistent successful POST ID can attach a VA
only through the existing ownership checks. INITIALIZED stays pending; timeouts,
unrecognized results and failures remain uncertain and retain scope. Replaying a
key returns state, and a replacement key cannot bypass an unresolved scope.

Existing legacy operations keep the legacy path and are never enrolled or
re-versioned. Existing enrolled operations keep their frozen version and bank,
including after selector removal or disabling the feature. Explicit version or
payload mismatches still fail the immutable identity check. Existing keys, locks,
dispatch attempts, webhook receipts and operation rows are not rewritten.

Authenticated webhook ingress remains unchanged: invalid partner authentication
is rejected, immutable evidence is retained, and duplicate deliveries cannot
dispatch. For enrolled requests, a webhook corroborates exact causal POST evidence;
its payment ID alone cannot establish allocation ownership. It makes no outbound
provider call. Later reconciliation and banking details use the existing GET V2
contract against the frozen verified target, without a POST retry. GET listing
membership alone cannot assign the VA to this operation.

## Specific blockers before one controlled real assignment

1. Identify the existing verified GREENTECH row and confirm its client/customer/
   wallet target and Wires intent. The new selector is deliberately unset.
2. `NiumVirtualAccountAssignmentService::assertEnabled()` still requires all four:
   enabled=true, application environment **testing**, old_workers_fenced=true,
   and an integer account ID in the allowlist. Defaults are false/false/empty.
   Even setting every configuration flag cannot dispatch in Production because
   of the explicit testing-only condition. No value was activated. A separately
   reviewed narrow cutover decision is needed; removing the environment check
   alone is not sufficient safety evidence.
3. Establish an actual fence for competing assignment/legacy reconciliation
   writers for this wallet, and inventory its prior requests/VA ownership before
   authorizing consumption. The existing legacy reconciliation writer does not
   share every ledger ownership lock; an allowlist flag alone does not fence it.
   Unresolved historical operations also block ledger preparation. The smallest
   prerequisite is a verified fence and reviewed wallet-specific history, not a
   new ownership registry or broad migration.
4. Agree a provider/support recovery procedure for INITIALIZED or lost successful
   POST evidence. The current resolver requires the exact persisted successful
   POST ID; a later webhook/GET cannot independently prove creation. Provider
   bank/type display fields also lack a verified mapping to the POST fields.
   Until an operation-specific evidence contract is reviewed, retain pending/
   unknown/manual-review state, show no assigned VA, and never retry the POST.

Banking-detail enrichment has a separate existing testing-only gate; assignment
success does not promise automatic Production detail enrichment. This change does
not enable it. For cross-origin UI use, also verify the deployed CORS configuration
allows `Idempotency-Key`; the source default does not list it.

## User-run validation only

No test/build/lint command was executed. The focused new suite covers exact API
routing/body/URL, no GET before POST, one dispatch, immutable replay, uncertain
outcomes, nonselected legacy corridors, saved V2/legacy operations, authentication,
V2 GET reconciliation, account/key checks and dispatch interlocks. Existing adapter,
webhook, reconciliation and detail suites retain their deeper evidence coverage.

Run from `/home/dtd1201/projects/api_origin_wallet`:

```sh
vendor/bin/phpunit tests/Feature/NiumGreentechV1RoutingTest.php tests/Feature/NiumVirtualAccountV1AdapterTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php tests/Feature/NiumVirtualAccountDetailServiceTest.php
```

These suites use private in-memory SQLite and mock HTTP. No real assignment CLI
command is supplied. Stop here for technical-lead review.
