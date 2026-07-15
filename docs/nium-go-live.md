# Nium Go-Live Runbook

This runbook covers the Origin Wallet API checks that must pass before switching Nium from sandbox to live.

## Required Environment

Set these values in the API environment:

```dotenv
NIUM_BASE_URL=
NIUM_ALLOW_INSECURE_LOCAL=false
NIUM_AUTH_MODE=header
NIUM_AUTH_HEADER_NAME=x-api-key
NIUM_API_KEY=
NIUM_CLIENT_ID=
NIUM_HEALTH_ENDPOINT=/api/v1/client/{clientHashId}
NIUM_CUSTOMER_CREATE_ENDPOINT=/api/v5/client/{clientHashId}/customers
NIUM_CUSTOMER_GET_ENDPOINT=/api/v5/client/{clientHashId}/customer/{customerHashId}
NIUM_CUSTOMER_LIST_ENDPOINT=/api/v5/client/{clientHashId}/customers
NIUM_WALLET_BALANCE_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}
NIUM_WALLET_TRANSACTIONS_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/transactions
NIUM_QUOTE_ENDPOINT=/api/v1/client/{clientHashId}/quotes
NIUM_BENEFICIARY_ENDPOINT=/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries
NIUM_BENEFICIARY_UPDATE_ENDPOINT=/api/v2/client/{clientHashId}/customer/{customerHashId}/beneficiaries/{beneficiaryHashId}
NIUM_BENEFICIARY_DELETE_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/beneficiaries/{beneficiaryHashId}
NIUM_ACCOUNT_VERIFICATION_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/accountVerification
NIUM_TRANSFER_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance
NIUM_TRANSFER_STATUS_ENDPOINT=/api/v1/client/{clientHashId}/customer/{customerHashId}/wallet/{walletHashId}/remittance/{systemReferenceNumber}/audit
NIUM_WEBHOOK_STATIC_HEADER_NAME=x-partner-key
NIUM_WEBHOOK_STATIC_HEADER_VALUE=
NIUM_COMPLIANCE_CALLBACK_STATIC_HEADER_NAME=x-partner-key
NIUM_COMPLIANCE_CALLBACK_STATIC_HEADER_VALUE=
```

For Phase 1 onboarding, the required values are exactly `NIUM_BASE_URL`,
`NIUM_API_KEY`, `NIUM_CLIENT_ID`, `NIUM_AUTH_MODE=header`,
`NIUM_AUTH_HEADER_NAME=x-api-key`, the four client/customer endpoints shown
above, and `NIUM_WEBHOOK_STATIC_HEADER_NAME=x-partner-key` plus a non-empty
`NIUM_WEBHOOK_STATIC_HEADER_VALUE`. `NIUM_BASE_URL` must use HTTPS. HTTP is
accepted only for `localhost`, `127.0.0.1`, or `::1` when both
`APP_ENV=local|testing` and `NIUM_ALLOW_INSECURE_LOCAL=true` are set.

Legacy variable migration:

| Old setting | Phase 1 setting |
| --- | --- |
| `NIUM_WEBHOOK_SECRET` | `NIUM_WEBHOOK_STATIC_HEADER_VALUE` |
| `NIUM_WEBHOOK_SIGNATURE_HEADER` | `NIUM_WEBHOOK_STATIC_HEADER_NAME=x-partner-key` |
| `NIUM_WEBHOOK_SIGNATURE_ALGORITHM` | Remove; lifecycle webhooks use constant-time static partner-key comparison |
| endpoint placeholders such as `{client}` / `{customer}` | `{clientHashId}` / `{customerHashId}` |

The legacy HMAC variables are not accepted for Nium customer lifecycle
webhooks. Missing or unsafe configuration reports `not_configured` and no Nium
request is sent. Unresolved endpoint placeholders also fail before I/O.

## Production migration preflight

Run these read-only queries before `php artisan migrate --force`. Both result
sets must be empty. Resolve every duplicate manually; do not delete, merge, or
renumber records in the migration.

```sql
SELECT user_id, provider_id, COUNT(*) AS duplicate_count,
       ARRAY_AGG(id ORDER BY id) AS record_ids
FROM user_provider_accounts
GROUP BY user_id, provider_id
HAVING COUNT(*) > 1;

SELECT provider_id, event_id, COUNT(*) AS duplicate_count,
       ARRAY_AGG(id ORDER BY id) AS record_ids
FROM webhook_events
WHERE event_id IS NOT NULL
GROUP BY provider_id, event_id
HAVING COUNT(*) > 1;
```

The Phase 1 migration repeats the first preflight and aborts with safe record
IDs when duplicates exist. The webhook table already enforces
`UNIQUE(provider_id, event_id)`; customer lifecycle code now stores canonical
`x-request-id` in `event_id`.

`x-partner-key` is a static header value and is not an HMAC signature. Generate separate random values for the general webhook and compliance callback. Do not commit or log either value.

Enable `CUSTOMER_STATUS_WEBHOOK`, `CUSTOMER_ENTITY_KYC_STATUS`, `CUSTOMER_COMPLIANCE_STATUS`, `CUSTOMER_ODD_STATUS_WEBHOOK`, and customer registration events for the configured webhook URL. Nium customer and wallet identifiers must never be populated manually.

