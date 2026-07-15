# Nium Phase 1 Sandbox E2E Runbook

This runbook is executable after Nium supplies sandbox credentials. It covers
Customer Onboarding V5 and its security/lifecycle controls only. It does not
exercise beneficiary mapping, FX, payout payloads, payout lifecycle, or wallet
transaction sync.

## One-time setup

Export values without committing them:

```bash
export API_BASE_URL='http://127.0.0.1:8000/api'
export USER_ID='<approved-origin-wallet-user-id>'
export USER_TOKEN='<origin-wallet-user-token>'
export ADMIN_TOKEN='<origin-wallet-admin-token>'
export NIUM_BASE_URL='https://gateway.nium.com'
export NIUM_CLIENT_ID='<sandbox-clientHashId>'
export NIUM_API_KEY='<sandbox-api-key>'
export NIUM_PARTNER_KEY='<sandbox-x-partner-key>'
```

Configure the backend with the exact Phase 1 variables documented in
`docs/nium-go-live.md`, run the exact Phase 1 migration shown below, and confirm
the user has approved internal KYC/KYB plus an approved Nium provider
submission.

```bash
php artisan migrate \
  --path=database/migrations/2026_07_14_000002_add_provider_onboarding_state_to_user_provider_accounts.php \
  --force
php artisan nium:smoke-test
```

The smoke test is configuration-only by default. It sends the authenticated
Get Client request only with `--live`. Validate the separate transaction
compliance callback only when required:

```bash
php artisan nium:smoke-test --live
php artisan nium:smoke-test --compliance-callback
```

## Verified Phase 1 application routes

These routes come from the current `php artisan route:list --json` output.
`{provider}` uses the provider code, so Nium requests use `nium`.

| Operation | Method and URI | Authentication | Success | Expected failures |
| --- | --- | --- | --- | --- |
| Provider list/status | `GET /api/providers` | Public API middleware; no bearer token | `200`, body contains `data[]` and Nium configuration/capability flags | Unexpected application/database failure: `500` |
| User Nium onboarding/link | `POST /api/user/users/{user}/provider-accounts/nium/link` | Bearer user token; token user must match `{user}`; completed profile middleware | `200`, body contains `provider_account` and `onboarding` | `401` missing/invalid token; `403` wrong user; `409` incomplete profile; `404` unknown user/provider; controlled `422` for KYC, provider configuration, or Nium failure |
| Nium webhook receiver | `POST /api/webhooks/providers/nium` | `x-partner-key` plus non-empty `x-request-id` for customer lifecycle events | `200`; duplicate delivery also `200` with `duplicate=true` | `403` invalid partner key or mismatching `clientHashId`; `404` unknown provider; controlled `422` for invalid envelope or failed reconciliation |
| Admin provider health/Get Client | `POST /api/admin/provider-health/nium/check` | Bearer admin token | `200`, body contains `provider_health` | `401` missing/invalid token; `403` non-admin; `404` unknown provider; `422` unsafe configuration, network failure, or non-success Nium response |
| Admin user/provider sync | `POST /api/admin/providers/nium/users/{user}/sync` | Bearer admin token | `200`, body contains authoritative `provider_account` | `401` missing/invalid token; `403` non-admin; `404` unknown user/provider; controlled `422` for KYC, configuration, or Nium reconciliation failure |
| Admin webhook retry | `POST /api/admin/provider-webhook-events/{providerWebhookEvent}/retry` | Bearer admin token | `200`, body contains the reprocessed `webhook_event`; successful synchronous retry has status `processed` | `401` missing/invalid token; `403` non-admin; `404` unknown/non-Nium event; `422` already processed or retry failed |

## Repository-backed deployment values

The repository deployment configuration currently defines the following
defaults. Confirm each value on the staging host before running commands;
`APP_DIR` and `PHP_VERSION` can override the bootstrap defaults.

