# Airwallex Frontend Handoff

This document is intended for the frontend team / Codex frontend.

Use it to wire the Airwallex user flow end-to-end against the current backend.

## Status

Airwallex backend capability is now enabled for:

- onboarding
- account sync
- balance sync
- transaction sync
- FX quote
- beneficiary create / update / delete
- transfer submit / sync status
- webhook handling

Important:

- backend still uses a manual-link onboarding shape
- frontend must send the correct completion payload so backend can store Airwallex account metadata
- if onboarding metadata is incomplete, later sync / quote / transfer calls may fail even if credentials are valid

## Backend Base

Base API URL:

```text
{API_BASE}/api
```

All user endpoints below require:

```http
Authorization: Bearer {user_token}
Content-Type: application/json
Accept: application/json
```

## Required Backend Env

Backend should have these values configured:

```env
AIRWALLEX_BASE_URL=https://api-demo.airwallex.com
AIRWALLEX_AUTH_MODE=airwallex_access_token
AIRWALLEX_CLIENT_ID=...
AIRWALLEX_API_KEY=...
AIRWALLEX_API_VERSION=2024-09-27
AIRWALLEX_TOKEN_ENDPOINT=/api/v1/authentication/login
AIRWALLEX_GLOBAL_ACCOUNTS_ENDPOINT=/api/v1/global_accounts
AIRWALLEX_BALANCES_ENDPOINT=/api/v1/balances/current
AIRWALLEX_TRANSACTIONS_ENDPOINT=/api/v1/pa/payment_events/search
AIRWALLEX_QUOTE_ENDPOINT=/api/v1/quotes/create
AIRWALLEX_TRANSFER_ENDPOINT=/api/v1/transfers/create
AIRWALLEX_TRANSFER_RETRIEVE_ENDPOINT=/api/v1/transfers/{transfer}
AIRWALLEX_BENEFICIARY_ENDPOINT=/api/v1/beneficiaries/create
AIRWALLEX_BENEFICIARY_UPDATE_ENDPOINT=/api/v1/beneficiaries/{beneficiary}
AIRWALLEX_BENEFICIARY_DELETE_ENDPOINT=/api/v1/beneficiaries/{beneficiary}
AIRWALLEX_WEBHOOK_SECRET=...
```

After env changes on server:

```bash
php artisan optimize:clear
```

Optional smoke test:

```bash
LOG_CHANNEL=stderr XDG_CONFIG_HOME=/tmp php artisan airwallex:smoke-test
LOG_CHANNEL=stderr XDG_CONFIG_HOME=/tmp php artisan airwallex:smoke-test {userId} --sync
LOG_CHANNEL=stderr XDG_CONFIG_HOME=/tmp php artisan airwallex:smoke-test {userId} --quote --source-currency=USD --target-currency=EUR --amount=100
```

## Frontend Flow Summary

Recommended user flow:

1. User completes profile.
2. User selects Airwallex as provider.
3. Frontend calls provider link endpoint.
4. User opens hosted/manual Airwallex link assigned by admin.
5. After Airwallex onboarding is done, frontend or admin flow calls provider complete endpoint with Airwallex account identifiers.
6. Only after that should frontend enable sync / quote / beneficiary / transfer features.

## Provider Onboarding Endpoints

List provider account state:

```http
GET /api/user/users/{userId}/provider-accounts
```

Get Airwallex provider account state:

```http
GET /api/user/users/{userId}/provider-accounts/airwallex
```

Start onboarding:

```http
POST /api/user/users/{userId}/provider-accounts/airwallex/link
```

Complete onboarding:

```http
POST /api/user/users/{userId}/provider-accounts/airwallex/complete
```

## Critical Complete Payload

This is the most important frontend requirement.

Backend needs Airwallex account metadata so it can send:

- `x-on-behalf-of`
- `x-sca-token` when required

Send at least:

```json
{
  "status": "active",
  "external_account_id": "acct_123456789",
  "open_id": "acct_123456789",
  "account_name": "Airwallex Sandbox Account"
}
```

If Airwallex gives you SCA / auth token and later API calls require it, also send:

```json
{
  "sca_token": "..."
}
```

Best-practice complete payload:

```json
{
  "status": "active",
  "external_customer_id": "cus_xxx",
  "external_account_id": "acct_xxx",
  "open_id": "acct_xxx",
  "account_name": "Demo Airwallex Account",
  "sca_token": "optional_if_required"
}
```

Notes:

- `external_account_id` and `open_id` should usually both be the Airwallex connected account open ID such as `acct_xxx`
- backend stores the whole completion payload and can read `open_id` / `sca_token` from it later
- without `acct_xxx`, sync and transfer-like calls may fail because backend cannot populate `x-on-behalf-of`

## Capability Gating For Frontend

Do not show Airwallex actions just because provider exists.

Wait until:

- provider is active
- provider account exists
- provider account status is one of `submitted`, `under_review`, or `active` for draft transfer creation
- provider account is realistically completed with Airwallex identifiers before enabling sync / quote / transfer

Practical frontend rule:

- enable dashboard/provider card after `provider_account` exists
- enable sync / quote / beneficiary / transfer only when Airwallex provider account contains usable account identifiers

## Sync Endpoints

Sync accounts:

```http
POST /api/user/users/{userId}/providers/airwallex/sync/accounts
```

Sync balances:

```http
POST /api/user/users/{userId}/providers/airwallex/sync/balances
```

Sync transactions:

```http
POST /api/user/users/{userId}/providers/airwallex/sync/transactions
```