## Network And Callback Details

Nium must whitelist the static outbound IPv4 address used by the API server to call Nium. Run this command on the actual VPS, not a developer laptop:

```bash
curl -4 https://api.ipify.org
```

Do not submit a Cloudflare edge IP. If sandbox and production use different servers or NAT gateways, provide both egress IPs and identify their environments.

Submit these public endpoints to Nium:

```text
Webhook URL: https://api.originwallet.asia/api/webhooks/providers/nium
Compliance callback URL: https://api.originwallet.asia/api/callbacks/nium/transaction-compliance
```

PGP-encrypted daily reports and SFTP access are optional. Leave the PGP section blank or mark it `N/A` until report delivery is enabled. When enabled, use a dedicated PGP key pair and send Nium only the public key.

Keep these controls enabled for live:

```dotenv
WALLET_LEDGER_ENABLED=true
WALLET_REQUIRE_SYNCED_BALANCE=true
TRANSFER_REQUIRE_ADMIN_APPROVAL=true
TRANSFER_APPROVAL_THRESHOLD_AMOUNT=0
TRANSFER_ALLOWED_PROVIDER_ACCOUNT_STATUSES=active
```

## Sandbox Readiness

1. Deploy code.
2. Run `php artisan migrate --force`.
3. Clear and warm config: `php artisan config:clear && php artisan config:cache`.
4. Confirm the Nium provider row exists and is active.
5. Configure the Nium sandbox base URL, API key, client hash ID, and two static partner keys.
6. Run `php artisan nium:smoke-test --compliance-callback`; copy the two URLs printed by the command into the Nium setup sheet. Add `--live` only when the authenticated Get Client request is intended.
7. Ask Nium to send a signed sandbox payout webhook and an `ACTION_REQUIRED` compliance callback.
8. Confirm valid callbacks return 2xx, invalid keys return 403, and duplicate events do not create duplicate rows.
9. Confirm unmatched callbacks appear in `GET /api/admin/nium-compliance-events?review_status=pending`.
10. Complete a sandbox quote, beneficiary, transfer, status sync, and terminal payout webhook test.

## Smoke Tests

Connectivity:

```bash
php artisan nium:smoke-test --live
```

Customer wallet sync:

```bash
php artisan nium:smoke-test <userId> --live --sync
```

Quote:

```bash
php artisan nium:smoke-test <userId> --live --quote --source-currency=USD --target-currency=EUR --amount=100
```

## Live Transfer Control Flow

1. User KYC/KYB must be verified.
2. User Nium provider account must be active.
3. Nium balance sync must exist for the source currency.
4. User creates transfer; status becomes `approval_required`.
5. Admin approves transfer in `/api/admin/transfers/{transfer}/approve`.
6. User submits transfer.
7. API reserves wallet balance and writes `transfer:{id}:hold`.
8. Provider submit succeeds; transfer becomes `pending`.
9. Nium webhook or status sync moves transfer to a terminal status.
10. Completed transfer writes `transfer:{id}:debit`; failed/cancelled transfer writes `transfer:{id}:release`.

## Required Launch Checks

- `php artisan route:list --path=api/admin/transfers`
- `php artisan route:list --path=api/webhooks`
- `php artisan route:list --path=api/callbacks/nium`
- `php artisan route:list --path=api/admin/nium-compliance-events`
- `php artisan migrate:status`
- `php artisan nium:smoke-test` for configuration-only validation
- `php artisan nium:smoke-test --live` for the explicit Get Client readiness request
- Admin UI build deployed with `VITE_API_BASE_URL=https://<api-domain>/api`
- Webhook events page shows Nium events and retry can reprocess failed events.
- Ledger page shows hold/debit/release entries for test transfers.
- Compliance events with `ACTION_REQUIRED` create a pending review task and flag the related transfer or transaction.

## Production Go-Live

Production must use credentials and static partner keys that are different from sandbox.

1. Complete every sandbox readiness check.
2. Obtain the production base URL, API key, client hash ID, enabled products, webhook schema, and compliance callback schema from Nium.
3. Confirm Nium has whitelisted the production egress IP.
4. Replace sandbox credentials with production secrets through the deployment secret store.
5. Run migrations and rebuild the Laravel configuration cache.
6. Run `php artisan nium:smoke-test --live` against the production client-details endpoint before enabling live transfers.
7. Ask Nium to send non-monetary production webhook/callback verification events.
8. Enable live transfers only after authentication, idempotency, matching, admin review, and ledger checks pass.

The compliance callback parser intentionally accepts nested and temporarily variable payload shapes. It recognizes common event IDs, request IDs, transaction/remittance/payment references, customer hash IDs, and compliance status fields. Final field mapping remains pending until Nium supplies the official transaction compliance callback schema.

## Rollback Notes

Do not manually edit balances for a failed live payout. Use provider status sync or webhook retry first. If a manual correction is unavoidable, insert a ledger entry with a unique reference and attach the correction reason in `raw_data`.
