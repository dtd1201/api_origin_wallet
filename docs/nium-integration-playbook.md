# Nium Integration Playbook

This document shows how to call the wallet backend for Nium flows.

Base API:

```text
https://api.khoinguyenoriginwallet.com/api
```

Authenticated user header:

```http
Authorization: Bearer {user_token}
Accept: application/json
Content-Type: application/json
```

## Prerequisites

Before the user can use Nium payout APIs in this backend:

- provider `nium` must exist and be configured
- the user must already have an active Nium provider account
- that provider account must contain:
  - `external_customer_id` = Nium `customerHashId`
  - `external_account_id` = Nium `walletHashId`

The backend also requires:

- `NIUM_BASE_URL`
- `NIUM_API_KEY`
- `NIUM_CLIENT_ID`

## 1. Resolve Provider Id

Find the local provider id:

```bash
curl --request GET \
  --url 'https://api.khoinguyenoriginwallet.com/api/providers' \
  --header 'Accept: application/json'
```

Use the returned `id` for the provider whose `code` is `nium`.

## 2. Create FX Quote

Backend endpoint:

```text
POST /api/user/users/{userId}/fx-quotes
```

Purpose:

- creates a local `fx_quotes` record
- uses Nium `POST /api/v1/client/{clientHashId}/quotes`
- the returned `quote_ref` can later be passed into transfer draft creation

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/fx-quotes' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 5,
    "source_currency": "USD",
    "target_currency": "INR",
    "source_amount": 100,
    "target_amount": 7800,
    "raw_data": {
      "nium": {
        "lock_period": "THIRTY_MINUTES",
        "conversion_schedule": "IMMEDIATE"
      }
    }
  }'
```

Important fields:

- `provider_id`: local `nium` provider id
- `source_currency`: funding currency
- `target_currency`: payout currency
- `source_amount`: source amount used to request quote
- `target_amount`: optional destination amount
- `raw_data.nium.lock_period`: optional Nium quote lock period
- `raw_data.nium.conversion_schedule`: optional quote conversion schedule

## 3. Create Beneficiary

Backend endpoint:

```text
POST /api/user/users/{userId}/beneficiaries
```

Purpose:

- creates a local beneficiary
- syncs it to Nium beneficiary v2 create endpoint
- can optionally call Nium Verify first

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/beneficiaries' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 5,
    "beneficiary_type": "personal",
    "full_name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "919876543210",
    "country_code": "IN",
    "currency": "INR",
    "bank_name": "HDFC",
    "bank_code": "HDFC0001234",
    "account_number": "1234567890",
    "swift_bic": "HDFCINBB",
    "address_line1": "1 Main St",
    "city": "Delhi",
    "state": "Delhi",
    "postal_code": "110017",
    "raw_data": {
      "nium": {
        "verify_before_create": true,
        "payoutMethod": "LOCAL",
        "bankAccountType": "CHECKING",
        "beneficiary": {
          "remitterBeneficiaryRelationship": "Friend"
        },
        "account_verification": {
          "routingInfo": [
            {
              "type": "IFSC",
              "value": "HDFC0001234"
            }
          ]
        },
        "routingInfo": [
          {
            "type": "IFSC",
            "value": "HDFC0001234"
          },
          {
            "type": "SWIFT",
            "value": "HDFCINBB"
          }
        ]
      }
    }
  }'
```

Important fields:

- `raw_data.nium.verify_before_create`: when `true`, backend calls Nium Verify before creating beneficiary
- `raw_data.nium.payoutMethod`: commonly `LOCAL`, `SWIFT`, `WALLET`
- `raw_data.nium.bankAccountType`: commonly `CHECKING` or `SAVINGS`
- `raw_data.nium.routingInfo`: preferred way to supply routing rails like `IFSC`, `SWIFT`, etc.
- `raw_data.nium.account_verification`: optional override payload for Nium Verify
- beneficiary `update/delete` are intentionally disabled by default until the exact endpoint/schema is confirmed from Nium docs for your account setup
- if `routingInfo` is not provided, backend falls back to `swift_bic`, `bank_code`, `branch_code`

### Optional Nium Verify Behavior

If `raw_data.nium.verify_before_create` is set to `true`, backend first calls:

```text
POST /api/v1/client/{clientHashId}/customer/{customerHashId}/accountVerification
```

Current behavior:

- verification failure stops beneficiary creation
- verification request/response are stored under beneficiary `raw_data`
- if you need corridor-specific verify fields, place them under `raw_data.nium.account_verification`

## 4. Create Transfer Draft

Backend endpoint:

```text
POST /api/user/users/{userId}/transfers
```

Purpose:

- creates a local transfer draft only
- no provider call happens yet

Draft example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/transfers' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 5,
    "beneficiary_id": 55,
    "fx_quote_id": 10,
    "transfer_type": "bank",
    "source_currency": "USD",
    "target_currency": "INR",
    "source_amount": 100,
    "target_amount": 7800,
    "purpose_code": "IR001",
    "reference_text": "Invoice 42",
    "client_reference": "INV-42",
    "raw_data": {
      "nium": {
        "sourceOfFunds": "Personal Savings",
        "ownPayment": true,
        "payout": {
          "serviceTime": "STANDARD",
          "preScreening": false
        }
      }
    }
  }'
```

Important fields:

- `beneficiary_id`: must reference a Nium beneficiary already synced
- `fx_quote_id`: optional, but useful if you want to carry quote info into payout request
- `raw_data.nium.sourceOfFunds`: recommended for remittance use cases
- `raw_data.nium.payout.tradeOrderID`: optional manual override if needed
- when `fx_quote_id` exists, backend may also reuse the stored `quote_ref`

## 5. Submit Transfer To Nium

Backend endpoint:

```text
POST /api/user/users/{userId}/transfers/{transferId}/submit
```

Purpose:

- calls Nium remittance endpoint
- stores `system_reference_number` as `external_transfer_id`
- stores `payment_id` as `external_payment_id`

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/transfers/777/submit' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json'
```

Expected behavior:

- transfer moves from `draft` to `pending`
- response contains local transfer with provider identifiers

## 6. Sync Transfer Status

Backend endpoint:

```text
POST /api/user/users/{userId}/transfers/{transferId}/sync-status
```

Purpose:

- calls Nium remittance audit endpoint
- reads latest audit event
- maps provider status into local transfer status

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/transfers/777/sync-status' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json'
```

Current status mapping:

- `PAID`, `SUCCESS`, `SUCCEEDED`, `COMPLETED` -> `completed`
- `FAILED`, `ERROR`, `REJECTED`, `RETURNED` -> `failed`
- `CANCELLED`, `VOIDED` -> `cancelled`
- `PENDING`, `PROCESSING`, `IN_PROGRESS`, `ACCEPTED` -> `pending`

## Notes

- `provider_id` values differ by environment, so always resolve from `GET /api/providers`.
- This backend currently uses Nium API key auth via `x-api-key` header and `clientHashId` in the path.
- Beneficiary v2 create request mapping is based on Nium public docs/Postman collection plus field inference from the validation and verify endpoints.
- Beneficiary update/delete are kept as explicit opt-in only. Set `NIUM_BENEFICIARY_UPDATE_ENDPOINT` or `NIUM_BENEFICIARY_DELETE_ENDPOINT` only after you confirm the exact endpoint and payload contract in Nium docs for your tenant/corridor.
- If you have access to the full Nium private request schemas for your corridor, you should align `raw_data.nium.routingInfo`, `bankAccountType`, and payout-specific fields with that exact schema.
