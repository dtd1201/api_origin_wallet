# Frontend API Guide

Base URL:

```text
https://api.khoinguyenoriginwallet.com/api
```

Production host:

```text
https://api.khoinguyenoriginwallet.com
```

Default headers:

```http
Content-Type: application/json
Accept: application/json
```

Authenticated headers:

```http
Authorization: Bearer {token}
```

## Common Notes

- All authenticated user endpoints require `auth.token`.
- All `/user/*` endpoints also require `auth.user`.
- All `/admin/*` endpoints require `auth.admin`.
- Endpoints inside the `profile.complete` middleware require the user profile to be completed first.
- Validation errors usually return `422 Unprocessable Entity`.
- Delete endpoints return `204 No Content`.
- Some provider-managed fields inside `result`, `raw_data`, provider accounts, quotes, and transfers can vary by integration. The examples below show the expected envelope and common fields.

## Common Response Shapes

Auth payload:

```json
{
  "token": "plain-api-token",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "pending",
    "kyc_status": "pending",
    "profile": null
  },
  "onboarding": {
    "profile_completed": false,
    "selected_provider_code": null,
    "selected_provider_account_status": "not_started",
    "provider_account_statuses": {},
    "next_action": "complete_user_profile",
    "message": "Login successful. User must complete profile before using wallet features."
  },
  "providers": [
    {
      "id": 1,
      "code": "AIRWALLEX",
      "name": "Airwallex",
      "status": "active",
      "is_available_for_onboarding": true,
      "supports_beneficiaries": true,
      "supports_data_sync": true,
      "supports_quotes": true,
      "supports_transfers": true,
      "supports_webhooks": true
    }
  ]
}
```

Pagination payload:

```json
{
  "current_page": 1,
  "data": [],
  "first_page_url": "https://api.khoinguyenoriginwallet.com/api/admin/users?page=1",
  "from": 1,
  "last_page": 1,
  "last_page_url": "https://api.khoinguyenoriginwallet.com/api/admin/users?page=1",
  "links": [],
  "next_page_url": null,
  "path": "https://api.khoinguyenoriginwallet.com/api/admin/users",
  "per_page": 15,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

Validation error example:

```json
{
  "message": "The email field is required. (and 1 more error)",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

## Public APIs

### `GET /test`

Response:

```json
{
  "message": "API working"
}
```

### `GET /providers`

Response:

```json
{
  "data": [
    {
      "id": 1,
      "code": "AIRWALLEX",
      "name": "Airwallex",
      "status": "active",
      "is_available_for_onboarding": true,
      "supports_beneficiaries": true,
      "supports_data_sync": true,
      "supports_quotes": true,
      "supports_transfers": true,
      "supports_webhooks": true
    }
  ]
}
```

## Auth APIs

### `POST /auth/register`

Request:

```json
{
  "email": "user@example.com",
  "phone": "+84901234567",
  "full_name": "Jane Doe",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

Response `202`:

```json
{
  "message": "Verification link sent to email. Please verify to complete registration.",
  "email": "user@example.com",
  "expires_in_minutes": 15
}
```

### `POST /auth/register/verify`

Request:

```json
{
  "email": "user@example.com",
  "verification_code": "123456"
}
```

Response `201`:

```json
{
  "message": "Email verified successfully. Registration completed and user logged in. Account status remains pending until provider onboarding is completed.",
  "token": "plain-api-token",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "pending",
    "kyc_status": "pending",
    "profile": null
  },
  "onboarding": {
    "profile_completed": false,
    "selected_provider_code": null,
    "selected_provider_account_status": "not_started",
    "provider_account_statuses": {},
    "next_action": "complete_user_profile",
    "message": "Login successful. User must complete profile before using wallet features."
  },
  "providers": []
}
```

### `GET /auth/register/activate?email={email}&code={code}&expires={...}&signature={...}`

Use this for one-click verification from email. Response is the same shape as `POST /auth/register/verify`.

### `POST /auth/login`

Request:

```json
{
  "email": "user@example.com",
  "password": "secret123"
}
```

Response `202`:

```json
{
  "message": "Verification code sent to email. Please verify to complete login.",
  "email": "user@example.com",
  "expires_in_minutes": 15
}
```

### `POST /auth/login/verify`

Request:

```json
{
  "email": "user@example.com",
  "verification_code": "123456"
}
```

Response `200`:

```json
{
  "message": "Login successful.",
  "token": "plain-api-token",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "active",
    "kyc_status": "pending",
    "profile": {
      "user_id": 1,
      "user_type": "individual"
    }
  },
  "onboarding": {
    "profile_completed": true,
    "selected_provider_code": "AIRWALLEX",
    "selected_provider_account_status": "pending",
    "provider_account_statuses": {
      "AIRWALLEX": {
        "provider_id": 1,
        "provider_name": "Airwallex",
        "status": "pending",
        "external_account_id": null
      }
    },
    "next_action": null,
    "message": "Profile received. Account and KYC status remain pending until provider onboarding is completed."
  },
  "providers": []
}
```

### `POST /auth/forgot-password`

Request:

```json
{
  "email": "user@example.com"
}
```

Response `202`:

```json
{
  "message": "If the email exists in our system, a password reset code has been sent.",
  "email": "user@example.com",
  "expires_in_minutes": 60
}
```

### `POST /auth/reset-password`

Request:

```json
{
  "email": "user@example.com",
  "verification_code": "123456",
  "password": "new-secret123",
  "password_confirmation": "new-secret123"
}
```

Response `200`:

```json
{
  "message": "Password reset successful. Please log in again with your new password."
}
```

### `POST /auth/google`

Request:

```json
{
  "id_token": "google-id-token"
}
```

Response `200` or `201`:

```json
{
  "message": "Google login successful.",
  "token": "plain-api-token",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "email": "user@gmail.com",
    "phone": null,
    "full_name": "Jane Doe",
    "status": "pending",
    "kyc_status": "pending",
    "profile": null
  },
  "onboarding": {
    "profile_completed": false,
    "selected_provider_code": null,
    "selected_provider_account_status": "not_started",
    "provider_account_statuses": {},
    "next_action": "complete_user_profile",
    "message": "Login successful. User must complete profile before using wallet features."
  },
  "providers": []
}
```

### `POST /auth/logout`

Response:

```json
{
  "message": "Logged out successfully."
}
```

### `GET /auth/me`

Response:

```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "active",
    "kyc_status": "pending",
    "profile": {
      "user_id": 1,
      "user_type": "individual"
    }
  },
  "onboarding": {
    "profile_completed": true,
    "selected_provider_code": "AIRWALLEX",
    "selected_provider_account_status": "pending",
    "provider_account_statuses": {},
    "next_action": null,
    "message": "Profile received. Account and KYC status remain pending until provider onboarding is completed."
  },
  "providers": []
}
```

### `PUT /auth/profile`

Request:

```json
{
  "phone": "+84901234567",
  "full_name": "Jane Doe",
  "provider_code": "AIRWALLEX",
  "profile": {
    "user_type": "individual",
    "country_code": "VN",
    "address_line1": "123 Nguyen Hue",
    "city": "HCMC",
    "postal_code": "700000"
  }
}
```

Response:

```json
{
  "message": "Profile submitted successfully.",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "pending",
    "kyc_status": "pending",
    "profile": {
      "user_id": 1,
      "user_type": "individual",
      "country_code": "VN",
      "address_line1": "123 Nguyen Hue",
      "city": "HCMC",
      "postal_code": "700000"
    }
  },
  "onboarding": {
    "profile_completed": true,
    "selected_provider_code": "AIRWALLEX",
    "selected_provider_account_status": "pending",
    "provider_account_statuses": {
      "AIRWALLEX": {
        "provider_id": 1,
        "provider_name": "Airwallex",
        "status": "pending",
        "external_account_id": null
      }
    },
    "next_action": null,
    "message": "Profile received. Account and KYC status remain pending until provider onboarding is completed."
  },
  "providers": []
}
```

## User APIs

Prefix:

```text
/user
```

### `GET /user/users/{userId}/overview`

Response:

```json
{
  "user": {
    "id": 1,
    "email": "user@example.com",
    "full_name": "Jane Doe",
    "status": "active",
    "kyc_status": "pending"
  },
  "summary": {
    "bank_accounts_count": 2,
    "beneficiaries_count": 3,
    "transfers_count": 10,
    "transactions_count": 44
  }
}
```

### `GET /user/users/{userId}/profile`

Response:

```json
{
  "id": 1,
  "email": "user@example.com",
  "phone": "+84901234567",
  "full_name": "Jane Doe",
  "status": "active",
  "kyc_status": "pending",
  "profile": {
    "user_id": 1,
    "user_type": "individual",
    "country_code": "VN"
  },
  "roles": [
    {
      "id": 1,
      "user_id": 1,
      "role_code": "user",
      "created_at": "2026-03-17T10:00:00.000000Z"
    }
  ]
}
```

### `PUT /user/users/{userId}/profile`

Request:

```json
{
  "phone": "+84901234567",
  "full_name": "Jane Doe",
  "provider_code": "AIRWALLEX",
  "profile": {
    "user_type": "business",
    "country_code": "VN",
    "company_name": "Origin Co",
    "company_reg_no": "123456789",
    "tax_id": "TAX-001",
    "address_line1": "123 Nguyen Hue",
    "city": "HCMC",
    "postal_code": "700000"
  }
}
```

Response:

```json
{
  "message": "Profile updated successfully.",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "phone": "+84901234567",
    "full_name": "Jane Doe",
    "status": "active",
    "kyc_status": "pending",
    "profile": {
      "user_id": 1,
      "user_type": "business",
      "country_code": "VN",
      "company_name": "Origin Co"
    },
    "roles": [],
    "provider_accounts": []
  }
}
```

## User APIs Requiring Completed Profile

### `GET /user/users/{userId}/provider-accounts`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "external_customer_id": null,
    "external_account_id": null,
    "account_name": null,
    "status": "pending",
    "metadata": {
      "integration_status": "awaiting_provider_details"
    },
    "provider": {
      "id": 1,
      "code": "AIRWALLEX",
      "name": "Airwallex",
      "status": "active"
    }
  }
]
```

