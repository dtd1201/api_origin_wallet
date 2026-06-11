# Nium Go-Live Runbook

This runbook covers the Origin Wallet API checks that must pass before switching Nium from sandbox to live.

## Required Environment

Set these values in the API environment:

```dotenv
NIUM_BASE_URL=
NIUM_API_KEY=
NIUM_CLIENT_ID=
NIUM_HEALTH_ENDPOINT=/api/v1/client/{client}
NIUM_CUSTOMER_ENDPOINT=
NIUM_WALLET_BALANCE_ENDPOINT=/api/v1/client/{client}/customer/{customer}/wallet/{wallet}/balance
NIUM_WALLET_TRANSACTIONS_ENDPOINT=/api/v1/client/{client}/customer/{customer}/wallet/{wallet}/transactions
NIUM_QUOTE_ENDPOINT=/api/v1/client/{client}/quotes
NIUM_BENEFICIARY_ENDPOINT=/api/v2/client/{client}/customer/{customer}/beneficiaries
NIUM_ACCOUNT_VERIFICATION_ENDPOINT=/api/v1/client/{client}/customer/{customer}/accountVerification
NIUM_TRANSFER_ENDPOINT=/api/v1/client/{client}/customer/{customer}/wallet/{wallet}/remittance
NIUM_TRANSFER_STATUS_ENDPOINT=/api/v1/client/{client}/customer/{customer}/wallet/{wallet}/remittance/{transfer}/audit
NIUM_WEBHOOK_SECRET=
NIUM_WEBHOOK_SIGNATURE_HEADER=
NIUM_WEBHOOK_SIGNATURE_ALGORITHM=sha256
```

Keep these controls enabled for live:

```dotenv
WALLET_LEDGER_ENABLED=true
WALLET_REQUIRE_SYNCED_BALANCE=true
TRANSFER_REQUIRE_ADMIN_APPROVAL=true
TRANSFER_APPROVAL_THRESHOLD_AMOUNT=0
TRANSFER_ALLOWED_PROVIDER_ACCOUNT_STATUSES=active
```

## Deployment Order

1. Deploy code.
2. Run `php artisan migrate --force`.
3. Clear and warm config: `php artisan config:clear && php artisan config:cache`.
4. Confirm the Nium provider row exists and is active.
5. Confirm webhook URL in Nium points to:

```text
https://<api-domain>/api/webhooks/providers/nium
```

## Smoke Tests

Connectivity:

```bash
php artisan nium:smoke-test
```

Customer wallet sync:

```bash
php artisan nium:smoke-test <userId> --sync
```

Quote:

```bash
php artisan nium:smoke-test <userId> --quote --source-currency=USD --target-currency=EUR --amount=100
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
- `php artisan migrate:status`
- `php artisan nium:smoke-test`
- Admin UI build deployed with `VITE_API_BASE_URL=https://<api-domain>/api`
- Webhook events page shows Nium events and retry can reprocess failed events.
- Ledger page shows hold/debit/release entries for test transfers.

## Rollback Notes

Do not manually edit balances for a failed live payout. Use provider status sync or webhook retry first. If a manual correction is unavoidable, insert a ledger entry with a unique reference and attach the correction reason in `raw_data`.