| Value | Repository default | Evidence | Staging action |
| --- | --- | --- | --- |
| Release directory | `/var/www/origin_wallet` | `deploy/scripts/bootstrap_vps.sh`, Nginx and Supervisor configs | Confirm the deployed checkout path |
| PHP-FPM service | `php8.3-fpm` | bootstrap default `PHP_VERSION=8.3` and Nginx socket `php8.3-fpm.sock` | Confirm with `systemctl status php8.3-fpm`; substitute the installed service if overridden |
| Supervisor program | `origin_wallet_worker` | `deploy/supervisor/api.originwallet.asia-worker.conf` | Confirm with `supervisorctl status origin_wallet_worker:*` |
| Public application health | `GET /api/test` | `routes/api.php` | Expect `200` with `{"message":"API working"}` |
| Nium provider health | `POST /api/admin/provider-health/nium/check` | `routes/admin.php` | Requires admin bearer token and performs Get Client |
| Webhook receiver | `POST /api/webhooks/providers/nium` | `routes/api.php` | Publicly reachable over TLS; authenticated by partner key |

Verification commands for the repository defaults:

```bash
cd /var/www/origin_wallet # confirm APP_DIR on staging first
sudo systemctl status php8.3-fpm --no-pager # confirm PHP_VERSION first
sudo supervisorctl status 'origin_wallet_worker:*'
curl --fail-with-body "${API_BASE_URL%/api}/api/test"
php artisan route:list --path=api/webhooks/providers
php artisan migrate:status
```

Exact rollback for the Phase 1 migration:

```bash
php artisan migrate:rollback \
  --path=database/migrations/2026_07_14_000002_add_provider_onboarding_state_to_user_provider_accounts.php \
  --force
```

Useful database queries (PostgreSQL):

```sql
SELECT id, user_id, provider_id, external_reference, external_customer_id,
       external_account_id, status, provider_status, provider_sub_status,
       customer_id_verified_at, wallet_id_verified_at,
       reconciliation_status, security_conflict_at
FROM user_provider_accounts WHERE user_id = :user_id;

SELECT event_id, event_type, processing_status, error_message, processed_at
FROM webhook_events ORDER BY id DESC LIMIT 20;

SELECT action, entity_id, old_data, new_data, created_at
FROM audit_logs WHERE action LIKE 'provider_account.nium_%'
ORDER BY id DESC LIMIT 20;
```

Cleanup for every test uses only sandbox records: delete the test user in
Origin Wallet (cascade removes local account/events tied to it where defined),
or restore the database snapshot created before the test. Ask Nium support to
close test customers that cannot be deleted through sandbox APIs. Never reuse
the same `externalId` for a different local user.

## 1. API authentication and Get Client

- Prerequisites: valid sandbox clientHashId/API key; backend configuration
  cache rebuilt.
- Request:

```bash
curl --fail-with-body --request GET \
  "$NIUM_BASE_URL/api/v1/client/$NIUM_CLIENT_ID" \
  --header "x-api-key: $NIUM_API_KEY" \
  --header "x-request-id: phase1-get-client-001"
```

- Expected HTTP: Nium `200`; missing/invalid API key is `401/403`. Backend
  admin health check returns operational only on the authenticated `200`.
- Backend health request:

```bash
curl --fail-with-body --request POST \
  "$API_BASE_URL/admin/provider-health/nium/check" \
  --header "Authorization: Bearer $ADMIN_TOKEN"
```

- Expected DB: one allowlisted `api_request_logs` row when the backend health
  endpoint is used; no API key, clientHashId, or PII in serialized log fields.
- Integration state: unchanged.
- Audit: no provider-account state audit.
- Cleanup: none.

## 2. Create Customer V5

- Prerequisites: approved local KYC/KYB; no Nium account for the test user.
- Request:

```bash
curl --fail-with-body --request POST \
  "$API_BASE_URL/user/users/$USER_ID/provider-accounts/nium/link" \
  --header "Authorization: Bearer $USER_TOKEN" \
  --header 'Accept: application/json'
```

- Expected HTTP: backend `200`; backend sends Nium
  `POST /api/v5/client/{clientHashId}/customers` with an immutable generated
  `externalId` and approved server-side KYC fields.
- Expected DB: exactly one `(user_id, provider_id)` row; customerHashId and
  walletHashId match the authenticated Nium response; independent verification
  timestamps are non-null only for IDs present in that response.
- Integration state: active only for authoritative `status=clear`, empty/null
  `subStatus`, and both verified IDs; otherwise submitted/under_review.
- Audit: `provider_account.nium_state_changed` records safe fingerprints and
  source `nium_v5_customer_create_response`.
- Cleanup: close the sandbox customer through Nium support/API if available,
  then delete the local test user/account or restore snapshot.

## 3. Duplicate externalId recovery