### `GET /user/users/{userId}/provider-accounts/{providerId}`

Response when linked:

```json
{
  "id": 1,
  "user_id": 1,
  "provider_id": 1,
  "external_customer_id": null,
  "external_account_id": null,
  "account_name": null,
  "status": "pending",
  "metadata": {},
  "provider": {
    "id": 1,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  }
}
```

Response when not linked:

```json
{
  "provider": {
    "id": 1,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "provider_account": null,
  "link_available": true
}
```

### `POST /user/users/{userId}/provider-accounts/{providerId}/link`

Request:

```json
{
  "force": false
}
```

Response:

```json
{
  "message": "Airwallex account link request processed successfully.",
  "provider": {
    "id": 1,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "provider_account": {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "status": "pending"
  }
}
```

### `POST /user/users/{userId}/providers/{providerId}/sync/accounts`

### `POST /user/users/{userId}/providers/{providerId}/sync/balances`

### `POST /user/users/{userId}/providers/{providerId}/sync/transactions`

Response shape for all 3:

```json
{
  "message": "Accounts synced successfully.",
  "provider": {
    "id": 1,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "result": {
    "synced": 3
  }
}
```

### `POST /user/users/{userId}/fx-quotes`

Request:

```json
{
  "provider_id": 1,
  "source_currency": "USD",
  "target_currency": "VND",
  "source_amount": 1000,
  "target_amount": null
}
```

Response `201`:

```json
{
  "id": 1,
  "user_id": 1,
  "provider_id": 1,
  "quote_ref": "Q-123456",
  "source_currency": "USD",
  "target_currency": "VND",
  "source_amount": "1000.00000000",
  "target_amount": "25500000.00000000",
  "mid_rate": "25500.0000000000",
  "net_rate": "25450.0000000000",
  "fee_amount": "5.00000000",
  "expires_at": "2026-03-17T11:00:00.000000Z",
  "raw_data": {}
}
```

