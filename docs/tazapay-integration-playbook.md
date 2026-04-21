# Tazapay Integration Playbook

This document shows how to call the wallet backend for Tazapay flows.

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

## 1. Create FX Quote

Backend endpoint:

```text
POST /api/user/users/{userId}/fx-quotes
```

Purpose:

- creates a local `fx_quotes` record using Tazapay `POST /v3/payout/quote`
- quote id is later reused when creating a transfer

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/fx-quotes' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 4,
    "source_currency": "USD",
    "target_currency": "HKD",
    "source_amount": 640000,
    "target_amount": 5000000,
    "raw_data": {
      "tazapay": {
        "payout_type": "local",
        "local": {
          "fund_transfer_network": "chats"
        },
        "holding_info": {
          "currency": "USD",
          "amount": 640000
        },
        "destination_info": {
          "currency": "HKD",
          "amount": 5000000
        }
      }
    }
  }'
```

Important fields:

- `provider_id`: must point to provider `tazapay`
- `raw_data.tazapay.payout_type`: one of `swift`, `local`, `local_payment_network`, `wallet`, `tazapay_account`
- `raw_data.tazapay.local.fund_transfer_network`: optional but usually needed for local payouts
- `target_amount`: payout amount in destination currency

## 2. Create Beneficiary

Backend endpoint:

```text
POST /api/user/users/{userId}/beneficiaries
```

Purpose:

- creates a local beneficiary
- syncs it to Tazapay `POST /v3/beneficiary`

Example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/beneficiaries' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 4,
    "beneficiary_type": "personal",
    "full_name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "9362987920",
    "country_code": "IN",
    "currency": "INR",
    "bank_name": "HDFC",
    "account_number": "1234567890",
    "swift_bic": "HDFCINBB",
    "address_line1": "Test",
    "address_line2": "Block A",
    "city": "Delhi",
    "state": "Delhi",
    "postal_code": "110017",
    "raw_data": {
      "tazapay": {
        "phone": {
          "calling_code": "91"
        },
        "bank_codes": {
          "ifsc_code": "HDFC0001234"
        },
        "bank": {
          "firc_required": true,
          "purpose_code": "PYR003",
          "transfer_type": "local"
        }
      }
    }
  }'
```

Important fields:

- `raw_data.tazapay.phone.calling_code`: recommended
- `raw_data.tazapay.bank_codes`: use country-specific routing codes when needed
- `raw_data.tazapay.bank.transfer_type`: use `local` or `swift`

## 3. Create Transfer Draft And Submit

Create draft:

```text
POST /api/user/users/{userId}/transfers
```

Submit to provider:

```text
POST /api/user/users/{userId}/transfers/{transferId}/submit
```

Sync provider status:

```text
POST /api/user/users/{userId}/transfers/{transferId}/sync-status
```

Draft example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/transfers' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{
    "provider_id": 4,
    "beneficiary_id": 55,
    "fx_quote_id": 10,
    "transfer_type": "local",
    "source_currency": "USD",
    "target_currency": "INR",
    "source_amount": 100,
    "target_amount": 8300,
    "purpose_code": "PYR003",
    "reference_text": "Invoice 42",
    "client_reference": "INV-42",
    "raw_data": {
      "tazapay": {
        "type": "local",
        "charge_type": "shared",
        "local": {
          "fund_transfer_network": "imps"
        }
      }
    }
  }'
```

Submit example:

```bash
curl --request POST \
  --url 'https://api.khoinguyenoriginwallet.com/api/user/users/123/transfers/777/submit' \
  --header 'Authorization: Bearer {user_token}' \
  --header 'Accept: application/json'
```

Important fields:

- `fx_quote_id`: optional but recommended when you want locked FX
- `raw_data.tazapay.type`: usually `local` or `swift`
- `raw_data.tazapay.charge_type`: useful for wire/swift cases
- `raw_data.tazapay.local.fund_transfer_network`: for local rails

## 4. Receive Webhook

Backend endpoint:

```text
POST /api/webhooks/providers/tazapay
```

Expected payload shape:

```json
{
  "type": "payout.succeeded",
  "id": "evt_test_123",
  "object": "event",
  "data": {
    "id": "pot_test_123",
    "status": "succeeded",
    "tracking_details": {
      "tracking_number": "TZP-TRACK-001",
      "tracking_type": "internal"
    },
    "balance_transaction": "btr_test_123",
    "reference_id": "INV-42",
    "transaction_description": "Invoice 42 settlement"
  }
}
```

Current backend behavior:

- deduplicates by `event_id`
- updates matching transfer by `data.id`
- updates matching transaction by `data.balance_transaction`
- maps `payout.succeeded` to `completed`
- maps `payout.reversed` to `failed`

Optional webhook verification:

- if both `TAZAPAY_WEBHOOK_SECRET` and `TAZAPAY_WEBHOOK_SIGNATURE_HEADER` are set, backend will require that header value to exactly match the configured secret
- this is a temporary compatibility mode until Tazapay publishes a definitive payout webhook signature scheme for this integration

## Notes

- `provider_id` values differ per environment. Resolve from `GET /api/providers`.
- user must already have an active linked Tazapay provider account, otherwise quote/transfer creation will be rejected.
- amounts are passed through as provider amounts. Follow Tazapay decimal/currency rules for each currency.