- Prerequisites: retain the account and its `external_reference`; arrange a
  retry after Nium created the customer but the first local response was lost.
- Request: repeat the request from test 2 without editing the local row.
- Expected HTTP: backend `200` when exactly one GET Customers result has the
  same externalId; ambiguous/not-found recovery is `200` restricted or a
  controlled `422`, never a second externalId/customer creation.
- Expected DB: one account; unchanged external_reference; exact recovered
  customerHashId. Missing walletHashId leaves `wallet_id_verified_at=NULL` and
  `reconciliation_status=failed`.
- Integration state: active only when the exact match also yields both verified
  IDs and clear/empty lifecycle state.
- Audit: state-change audit on exact recovery; reconciliation-failed audit for
  ambiguous, mismatched, or incomplete results.
- Cleanup: same as test 2.

## 4. Customer registration webhook

- Prerequisites: existing local external_reference; obtain the official
  `CARD_CUSTOMER_REGISTRATION_WEBHOOK` sandbox payload.
- Request:

```bash
curl --fail-with-body --request POST "$API_BASE_URL/webhooks/providers/nium" \
  --header 'Content-Type: application/json' \
  --header "x-partner-key: $NIUM_PARTNER_KEY" \
  --header 'x-request-id: phase1-registration-001' \
  --data @tests/Fixtures/nium/customer-registration-webhook.json
```

- Expected HTTP: `200`; backend performs authoritative GET Customer V5.
- Expected DB: one event keyed by header request ID; IDs/timestamps come from
  GET Customer response, not directly from query/body-controlled fields.
- Integration state: reflects GET Customer, never registration notification
  alone.
- Audit: state change with GET-response source; reconciliation failure audit if
  GET fails.
- Cleanup: retain account for subsequent lifecycle tests or restore snapshot.

## 5. Customer status webhook

- Prerequisites: mapped customer; official `CUSTOMER_STATUS_WEBHOOK` payload.
- Request: use test 4 curl with fixture
  `customer-status-rfi-webhook.json` and request ID `phase1-status-001`.
- Expected HTTP: `200` after GET Customer; controlled `422` if GET fails.
- Expected DB: event retained in both cases; failed GET sets event and account
  reconciliation status to failed.
- Integration state: restrictive notification applies immediately; only GET
  Customer may clear the restriction or activate.
- Audit: state-change and, when applicable, reconciliation-failed audit.
- Cleanup: resync with GET Customer after restoring the desired sandbox state.

## 6. Entity KYC status webhook

- Prerequisites: corporate/entity sandbox customer; official
  `CUSTOMER_ENTITY_KYC_STATUS` payload.
- Request: use test 4 curl with fixture
  `customer-entity-kyc-status-webhook.json` and a unique request ID.
- Expected HTTP: `200` with successful GET Customer.
- Expected DB: event processed; allowlisted entity KYC status stored under
  `metadata.nium_entity_kyc_states`; no identity document payload in logs.
- Integration state: based on GET Customer; entity notification alone cannot
  activate.
- Audit: safe state/metadata change audit.
- Cleanup: restore snapshot or retain for KYB testing.

## 7. Customer compliance status webhook

- Prerequisites: mapped customer; official `CUSTOMER_COMPLIANCE_STATUS` payload.
- Request: use fixture `customer-compliance-status-webhook.json` with a unique
  header request ID.
- Expected HTTP: `200` after authoritative GET.
- Expected DB: event processed; compliance status persisted; verified IDs are
  unchanged unless GET confirms them.
- Integration state: compliance notification cannot activate by itself.
- Audit: safe state-change audit; no full compliance/KYC payload in API logs.
- Cleanup: restore sandbox/customer state as required.

## 8. Customer ODD status webhook

- Prerequisites: mapped customer; official `CUSTOMER_ODD_STATUS_WEBHOOK` payload.
- Request: use fixture `customer-odd-status-webhook.json` with a unique request
  ID.
- Expected HTTP: `200` after GET Customer.
- Expected DB: event processed and odd_status persisted without replacing IDs.
- Integration state: access state follows current GET Customer.
- Audit: safe state/ODD change audit.
- Cleanup: restore snapshot.

## 9. Invalid and missing x-partner-key

- Prerequisites: any valid lifecycle fixture and new request IDs.
- Request: send once without the header and once with
  `x-partner-key: deliberately-wrong`.
