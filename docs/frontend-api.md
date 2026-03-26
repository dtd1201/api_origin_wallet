# Frontend API Guide

This document is intended for frontend implementation.

Use it for:

- building API clients / service layer
- generating TypeScript types
- mapping screens to backend flows
- handling auth, onboarding, profile completion, and admin behavior correctly

## Environment

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

## Frontend Rules

- Public endpoints can be called without a token.
- All authenticated endpoints require `Authorization: Bearer {token}`.
- All `/user/*` endpoints require a token belonging to a user account.
- All `/admin/*` endpoints require a token belonging to an admin account.
- Some `/user/*` endpoints additionally require the user profile to be completed first.
- Validation errors usually return `422 Unprocessable Entity`.
- Delete endpoints usually return `204 No Content`.
- Provider-managed fields inside `result`, `raw_data`, provider accounts, quotes, and transfers can vary by integration.

## Admin Frontend Notes

The admin frontend should be treated as a separate project from the user frontend.

Recommended setup:

- user app on one subdomain, for example `wallet.example.com`
- admin app on a different subdomain, for example `admin-wallet.example.com`
- both apps can call the same backend API base URL

Important implementation notes:

- do not share login flow between user app and admin app
- user app uses `/auth/*`
- admin app uses `/admin/auth/*`
- admin app does not support register or Google login
- store admin token and user token under different storage keys
- do not reuse user auth guards/hooks blindly inside the admin app
- the admin app should call `GET /admin/auth/me` on bootstrap
- if admin token returns `401` or `403`, clear admin session and redirect to admin login

Suggested token storage keys:

- `origin_wallet_user_token`
- `origin_wallet_admin_token`

CORS / deploy notes:

- add both user frontend origin and admin frontend origin to `CORS_ALLOWED_ORIGINS`
- deploy user frontend and admin frontend separately
- keep environment variables separate for each app
- admin subdomain should generally be marked `noindex`

Current backend CORS behavior:

- backend reads allowed origins from `CORS_ALLOWED_ORIGINS`
- CORS applies to `api/*`
- Bearer-token auth does not require `supports_credentials=true`
- adding the admin frontend origin will not affect user auth flow as long as each frontend uses its own token storage and auth routes

Production example:

```env
CORS_ALLOWED_ORIGINS=https://khoinguyenoriginwallet.com,https://www.khoinguyenoriginwallet.com,https://admin.khoinguyenoriginwallet.com
```

