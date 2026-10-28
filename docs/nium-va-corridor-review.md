# NIUM VA confirmed corridor — technical-lead review

This supersedes the account-specific routing in `nium-greentech-v1-review.md`.
Routing is customer-independent. **Production allocation remains disabled.**
No tests or builds were run, no real NIUM calls were made, and no Production
environment, migrations, deployment, commit or push was performed.

## Exact task files

Backend paths are relative to `api_origin_wallet`:

| File | Change |
| --- | --- |
| `app/Services/Nium/NiumVirtualAccountAssignmentContract.php` | Central predicate for the confirmed corridor. |
| `app/Services/Nium/NiumPaymentIdService.php` | Remove account ID selection; select V1 by corridor; prevent legacy V2 fallback, including historical preflight-failed keys in that corridor. |
| `app/Services/Nium/NiumVirtualAccountAssignmentService.php` | Admit any eligible account for this V1 corridor in isolated testing; add allocation pause and read-only admission reason; keep testing restriction. |
| `app/Services/Nium/NiumProviderAccountStateService.php` | More precise VA eligibility reasons for restrictions and security review; eligibility predicate unchanged. |
| `app/Http/Controllers/Api/User/ProviderAccountController.php` | Expose allocation admission separately from account eligibility on the integration index. |
| `config/services.php` | Remove GREENTECH selector; default allocation pause to true; retain disabled allocation and cutover controls. |
| `phpunit.xml` | Mock tests default the new pause to false; other activation gates remain enforced. |
| `tests/Feature/NiumGreentechV1RoutingTest.php` → `tests/Feature/NiumVirtualAccountCorridorTest.php` | Rename and extend synthetic HTTP/database tests for reusable routing and safety. |
| `tests/Feature/NiumVirtualAccountV1AdapterTest.php` | Replace the removed corridor account allowlist interlock with allocation pause coverage. |
| `tests/Feature/NiumVirtualAccountEligibilityProjectionTest.php` | Expect the explicit security-review eligibility reason. |
| `docs/nium-va-corridor-review.md` | This handoff. |

Frontend paths are relative to `origin-wallet-platform`:

| File | Change |
| --- | --- |
| `src/pages/account/AccountVirtualAccountRequest.tsx` | Only confirmed choices for new requests; Wires default; preserve saved parameters; show disabled reasons; keep status checking available. |
| `src/services/providerAccountService.ts` | Type the separate admission reason and provide user-facing allocation messages. |
| `src/lib/virtualAccountLifecycle.ts` | Recheck admission before reserving a new key; missing capability data fails closed; existing-operation GET recovery remains available. |
| `src/pages/account/AccountVirtualAccountRequest.test.tsx` | Corridor defaults, disabled reasons, pause and retained-intent coverage. |
| `src/lib/virtualAccountLifecycle.test.ts` | Paused/unavailable allocation cannot reserve a key or POST; retained operations still use GET. |
| `src/test/virtualAccountFixtures.ts` | Include admission status in the mock integration response. |
| `tests/browser/va-fixtures.ts` | Include admission status in browser mocks. |
| `tests/browser/va-backend.ts` | Expect Wires for new requests and persisted browser intent. |
| `tests/browser/virtual-account-lifecycle.e2e.ts` | Expect Wires in new-request concurrency assertions. |

No ledger, webhook, reconciliation, detail service, queue, migration, KYB, RFI or
transfer implementation was changed. `tatus --short` remains untouched.

## Corridor and identity

For a new ordinary request, normalized USD + SELF_FUNDING_ACCOUNT + local WIRES
intent selects V1. A missing caller bank is fixed server-side to JPM_SG; an
explicit bank must be JPM_SG for this selection. No account ID, customer name or
allowlist membership identifies this business corridor. The old GREENTECH config
key is removed and has no effect.

The existing adapter constructs exactly:

```http
POST /api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/paymentId
```

```json
{"accountCategory":"SELF_FUNDING_ACCOUNT","currencyCode":"USD","bankName":"JPM_SG"}
```

There is no `accountType` in this body and no mandatory GET before POST. WIRES
remains frozen local intent, not proof of the rail NIUM provisions. Origin/client
come from existing validated configuration; customer/wallet come from the current
verified eligible provider account. Each account keeps its own operation keys,
scope, frozen identity, request UUID and evidence.

Saved enrolled operations retain their frozen V1/V2 version, bank and payload.
Saved legacy operations are not enrolled or rewritten. Uncertain results never
reauthorize POST. A legacy preflight-failed key for the confirmed corridor now
fails closed even when the single-dispatch flag is disabled.