- Expected HTTP: both `403`.
- Expected DB: no webhook event, account mutation, or audit row.
- Integration state: unchanged.
- Audit/log: operational rejection may record IP only; partner header name/value
  and credentials must not appear in logs.
- Cleanup: none.

## 10. Mismatching clientHashId

- Prerequisites: valid partner key; change only payload clientHashId to another
  sandbox client; use a new request ID.
- Request: send the modified official lifecycle fixture.
- Expected HTTP: `403`.
- Expected DB: no event and no account/audit mutation.
- Integration state: unchanged.
- Audit: none.
- Cleanup: none.

## 11. Duplicate x-request-id

- Prerequisites: valid mapped lifecycle payload.
- Request: send two requests concurrently with the same non-empty
  `x-request-id`, even if the second payload has a different payload `eventId`.
- Expected HTTP: both `200`; one response has `duplicate=true`.
- Expected DB: exactly one `(provider_id,event_id)` row because of the unique DB
  constraint; only one lifecycle reconciliation runs.
- Integration state: determined by that one authoritative GET.
- Audit: no duplicate state transition caused by the duplicate delivery.
- Cleanup: restore snapshot.

## 12. Lifecycle state matrix

- Prerequisites: mapped customer and both verified IDs. Change sandbox state,
  trigger an official notification, then allow backend GET Customer to confirm.
- Request: repeat test 5 for each state below with a unique request ID.
- Expected HTTP: `200` when GET succeeds.
- Expected DB/integration state:

| GET Customer status | subStatus | Internal state | Financial eligibility |
| --- | --- | --- | --- |
| pending | empty/null | submitted | blocked |
| clear | awaiting_kyc | under_review | blocked |
| clear | under_review | under_review | blocked |
| clear | rfi_requested | under_review | blocked |
| clear | empty/null | active only with both verified IDs | eligible |
| suspended | any | blocked | blocked |
| closed | any | blocked | blocked |
| terminated | any | blocked | blocked |

- Audit: one state-change audit per real transition. A stale notification must
  yield to current GET Customer state.
- Cleanup: return sandbox customer to a non-live test state or close it.

## 13. Restart and resync

- Prerequisites: leave a failed reconciliation event from test 5; restart PHP
  workers/backend without editing provider IDs.
- Request: after confirming the repository-backed service names against the
  staging host, restart the services and invoke one of the exact admin routes:

```bash
sudo systemctl restart php8.3-fpm # replace only if staging uses a confirmed override
sudo supervisorctl restart 'origin_wallet_worker:*'

curl --fail-with-body --request POST \
  "$API_BASE_URL/admin/providers/nium/users/$USER_ID/sync" \
  --header "Authorization: Bearer $ADMIN_TOKEN"

curl --fail-with-body --request POST \
  "$API_BASE_URL/admin/provider-webhook-events/<failed-event-id>/retry" \
  --header "Authorization: Bearer $ADMIN_TOKEN"
```

  Use the sync request or the retry request appropriate to the test; neither
  route accepts a user bearer token.
- Expected HTTP: admin retry/sync `200` on successful GET.
- Expected DB: same event/account IDs; event becomes processed; reconciliation
  becomes reconciled; no new customer or externalId.
- Integration state: current GET lifecycle state.
- Audit: new reconciliation/state audit with safe source and request ID.
- Cleanup: restore snapshot.

## 14. Financial gates

- Prerequisites: execute each lifecycle row from test 12 and also test clear
  with only customer verification, then only wallet verification.
- Request: call existing backend beneficiary-create, balance-sync, and
  transfer-create/submit entry points using harmless sandbox test data. Do not
  validate Phase 2 payload mapping in this run.
- Expected HTTP: controlled `422` before outbound provider I/O for every state
  except clear + empty subStatus + independently verified customer and wallet.
- Expected DB: no beneficiary/provider balance/payout mutation for blocked
  attempts; no Nium request log for the gated operation.
- Integration state: unchanged by blocked attempts.
- Audit: lifecycle restriction audit already exists; blocked financial calls do
  not alter provider IDs/status.
- Cleanup: delete local drafts created only in the eligible control case and
  restore snapshot.

## Exit criteria

All 14 tests must pass with exact sandbox payloads/responses captured as
redacted fixtures. Any response-shape difference must be added as a fixture and
reviewed before code changes. Do not enable payout or begin Phase 2 based solely
on mocked tests.
