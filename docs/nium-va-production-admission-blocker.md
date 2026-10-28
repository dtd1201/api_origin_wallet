# Historical NIUM VA Production admission report

Superseded by [Production release and runtime cutover](nium-va-production-release.md).
The text below records the earlier testing-only state, before the legacy evidence
fixes in `45c1f75` and the default-OFF Production admission change. It is not the
current deployment instruction.

Production admission was not implemented. The requested verifiable legacy-writer
fence cannot be established by the existing repository controls. Adding a new
default-OFF flag and accepting `old_workers_fenced=true` would not satisfy the
requested safety condition. The existing testing-only restriction remains intact.

Only this report is added by this task. Existing work is preserved. No application,
configuration, test, migration, deployment or Production environment was changed.
No tests/builds or NIUM requests were run; nothing was committed or pushed.

## Precise blocker and source evidence

- `app/Services/Nium/NiumVirtualAccountAssignmentService.php:97` checks the pause,
  testing environment, enabled flag and `old_workers_fenced` boolean. It has no
  authoritative process/cutover verification. `config/services.php:139` defaults
  allocation paused=true, enabled=false and old_workers_fenced=false.
- `app/Services/Nium/NiumVirtualAccountSafetyLedger.php:338` inventories existing
  unresolved legacy operations. This is a history barrier, not a fence against a
  legacy writer creating or completing work afterward. Dispatch authorization is
  durably consumed before the provider POST; an already-authorized worker is not
  revoked merely by changing a flag.
- `app/Services/Nium/NiumVirtualAccountReconciliationService.php:145` rejects an
  enrolled operation but permits legacy operations to attach/create VA ownership.
  Its transaction locks the operation, checks ownership within that account, and
  does not acquire the ledger's Payment ID advisory lock. It remains reachable
  through `app/Console/Commands/ReconcileNiumPaymentIdOperation.php:15`. GET-only
  provider transport does not make these local ownership writes read-only.
- `app/Services/Nium/NiumVirtualAccountSafetyLedger.php:385` explicitly limits its
  advisory lock to participating writers. Current legacy webhook handling joins
  that lock, but legacy reconciliation and historical/raw writers do not all do
  so. A different account's legacy resolver can write the same Payment ID after
  the ledger's collision check, defeating its ownership arbitration.
- `database/migrations/2026_10_27_000002_support_nium_va_single_dispatch.php` guards
  operation updates conditional on ledger enrollment. These invariants do not
  constitute a global ban on writes by legacy runtimes or their provider POSTs.
- `deploy/supervisor/api.originwallet.asia-worker.conf:4` runs a general queue
  worker with autostart/autorestart enabled. There is no cutover identity or
  admission-verifiable proof that old runtimes stopped and cannot restart. This
  describes repository configuration, not an observation of deployed processes;
  Production infrastructure was not accessed.

New PHP guards or a shared advisory lock cannot fence old binaries that do not
honor them. A database-only fence also cannot revoke permission from a worker
already past durable authorization and about to call NIUM.

## Minimum prerequisite to resume admission implementation

Provide a reviewed, enforceable operational cutover that removes old runtimes'
ability to allocate or mutate VA ownership, prevents their restart, and produces
authoritative evidence the admission path can verify. Include old web/CLI/queue/
scheduled entry points and already-authorized/in-flight requests. Review retained
legacy operations and ownership before admitting all eligible customers. A manual
declaration, configuration flag, timestamp or empty legacy-history query is not
that evidence. Infrastructure enforcement/verification is not currently wired
into this application; choosing its mechanism needs technical-lead review.

Once that prerequisite exists, the narrow code change is to admit only the
confirmed V1 corridor in Production behind a default-OFF admission gate, the
independent default-ON pause, verified cutover evidence and all existing account/
ledger checks. Add no customer allowlist. Webhook and GET V2 recovery must remain
independent of admission. Production-ON tests must mock a real verification
contract; no such successful verification contract is invented in this task.

## Behavior retained and user-run checks

The confirmed USD / SELF_FUNDING_ACCOUNT / JPM_SG / WIRES routing, exact three-field
V1 body, no mandatory preflight GET, durable single dispatch, immutable history,
uncertain-outcome blocking, authenticated webhook receipt and GET V2 recovery are
unchanged. New ordinary requests in this corridor still enter the enrolled gate
when disabled; historical preflight-failed keys cannot dispatch legacy V2
(`app/Services/Nium/NiumPaymentIdService.php:72`).

Existing mock suites cover Production rejection, pause on/off, legacy-history
blocking, no V2 fallback, no uncertain retry, and receipt/GET recovery during a
pause. They do not prove an operational fence or successful Production admission.
No new Production-OFF/ON tests were authored because implementing an ON case would
require the missing verification contract.

From `/home/dtd1201/projects/api_origin_wallet`, user-run only:

```sh
vendor/bin/phpunit tests/Feature/NiumVirtualAccountCorridorTest.php tests/Feature/NiumVirtualAccountV1AdapterTest.php tests/Feature/NiumVirtualAccountLedgerWebhookTest.php tests/Feature/NiumVirtualAccountLedgerReconciliationTest.php
```

Keep allocation disabled and paused. Stop here for technical-lead review.