Local development example:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173,http://localhost:8080,http://127.0.0.1:8080,http://localhost:3001
```

Recommended deployment checklist for admin frontend:

- point admin frontend to the same backend API base URL
- add admin frontend origin to `CORS_ALLOWED_ORIGINS`
- run `php artisan optimize:clear` after changing env values on the API server
- verify preflight and API calls from browser devtools on the admin subdomain

## Recommended Frontend Flow

### 1. App bootstrap

On app startup:

1. Read token from storage.
2. If token exists, call `GET /auth/me`.
3. If `401`, clear token and send user back to login.
4. Use `onboarding.profile_completed` and `onboarding.next_action` to decide the first screen.

### 2. Register flow

1. Call `POST /auth/register`.
2. Ask user for the verification code from email.
3. Call `POST /auth/register/verify`.
4. Save returned token.
5. If `onboarding.profile_completed === false`, navigate to profile completion / onboarding.

Alternative:

- Email links can use `GET /auth/register/activate?...`.

### 3. Login flow

1. Call `POST /auth/login` with email and password.
2. Ask user for the login verification code from email.
3. Call `POST /auth/login/verify`.
4. Save returned token.
5. Use `onboarding` in the response to decide whether to send the user to:
   - complete profile
   - wait for provider / KYC review
   - dashboard

### 4. Google login flow

1. Obtain Google `id_token` from the frontend Google auth SDK.
2. Call `POST /auth/google`.
3. Save returned token.
4. Use `onboarding.profile_completed` exactly like email registration/login.

### 5. Admin login flow

1. Call `POST /admin/auth/login` with email and password.
2. Ask admin user for the login verification code from email.
3. Call `POST /admin/auth/login/verify`.
4. Save returned token.
5. On admin app startup, call `GET /admin/auth/me`.

Important rule:

- admin login only works for accounts whose `user.roles` includes an admin role such as `admin` or `super_admin`

### 6. Password reset flow

1. Call `POST /auth/forgot-password`.
2. Ask user for the verification code from email.
3. Call `POST /auth/reset-password`.
4. Redirect user back to the login screen.

### 7. Profile completion flow

After login or registration, the frontend should inspect:

- `onboarding.profile_completed`
- `onboarding.selected_provider_code`
- `onboarding.selected_provider_account_status`
- `onboarding.next_action`
- `onboarding.message`

Practical rule:

- if `profile_completed` is `false`, send user to complete profile
- if `profile_completed` is `true` but provider account / KYC is still pending, show pending / review state
- only enable profile-complete-only endpoints when profile is complete

## How Frontend Should Use `userId`

Many user endpoints are shaped like:

```text
/user/users/{userId}/...
```

Frontend should normally use:

```text
userId = authMe.user.id
```

or:

```text
userId = login/register response.user.id
```

The frontend should not let users manually choose another `userId`.

## Common HTTP Statuses

- `200 OK`: successful read/update/login verify flow
- `201 Created`: created new resource
- `202 Accepted`: action started, usually waiting for email verification
- `204 No Content`: delete succeeded
- `401 Unauthorized`: token missing / invalid / expired
- `403 Forbidden`: token exists but role or signature is invalid
- `404 Not Found`: resource not found
- `422 Unprocessable Entity`: validation error or flow state error

## Common Error Shapes

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

Simple error example:

```json
{
  "message": "Invalid credentials."
}
```

Frontend rule:

- prefer field-level mapping when `errors` exists
- otherwise show `message`

## Important Enums And Common Values

These values appear repeatedly in responses and are useful for frontend UI logic.

### User-related

- `status`: commonly `pending`, `active`, `suspended`
- `kyc_status`: commonly `pending`
- `profile.user_type`: commonly `individual`, `business`

### Provider account / onboarding-related

- `selected_provider_account_status`: commonly `not_started`, `pending`
- `provider_account.status`: commonly `pending`, `active`

### Transfer-related

- `transfer_type`: commonly `bank_transfer`
- `status`: commonly `draft`, `pending`, `completed`, `cancelled`

The backend may add more values over time, so frontend should avoid hard-crashing on unknown enum values.

## Date And Number Handling

- Datetimes are returned as ISO-8601 strings, for example `2026-03-17T10:00:00.000000Z`.
- Monetary values are often returned as decimal strings, not JS numbers.
- Frontend should treat monetary fields as strings until formatted for display or converted with a safe decimal library.

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
    "profile": null,
    "roles": []
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
      "supports_beneficiaries": false,
      "supports_data_sync": false,
      "supports_quotes": false,
      "supports_transfers": false,
      "supports_webhooks": false,
      "is_configured": false
    },
    {
      "id": 2,
      "code": "CURRENXIE",
      "name": "Currenxie",
      "status": "active",
      "is_available_for_onboarding": true,
      "supports_beneficiaries": true,
      "supports_data_sync": true,
      "supports_quotes": true,
      "supports_transfers": true,
      "supports_webhooks": true,
      "is_configured": true
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
      "supports_beneficiaries": false,
      "supports_data_sync": false,
      "supports_quotes": false,
      "supports_transfers": false,
      "supports_webhooks": false,
      "is_configured": false
    },
    {
      "id": 2,
      "code": "WISE",
      "name": "Wise",
      "status": "active",
      "is_available_for_onboarding": true,
      "supports_beneficiaries": false,
      "supports_data_sync": false,
      "supports_quotes": false,
      "supports_transfers": false,
      "supports_webhooks": false,
      "is_configured": false
    },
    {
      "id": 3,
      "code": "CURRENXIE",
      "name": "Currenxie",
      "status": "active",
      "is_available_for_onboarding": true,
      "supports_beneficiaries": true,
      "supports_data_sync": true,
      "supports_quotes": true,
      "supports_transfers": true,
      "supports_webhooks": true,
      "is_configured": true
    }
  ]
}
```

Frontend notes:

- `is_available_for_onboarding` means the provider can participate in the onboarding flow
- a provider can still have `is_available_for_onboarding === true` while `is_configured === false`
- `is_configured === false` means live backend-driven API actions such as sync, quotes, transfers, or direct API onboarding may not be ready yet
- current default seeded setup includes `Currenxie`, `Wise`, and `Airwallex`
- `Wise` and `Airwallex` currently default to manual-link onboarding unless backend API capabilities are explicitly enabled later

### `POST /contact`

Public endpoint for the website contact form.