Other pre-existing internal/legacy adapter paths remain unchanged outside this
corridor; this change gives them no new Production admission. Explicit internal
version overrides remain test adapters behind their existing account allowlist.
The frontend offers no new currencies, banks, categories or rails. Saved older
parameters remain visible as disabled saved choices, and cannot silently become
a new unsupported request.

## Eligibility and admission

The existing authoritative eligibility predicate still requires the current NIUM
account to be active, provider status clear, no provider sub-status, verified
nonempty customer/wallet IDs, and no security conflict. Pending, restricted,
unverified and conflicted accounts remain blocked. Internal KYB approval alone
cannot authorize allocation.

Account eligibility is separate from allocation admission. The index retains
`can_request_virtual_account` / `virtual_account_eligibility_reason` and adds
`virtual_account_allocation_reason`. The UI requires both eligibility and a null
allocation reason; a missing field fails closed. Fresh admission is checked again
before creating browser intent, and the backend is authoritative at assignment.

New enrolled allocations require all of:

1. `services.nium.va_single_dispatch.allocation_paused === false` (default true,
   sourced from `NIUM_VA_ALLOCATION_PAUSED`).
2. Application environment `testing`, unchanged from the existing implementation.
3. `va_single_dispatch.enabled === true` (default false).
4. `va_single_dispatch.old_workers_fenced === true` (default false).
5. Existing provider configuration, committed-transaction, legacy-history,
   ownership, scope and durable authorization checks.

For the confirmed V1 corridor, the account ID allowlist is no longer required.
Other historical test adapters retain it. This does not add a Production path:
even clearing the pause and setting every flag still cannot dispatch in
Production. Do not change APP_ENV to evade the restriction.

The pause is an admission control for enrolled allocation, **not a fence for old
processes or legacy writers**. It does not revoke an authorization already durably
consumed by an in-flight worker. A pause/cutover therefore also requires draining
workers that already hold dispatch authority. Webhook receipt, saved-operation
reads and GET-only reconciliation do not consult this allocation gate.

## Required cutover before Production admission

The smallest future code admission change would allow the exact confirmed V1
corridor in an explicitly reviewed Production branch of the existing admission
guard, still behind the independent allocation pause and eligibility/ledger checks.
It must leave other adapters testing-only. That branch is deliberately **not
implemented** because the following operational collision remains unresolved:

- `NiumVirtualAccountReconciliationService::assign()` can attach legacy VA ownership
  without the ledger Payment ID advisory lock. Old assignment/command workers and
  other legacy allocation sources can also remain live. A flag named
  `old_workers_fenced` does not stop them. The account-scoped database uniqueness
  constraint alone does not establish cross-account provider ownership.
- Before admission, stop/drain the actual legacy assignment and reconciliation
  processes for every admitted wallet and prevent replacement old processes from
  starting. Review automatic provider allocation sources too. With all eligible
  customers admitted, this is a cutover-wide prerequisite, not one company ID.
- Inventory historical requests, missing/aliased wallet identities, existing VA
  ownership and consumed/in-flight attempts. Resolve or quarantine uncertainty
  without another POST. Existing unresolved legacy requests continue to block
  ledger preparation. Preserve the same durable database/history during cutover.
- Verify the exact provider origin/client/customer/wallet target and the confirmed
  corridor's entitlement for admitted accounts. Review an operation-specific
  provider/support recovery procedure for INITIALIZED or lost successful evidence.

Authenticated webhook evidence is preserved as before. For enrolled allocations,
webhook/GET observations alone cannot establish creation or ownership without the
required causal POST evidence. A timeout, INITIALIZED or missing proof can remain
pending/unknown/manual review; no new resolver or resend is added. A later GET
continues to use V2. Automatic banking-detail enrichment retains its separate
testing-only gate; Production assignment does not imply Production enrichment.
For cross-origin UI use, verify deployed CORS allows Idempotency-Key.

## User-run validation

No test/build/lint command was run. Source review and whitespace diff checks were
performed. Tests use fake HTTP, synthetic targets and private in-memory SQLite;
the test-only pause override does not change any deployed environment.

From the backend:

```sh
vendor/bin/phpunit tests/Feature/NiumVirtualAccountCorridorTest.php tests/Feature/NiumVirtualAccountV1AdapterTest.php tests/Feature/NiumVirtualAccountEligibilityProjectionTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php
```

From the frontend:

```sh
npm test -- src/pages/account/AccountVirtualAccountRequest.test.tsx src/lib/virtualAccountLifecycle.test.ts src/hooks/useVirtualAccountOperation.test.tsx src/services/providerAccountService.test.ts
```

Optional existing browser regression command (also not run): `npm run test:va:browser`.
Stop for technical-lead review; allocation remains disabled.