### `GET /user/users/{userId}/fx-quotes`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "quote_ref": "Q-123456",
    "source_currency": "USD",
    "target_currency": "VND",
    "source_amount": "1000.00000000",
    "target_amount": "25500000.00000000"
  }
]
```

### `GET /user/users/{userId}/fx-quotes/{fxQuoteId}`

Response: same object shape as create quote.

### `GET /user/users/{userId}/balances`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "bank_account_id": 1,
    "external_account_id": "acct_001",
    "currency": "USD",
    "available_balance": "1000.00000000",
    "ledger_balance": "1000.00000000",
    "reserved_balance": "0.00000000",
    "as_of": "2026-03-17T10:00:00.000000Z",
    "raw_data": {}
  }
]
```

### `GET /user/users/{userId}/bank-accounts`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "external_account_id": "acct_001",
    "account_type": "checking",
    "currency": "USD",
    "country_code": "US",
    "bank_name": "Demo Bank",
    "account_name": "Jane Doe",
    "account_number": "****1234",
    "status": "active",
    "is_default": true,
    "raw_data": {}
  }
]
```

### `GET /user/users/{userId}/bank-accounts/{bankAccountId}`

Response: same object shape as list item.

### `POST /user/users/{userId}/beneficiaries`

Request:

```json
{
  "provider_id": 1,
  "beneficiary_type": "individual",
  "full_name": "John Smith",
  "email": "john@example.com",
  "phone": "+12025550123",
  "country_code": "US",
  "currency": "USD",
  "bank_name": "Demo Bank",
  "bank_code": "001",
  "account_number": "123456789",
  "address_line1": "1 Main St",
  "city": "New York",
  "postal_code": "10001"
}
```

Response `201`:

```json
{
  "id": 1,
  "user_id": 1,
  "provider_id": 1,
  "external_beneficiary_id": "bene_001",
  "beneficiary_type": "individual",
  "full_name": "John Smith",
  "company_name": null,
  "email": "john@example.com",
  "phone": "+12025550123",
  "country_code": "US",
  "currency": "USD",
  "bank_name": "Demo Bank",
  "bank_code": "001",
  "account_number": "123456789",
  "status": "pending",
  "raw_data": {}
}
```

### `GET /user/users/{userId}/beneficiaries`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "beneficiary_type": "individual",
    "full_name": "John Smith",
    "country_code": "US",
    "currency": "USD",
    "status": "active"
  }
]
```

### `GET /user/users/{userId}/beneficiaries/{beneficiaryId}`

Response: same object shape as create beneficiary.

### `PUT /user/users/{userId}/beneficiaries/{beneficiaryId}`

Request:

```json
{
  "full_name": "John A. Smith",
  "bank_name": "New Demo Bank",
  "account_number": "987654321"
}
```

Response:

```json
{
  "id": 1,
  "user_id": 1,
  "provider_id": 1,
  "beneficiary_type": "individual",
  "full_name": "John A. Smith",
  "bank_name": "New Demo Bank",
  "account_number": "987654321",
  "status": "active"
}
```

### `DELETE /user/users/{userId}/beneficiaries/{beneficiaryId}`

Response: `204 No Content`

### `POST /user/users/{userId}/transfers`

Request:

```json
{
  "provider_id": 1,
  "source_bank_account_id": 1,
  "beneficiary_id": 1,
  "fx_quote_id": 1,
  "transfer_type": "bank_transfer",
  "source_currency": "USD",
  "target_currency": "VND",
  "source_amount": 1000,
  "purpose_code": "salary",
  "reference_text": "March payroll",
  "client_reference": "payroll-2026-03"
}
```

Response `201`:

```json
{
  "id": 1,
  "transfer_no": "TRF-ABC123XYZ789",
  "user_id": 1,
  "provider_id": 1,
  "source_bank_account_id": 1,
  "beneficiary_id": 1,
  "transfer_type": "bank_transfer",
  "source_currency": "USD",
  "target_currency": "VND",
  "source_amount": "1000.00000000",
  "target_amount": "25500000.00000000",
  "fx_rate": "25450.0000000000",
  "fee_amount": "5.00000000",
  "status": "draft",
  "raw_data": {
    "fx_quote_id": 1,
    "quote_ref": "Q-123456"
  }
}
```

### `GET /user/users/{userId}/transfers`

Response:

```json
[
  {
    "id": 1,
    "transfer_no": "TRF-ABC123XYZ789",
    "provider_id": 1,
    "source_amount": "1000.00000000",
    "target_amount": "25500000.00000000",
    "status": "draft"
  }
]
```

### `GET /user/users/{userId}/transfers/{transferId}`

Response:

```json
{
  "id": 1,
  "transfer_no": "TRF-ABC123XYZ789",
  "user_id": 1,
  "provider_id": 1,
  "status": "pending",
  "beneficiary": {
    "id": 1,
    "full_name": "John Smith"
  },
  "source_bank_account": {
    "id": 1,
    "account_name": "Jane Doe"
  },
  "approvals": [],
  "transactions": []
}
```

### `POST /user/users/{userId}/transfers/{transferId}/submit`

Response:

```json
{
  "message": "Transfer submitted successfully.",
  "transfer": {
    "id": 1,
    "transfer_no": "TRF-ABC123XYZ789",
    "status": "pending",
    "submitted_at": "2026-03-17T10:10:00.000000Z"
  }
}
```

### `POST /user/users/{userId}/transfers/{transferId}/cancel`

Response:

```json
{
  "id": 1,
  "transfer_no": "TRF-ABC123XYZ789",
  "status": "cancelled"
}
```

### `GET /user/users/{userId}/transactions`

Response:

```json
[
  {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "external_transaction_id": "txn_001",
    "transaction_type": "transfer",
    "direction": "debit",
    "currency": "USD",
    "amount": "1000.00000000",
    "fee_amount": "5.00000000",
    "status": "completed"
  }
]
```

### `GET /user/users/{userId}/transactions/{transactionId}`

Response:

```json
{
  "id": 1,
  "user_id": 1,
  "provider_id": 1,
  "external_transaction_id": "txn_001",
  "currency": "USD",
  "amount": "1000.00000000",
  "bank_account": {
    "id": 1,
    "account_name": "Jane Doe"
  },
  "transfer": {
    "id": 1,
    "transfer_no": "TRF-ABC123XYZ789"
  }
}
```

## Admin APIs

Prefix:

```text
/admin
```

### Users

#### `GET /admin/users`

Response: paginated users with `profile` and `roles`.

#### `POST /admin/users`

Request:

```json
{
  "email": "admin-created@example.com",
  "phone": "+84901234567",
  "full_name": "Created User",
  "password_hash": "secret123",
  "status": "active",
  "kyc_status": "pending"
}
```

Response `201`:

```json
{
  "id": 1,
  "email": "admin-created@example.com",
  "phone": "+84901234567",
  "full_name": "Created User",
  "status": "active",
  "kyc_status": "pending"
}
```

#### `GET /admin/users/{id}`

Response: user object with `profile` and `roles`.

#### `PUT /admin/users/{id}`

Request:

```json
{
  "full_name": "Updated User",
  "status": "suspended"
}
```

Response: updated user object.

#### `DELETE /admin/users/{id}`

Response: `204 No Content`

### Integration Providers

#### `GET /admin/integration-providers`

Response: paginated provider list.

#### `POST /admin/integration-providers`

Request:

```json
{
  "code": "AIRWALLEX",
  "name": "Airwallex",
  "status": "active"
}
```

Response `201`: provider object.

#### `GET /admin/integration-providers/{id}`

Response: provider object.

#### `PUT /admin/integration-providers/{id}`

Request:

```json
{
  "name": "Airwallex Global",
  "status": "inactive"
}
```

Response: updated provider object.

#### `DELETE /admin/integration-providers/{id}`

Response: `204 No Content`

### Bank Accounts

#### `GET /admin/bank-accounts`

Response: paginated bank account list.

#### `POST /admin/bank-accounts`

Request:

```json
{
  "user_id": 1,
  "provider_id": 1,
  "external_account_id": "acct_001",
  "account_type": "checking",
  "currency": "USD",
  "country_code": "US",
  "bank_name": "Demo Bank",
  "bank_code": "001",
  "branch_code": "123",
  "account_name": "Jane Doe",
  "account_number": "123456789",
  "iban": null,
  "swift_bic": "DEMOUS33",
  "routing_number": "021000021",
  "status": "active",
  "is_default": true,
  "raw_data": {}
}
```