Request:

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "company": "Origin Wallet",
  "subject": "Need help with onboarding",
  "message": "Please contact me about account setup."
}
```

Validation rules:

- `name`: required, string, max 255
- `email`: required, valid email, max 255
- `company`: optional, string, max 255
- `subject`: required, string, max 255
- `message`: required, string, max 5000

Response `201`:

```json
{
  "message": "Contact message submitted successfully.",
  "data": {
    "id": 1,
    "submitted_at": "2026-03-26T03:00:00.000000Z"
  }
}
```

Frontend notes:

- no authentication is required
- frontend should show a success state after `201`
- frontend should map `422` validation errors to form fields
- backend also stores request metadata such as IP address and user agent for support follow-up

## Auth APIs

### `POST /auth/chatbot/message`

Authenticated chatbot MVP endpoint for frontend chat UI.

Headers:

```http
Authorization: Bearer {token}
```

Request:

```json
{
  "message": "How do I add a beneficiary?",
  "conversation_id": null
}
```

Response `200`:

```json
{
  "conversation_id": "conv_001",
  "reply": "To add a beneficiary, open the beneficiaries screen and provide recipient banking details such as full name, country, currency, bank name, and account number.",
  "suggestions": [
    "How do I create a transfer?",
    "What beneficiary fields are required?"
  ],
  "actions": [
    {
      "type": "navigate",
      "label": "Open Beneficiaries",
      "target": "/beneficiaries"
    },
    {
      "type": "navigate",
      "label": "Add Beneficiary",
      "target": "/beneficiaries/new"
    }
  ],
  "meta": {
    "profile_completed": true,
    "has_provider_account": true,
    "has_beneficiaries": false,
    "has_balances": true
  }
}
```

Frontend notes:

- This MVP is rule-based, not AI-model-based yet.
- `conversation_id` can be reused by frontend UI, but the current MVP does not persist conversation history.
- `actions` are intended for UI shortcuts like navigation buttons.
- `suggestions` are intended for quick-reply chips.

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
    },
    "roles": []
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

### `POST /admin/auth/login`

Same 2-step login behavior as `/auth/login`, but only for admin accounts.

Request:

```json
{
  "email": "admin@example.com",
  "password": "secret123"
}
```

Response `202`:

```json
{
  "message": "Verification code sent to email. Please verify to complete login.",
  "email": "admin@example.com",
  "expires_in_minutes": 15
}
```

Response `403`:

```json
{
  "message": "This account is not allowed to access admin."
}
```

### `POST /admin/auth/login/verify`

Request:

```json
{
  "email": "admin@example.com",
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
    "id": 99,
    "email": "admin@example.com",
    "phone": null,
    "full_name": "Backoffice Admin",
    "status": "active",
    "kyc_status": "approved",
    "profile": null,
    "roles": [
      "admin"
    ]
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

### `GET /admin/auth/me`

Headers:

```http
Authorization: Bearer {adminToken}
```

Response: same auth payload shape as `POST /admin/auth/login/verify`.

### `POST /admin/auth/logout`

Headers:

```http
Authorization: Bearer {adminToken}
```

Response:

```json
{
  "message": "Logged out successfully."
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
{
  "data": [
    {
      "provider": {
        "id": 2,
        "code": "AIRWALLEX",
        "name": "Airwallex",
        "status": "active"
      },
      "provider_account": null,
      "integration_link": null,
      "integration_request": null,
      "link_available": false,
      "can_connect": false,
      "can_request_connect": true,
      "request_pending": false
    },
    {
      "provider": {
        "id": 1,
        "code": "CURRENXIE",
        "name": "Currenxie",
        "status": "active"
      },
      "provider_account": null,
      "integration_link": {
        "id": 10,
        "user_id": 1,
        "provider_id": 1,
        "link_url": "https://provider.example.com/connect/user-123",
        "link_label": "Connect provider",
        "is_active": true,
        "created_at": "2026-03-24T03:00:00.000000Z",
        "updated_at": "2026-03-24T03:00:00.000000Z"
      },
      "integration_request": null,
      "link_available": true,
      "can_connect": true,
      "can_request_connect": false,
      "request_pending": false
    }
  ]
}
```

Frontend notes:

- this endpoint returns active providers that support onboarding, not every active provider in the database
- use `integration_link.link_url` for the connect CTA URL
- use `integration_link.link_label` for button text when present
- the `{providerCode}` route parameter uses provider `code`, for example `CURRENXIE` or `AIRWALLEX`
- `link_available` becomes `true` when the provider supports onboarding and the user has an active integration link
- this allows manual link-based onboarding even before full provider API config exists
- show `Connect provider` when `can_connect === true`
- show `Request connect` when `can_request_connect === true`
- disable the request button and show pending state when `request_pending === true`
- `provider_account` can be `null` before the user completes the external provider connection
- `Wise` and `Airwallex` may appear here with manual onboarding availability even when sync/quote/transfer features are still disabled

### `GET /user/users/{userId}/provider-accounts/{providerCode}`

Response when linked:

```json
{
  "provider": {
    "id": 1,
    "code": "CURRENXIE",
    "name": "Currenxie",
    "status": "active"
  },
  "provider_account": {
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
      "code": "CURRENXIE",
      "name": "Currenxie",
      "status": "active"
    }
  },
  "integration_link": {
    "id": 10,
    "user_id": 1,
    "provider_id": 1,
    "link_url": "https://provider.example.com/connect/user-123",
    "link_label": "Connect provider",
    "is_active": true,
    "created_at": "2026-03-24T03:00:00.000000Z",
    "updated_at": "2026-03-24T03:00:00.000000Z"
  },
  "integration_request": null,
  "link_available": true,
  "can_connect": true,
  "can_request_connect": false,
  "request_pending": false
}
```

Response when not linked:

```json
{
  "provider": {
    "id": 2,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "provider_account": null,
  "integration_link": null,
  "integration_request": {
    "id": 7,
    "user_id": 1,
    "provider_id": 2,
    "status": "pending",
    "note": "Please enable this provider for my account.",
    "requested_at": "2026-03-24T04:00:00.000000Z",
    "resolved_at": null,
    "created_at": "2026-03-24T04:00:00.000000Z",
    "updated_at": "2026-03-24T04:00:00.000000Z"
  },
  "link_available": false,
  "can_connect": false,
  "can_request_connect": true,
  "request_pending": true
}
```

### `POST /user/users/{userId}/provider-accounts/{providerCode}/link`

Request:

```json
{
  "force": false
}
```

Response:

```json
{
  "message": "Currenxie onboarding request sent successfully.",
  "provider": {
    "id": 1,
    "code": "CURRENXIE",
    "name": "Currenxie",
    "status": "active"
  },
  "provider_account": {
    "id": 1,
    "user_id": 1,
    "provider_id": 1,
    "status": "pending"
  },
  "onboarding": {
    "status": "pending",
    "next_action": "wait_for_provider_review",
    "message": "Currenxie onboarding request sent successfully.",
    "action_type": "direct_api",
    "metadata": {
      "provider_code": "CURRENXIE",
      "provider_account_status": "pending"
    }
  }
}
```

Frontend notes:

- use `onboarding.next_action` to decide whether to wait, redirect, or show success state
- `onboarding.redirect_url` can be present for hosted / redirect-based providers
- `provider_account` remains the canonical local account record when available

### `POST /user/users/{userId}/provider-accounts/{providerCode}/request-connect`

Use this when the user sees a provider card but no connect link is available yet.

Request:

```json
{
  "note": "Please enable this provider for my account."
}
```

Response `202`:

```json
{
  "message": "Provider connection request submitted successfully.",
  "provider": {
    "id": 2,
    "code": "AIRWALLEX",
    "name": "Airwallex",
    "status": "active"
  },
  "integration_request": {
    "id": 7,
    "user_id": 1,
    "provider_id": 2,
    "status": "pending",
    "note": "Please enable this provider for my account.",
    "requested_at": "2026-03-24T04:00:00.000000Z",
    "resolved_at": null,
    "created_at": "2026-03-24T04:00:00.000000Z",
    "updated_at": "2026-03-24T04:00:00.000000Z"
  },
  "request_pending": true
}
```

Response `422`:

```json
{
  "message": "This provider is already available for connection."
}
```

### `POST /user/users/{userId}/provider-accounts/{providerCode}/complete`

Use this after a hosted or redirect-based provider sends the user back to your frontend and you need to finalize local onboarding state.

Example request:

```json
{
  "status": "active",
  "external_customer_id": "cust_123",
  "external_account_id": "acct_456",
  "account_name": "Jane Doe - Hosted"
}
```

Example response:

```json
{
  "message": "Hosted Provider onboarding completed successfully.",
  "provider": {
    "id": 3,
    "code": "HOSTED_PROVIDER",
    "name": "Hosted Provider",
    "status": "active"
  },
  "provider_account": {
    "id": 1,
    "user_id": 1,
    "provider_id": 3,
    "external_customer_id": "cust_123",
    "external_account_id": "acct_456",
    "account_name": "Jane Doe - Hosted",
    "status": "active"
  },
  "onboarding": {
    "status": "active",
    "next_action": "provider_onboarding_completed",
    "message": "Hosted Provider onboarding completed successfully.",
    "action_type": "callback",
    "metadata": {
      "provider_code": "HOSTED_PROVIDER",
      "provider_account_status": "active"
    }
  }
}
```

Frontend notes:

- call this only for providers whose `link` response indicates a redirect-style flow
- after success, refresh `GET /auth/me` or `GET /user/users/{userId}/provider-accounts/{providerCode}`
- for providers with direct API onboarding, this endpoint can return `422`

### `POST /user/users/{userId}/providers/{providerCode}/sync/accounts`

### `POST /user/users/{userId}/providers/{providerCode}/sync/balances`

### `POST /user/users/{userId}/providers/{providerCode}/sync/transactions`

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

Important rule:

- this list excludes admin accounts such as `admin` and `super_admin`

#### `POST /admin/users`

Request:

```json
{
  "email": "admin-created@example.com",
  "phone": "+84901234567",
  "full_name": "Created User",
  "password": "secret123",
  "status": "active",
  "kyc_status": "pending",
  "integration_links": [
    {
      "provider_code": "CURRENXIE",
      "link_url": "https://provider.example.com/connect/currenxie",
      "link_label": "Connect Currenxie",
      "is_active": true
    },
    {
      "provider_code": "AIRWALLEX",
      "link_url": "https://provider.example.com/connect/airwallex",
      "link_label": "Connect Airwallex",
      "is_active": false
    }
  ]
}
```

Notes:

- `password` is the recommended field for frontend use
- `password_hash` is still accepted for backward compatibility
- this endpoint creates regular users only, not admin users
- `integration_links` is optional
- when provided, it defines which providers will appear on the user's integrations screen

Response `201`:

```json
{
  "id": 1,
  "email": "admin-created@example.com",
  "phone": "+84901234567",
  "full_name": "Created User",
  "status": "active",
  "kyc_status": "pending",
  "roles": [],
  "integration_links": [
    {
      "id": 10,
      "user_id": 1,
      "provider_id": 1,
      "link_url": "https://provider.example.com/connect/currenxie",
      "link_label": "Connect Currenxie",
      "is_active": true,
      "provider": {
        "id": 1,
        "code": "CURRENXIE",
        "name": "Currenxie",
        "status": "active"
      }
    }
  ],
  "available_providers": [
    {
      "id": 2,
      "code": "AIRWALLEX",
      "name": "Airwallex",
      "status": "active"
    },
    {
      "id": 1,
      "code": "CURRENXIE",
      "name": "Currenxie",
      "status": "active"
    }
  ]
}
```

Important rule:

- `available_providers` only includes providers with `status = active`

#### `GET /admin/users/{id}`

Response: user object with `profile`, `roles`, current `integration_links`, and `available_providers`.

Important rule:

- returns `404` if `{id}` belongs to an admin account
- `available_providers` only includes providers with `status = active`

#### `PUT /admin/users/{id}`

Request:

```json
{
  "full_name": "Updated User",
  "status": "suspended",
  "password": "new-secret123",
  "integration_links": [
    {
      "provider_code": "AIRWALLEX",
      "link_url": "https://provider.example.com/connect/airwallex",
      "link_label": "Connect Airwallex",
      "is_active": true
    }
  ]
}
```

Response: updated user object with `integration_links` and `available_providers`.

Important rule:

- returns `404` if `{id}` belongs to an admin account
- if `integration_links` is provided, the backend syncs the whole provider assignment list for that user
- providers omitted from `integration_links` will be removed from the user's assigned provider list
- `integration_links[*].provider_code` must reference an active provider; inactive providers are rejected with validation error `422`

#### `DELETE /admin/users/{id}`

Response: `204 No Content`

Important rule:

- returns `404` if `{id}` belongs to an admin account

### Contact Submissions

#### `GET /admin/contact-submissions`

Admin-only paginated inbox for contact form submissions.

Response:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 2,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "company": "Origin Wallet",
      "subject": "Need help with onboarding",
      "message": "Please contact me about account setup.",
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0",
      "submitted_at": "2026-03-26T03:00:00.000000Z",
      "created_at": "2026-03-26T03:00:00.000000Z",
      "updated_at": "2026-03-26T03:00:00.000000Z"
    }
  ],
  "per_page": 15,
  "total": 1
}
```

Frontend notes:

- requires admin bearer token
- ordered newest first by `submitted_at`
- use standard Laravel pagination fields for table pagination

#### `GET /admin/contact-submissions/{id}`

Admin-only endpoint for the full detail of one contact submission.

Response:

```json
{
  "id": 2,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "company": "Origin Wallet",
  "subject": "Need help with onboarding",
  "message": "Please contact me about account setup.",
  "ip_address": "127.0.0.1",
  "user_agent": "Mozilla/5.0",
  "submitted_at": "2026-03-26T03:00:00.000000Z",
  "created_at": "2026-03-26T03:00:00.000000Z",
  "updated_at": "2026-03-26T03:00:00.000000Z"
}
```

#### `GET /admin/users/{id}/integration-links`

Response:

```json
{
  "user_id": 12,
  "data": [
    {
      "provider": {
        "id": 1,
        "code": "CURRENXIE",
        "name": "Currenxie",
        "status": "active"
      },
      "integration_link": {
        "id": 10,
        "user_id": 12,
        "provider_id": 1,
        "link_url": "https://provider.example.com/connect/user-123",
        "link_label": "Connect provider",
        "is_active": true,
        "created_at": "2026-03-24T03:00:00.000000Z",
        "updated_at": "2026-03-24T03:00:00.000000Z"
      },
      "integration_request": null
    },
    {
      "provider": {
        "id": 2,
        "code": "AIRWALLEX",
        "name": "Airwallex",
        "status": "active"
      },
      "integration_link": null,
      "integration_request": {
        "id": 7,
        "user_id": 12,
        "provider_id": 2,
        "status": "pending",
        "note": "Please enable this provider for my account.",
        "requested_at": "2026-03-24T04:00:00.000000Z",
        "resolved_at": null,
        "created_at": "2026-03-24T04:00:00.000000Z",
        "updated_at": "2026-03-24T04:00:00.000000Z"
      }
    }
  ]
}
```

Important rule:

- returns one slot per active provider
- each user can have at most one link per provider
- pending user requests appear in `integration_request`
- returns `404` if `{id}` belongs to an admin account

#### `PUT /admin/users/{id}/integration-links/{providerCode}`

Request:

```json
{
  "link_url": "https://provider.example.com/connect/user-123",
  "link_label": "Connect provider",
  "is_active": true
}
```

Response:

```json
{
  "message": "Currenxie integration link saved successfully.",
  "user_id": 12,
  "provider": {
    "id": 1,
    "code": "CURRENXIE",
    "name": "Currenxie",
    "status": "active"
  },
  "integration_link": {
    "id": 10,
    "user_id": 12,
    "provider_id": 1,
    "link_url": "https://provider.example.com/connect/user-123",
    "link_label": "Connect provider",
    "is_active": true,
    "created_at": "2026-03-24T03:00:00.000000Z",
    "updated_at": "2026-03-24T03:00:00.000000Z"
  },
  "integration_request": {
    "id": 7,
    "user_id": 12,
    "provider_id": 1,
    "status": "resolved",
    "note": "Please enable this provider for my account.",
    "requested_at": "2026-03-24T04:00:00.000000Z",
    "resolved_at": "2026-03-24T04:30:00.000000Z",
    "created_at": "2026-03-24T04:00:00.000000Z",
    "updated_at": "2026-03-24T04:30:00.000000Z"
  }
}
```

Important rule:

- if a link already exists for the same `{user, provider}`, this endpoint updates it instead of creating a second one
- if there was a pending user request for this provider, it is marked as `resolved`
- returns `404` if `{id}` belongs to an admin account

#### `DELETE /admin/users/{id}/integration-links/{providerCode}`

Response: `204 No Content`

Important rule:

- returns `404` if `{id}` belongs to an admin account

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

#### `GET /admin/integration-providers/{providerCode}`

Response: provider object.

#### `PUT /admin/integration-providers/{providerCode}`

Request:

```json
{
  "name": "Airwallex Global",
  "status": "inactive"
}
```

Response: updated provider object.

#### `DELETE /admin/integration-providers/{providerCode}`

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

### `POST /admin/providers/{providerCode}/users/{userId}/sync`

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