Recommended frontend sequence after onboarding complete:

1. sync accounts
2. sync balances
3. sync transactions
4. reload:
   - `GET /api/user/users/{userId}/bank-accounts`
   - `GET /api/user/users/{userId}/balances`
   - `GET /api/user/users/{userId}/transactions`

## Quote Endpoint

Create FX quote:

```http
POST /api/user/users/{userId}/fx-quotes
```

Payload:

```json
{
  "provider_id": 3,
  "source_currency": "USD",
  "target_currency": "EUR",
  "source_amount": 100
}
```

Alternative payload if frontend wants target-driven quote:

```json
{
  "provider_id": 3,
  "source_currency": "USD",
  "target_currency": "EUR",
  "source_amount": 100,
  "target_amount": 92
}
```

Response fields frontend will commonly use:

- `id`
- `quote_ref`
- `source_currency`
- `target_currency`
- `source_amount`
- `target_amount`
- `mid_rate`
- `net_rate`
- `fee_amount`
- `expires_at`

## Beneficiary Endpoint

Create beneficiary:

```http
POST /api/user/users/{userId}/beneficiaries
```

Minimum payload:

```json
{
  "provider_id": 3,
  "beneficiary_type": "personal",
  "full_name": "Jane Doe",
  "country_code": "US",
  "currency": "USD",
  "bank_name": "JPMorgan Chase Bank, NA",
  "bank_code": "021000021",
  "account_number": "50001121",
  "email": "jane@example.com",
  "address_line1": "412 5th Avenue",
  "city": "Seattle",
  "state": "Washington",
  "postal_code": "98104",
  "raw_data": {
    "airwallex": {
      "transfer_method": "LOCAL",
      "local_clearing_system": "ACH",
      "account_routing_type1": "aba",
      "bank_account_category": "Checking"
    }
  }
}
```

Important:

- Airwallex beneficiary requirements vary by country / currency / clearing system
- frontend should collect provider-specific fields under `raw_data.airwallex`
- backend maps common fields plus selected Airwallex-specific fields from `raw_data.airwallex`

Update beneficiary:

```http
PUT /api/user/users/{userId}/beneficiaries/{beneficiaryId}
```

Delete beneficiary:

```http
DELETE /api/user/users/{userId}/beneficiaries/{beneficiaryId}
```

## Transfer Endpoints

Create local draft transfer:

```http
POST /api/user/users/{userId}/transfers
```

Payload example:

```json
{
  "provider_id": 3,
  "beneficiary_id": 11,
  "fx_quote_id": 5,
  "transfer_type": "bank",
  "source_currency": "USD",
  "target_currency": "EUR",
  "source_amount": 100,
  "target_amount": 92,
  "purpose_code": "travel",
  "reference_text": "Invoice 42",
  "raw_data": {
    "quote_ref": "quote_xxx",
    "airwallex": {
      "transfer_method": "LOCAL",
      "fee_paid_by": "PAYER",
      "lock_rate_on_create": true,
      "transfer_date": "2026-04-15"
    }
  }
}
```

Submit transfer to Airwallex:

```http
POST /api/user/users/{userId}/transfers/{transferId}/submit
```

Sync transfer status:

```http
POST /api/user/users/{userId}/transfers/{transferId}/sync-status
```

Cancel local transfer:

```http
POST /api/user/users/{userId}/transfers/{transferId}/cancel
```

Practical frontend rule:

- `POST /transfers` only creates local draft after eligibility checks
- actual provider submission happens on `/submit`
- after submit, show polling or manual refresh using `/sync-status`

## Display / Read Endpoints

Use these after sync or mutations:

```http
GET /api/user/users/{userId}/bank-accounts
GET /api/user/users/{userId}/bank-accounts/{bankAccountId}
GET /api/user/users/{userId}/balances
GET /api/user/users/{userId}/transactions
GET /api/user/users/{userId}/transactions/{transactionId}
GET /api/user/users/{userId}/fx-quotes
GET /api/user/users/{userId}/fx-quotes/{fxQuoteId}
GET /api/user/users/{userId}/beneficiaries
GET /api/user/users/{userId}/beneficiaries/{beneficiaryId}
GET /api/user/users/{userId}/transfers
GET /api/user/users/{userId}/transfers/{transferId}
```

## Common Reasons For 422

Typical backend `422` causes:

- Airwallex credentials missing or invalid on backend
- user has not completed profile
- user has not completed provider onboarding
- provider account exists but missing `acct_xxx` / `open_id`
- beneficiary missing fields required by Airwallex schema for selected route
- transfer submitted without synced beneficiary
- insufficient balance for transfer
- quote / transfer attempted before provider account is ready

Frontend should display backend `message` directly unless field-level `errors` exist.

## Suggested Frontend Implementation Order

1. Show Airwallex provider account card and status.
2. Implement complete-onboarding payload with `external_account_id` and `open_id`.
3. Add sync buttons for accounts / balances / transactions.
4. Build quote creation form.
5. Build beneficiary form with `raw_data.airwallex`.
6. Build transfer draft + submit flow.
7. Add transfer status refresh.

## Quick Handoff Notes For Codex Frontend

- treat Airwallex as a capability-driven provider, not a hardcoded happy path
- keep provider-specific beneficiary fields inside `raw_data.airwallex`
- when onboarding is completed, always persist/send `acct_xxx` identifiers back to backend
- do not enable quote / sync / transfer buttons until provider account is truly completed
- after every sync or submit action, reload the relevant list endpoint instead of assuming optimistic local state