Response `201`: bank account object.

#### `GET /admin/bank-accounts/{id}`

Response: bank account object.

#### `PUT /admin/bank-accounts/{id}`

Request:

```json
{
  "status": "inactive",
  "is_default": false
}
```

Response: updated bank account object.

#### `DELETE /admin/bank-accounts/{id}`

Response: `204 No Content`

### Beneficiaries

#### `GET /admin/beneficiaries`

Response: paginated beneficiary list.

#### `POST /admin/beneficiaries`

Request:

```json
{
  "user_id": 1,
  "provider_id": 1,
  "beneficiary_type": "individual",
  "full_name": "John Smith",
  "email": "john@example.com",
  "phone": "+12025550123",
  "country_code": "US",
  "currency": "USD",
  "bank_name": "Demo Bank",
  "bank_code": "001",
  "account_number": "123456789",
  "status": "active",
  "raw_data": {}
}
```

Response `201`: beneficiary object.

#### `GET /admin/beneficiaries/{id}`

Response: beneficiary object.

#### `PUT /admin/beneficiaries/{id}`

Request:

```json
{
  "full_name": "John A. Smith",
  "status": "inactive"
}
```

Response: updated beneficiary object.

#### `DELETE /admin/beneficiaries/{id}`

Response: `204 No Content`

### Transfers

#### `GET /admin/transfers`

Response: paginated transfer list.

#### `POST /admin/transfers`

Request:

```json
{
  "transfer_no": "TRF-MANUAL-0001",
  "user_id": 1,
  "provider_id": 1,
  "source_bank_account_id": 1,
  "beneficiary_id": 1,
  "external_transfer_id": "tr_001",
  "external_payment_id": "pay_001",
  "transfer_type": "bank_transfer",
  "source_currency": "USD",
  "target_currency": "VND",
  "source_amount": 1000,
  "target_amount": 25500000,
  "fx_rate": 25500,
  "fee_amount": 5,
  "fee_currency": "USD",
  "purpose_code": "salary",
  "reference_text": "March payroll",
  "client_reference": "manual-payroll-001",
  "status": "pending",
  "submitted_at": "2026-03-17T10:00:00Z",
  "raw_data": {}
}
```

Response `201`: transfer object.

#### `GET /admin/transfers/{id}`

Response: transfer object with `beneficiary`, `sourceBankAccount`, `approvals`, `transactions`.

#### `PUT /admin/transfers/{id}`

Request:

```json
{
  "status": "completed",
  "completed_at": "2026-03-17T10:30:00Z"
}
```

Response: updated transfer object.

#### `DELETE /admin/transfers/{id}`

Response: `204 No Content`

### Transactions

#### `GET /admin/transactions`

Response: paginated transaction list.

#### `POST /admin/transactions`

Request:

```json
{
  "user_id": 1,
  "provider_id": 1,
  "bank_account_id": 1,
  "transfer_id": 1,
  "external_transaction_id": "txn_001",
  "transaction_type": "transfer",
  "direction": "debit",
  "currency": "USD",
  "amount": 1000,
  "fee_amount": 5,
  "description": "Outbound transfer",
  "reference_text": "March payroll",
  "status": "completed",
  "booked_at": "2026-03-17T10:35:00Z",
  "value_date": "2026-03-17",
  "raw_data": {}
}
```

Response `201`: transaction object.

#### `GET /admin/transactions/{id}`

Response: transaction object with `bankAccount` and `transfer`.

#### `PUT /admin/transactions/{id}`

Request:

```json
{
  "status": "reversed",
  "description": "Manual reversal"
}
```

Response: updated transaction object.

#### `DELETE /admin/transactions/{id}`

Response: `204 No Content`

### `POST /admin/providers/{providerId}/users/{userId}/sync`

Response:

```json
{
  "message": "Airwallex sync submitted successfully.",
  "provider": {
    "id": 1,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "user_id": 1,
  "provider_account": {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "status": "pending"
  }
}
```

## Webhook API

### `POST /webhooks/providers/{provider}`

This endpoint is for provider-to-server callbacks, not frontend usage.
