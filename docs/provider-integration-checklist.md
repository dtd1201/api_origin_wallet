# Provider Integration Checklist

Use this checklist before marking a provider as ready for user-facing onboarding or live operations.

## 1. Register provider capabilities

Add the provider implementation to `config/integrations.php`.

Example capability map:

```php
'airwallex' => [
    'onboarding' => \App\Services\Airwallex\AirwallexOnboardingService::class,
    'webhook' => \App\Services\Airwallex\AirwallexWebhookService::class,
    'data_sync' => \App\Services\Airwallex\AirwallexDataSyncService::class,
    'quote' => \App\Services\Airwallex\AirwallexQuoteService::class,
    'transfer' => \App\Services\Airwallex\AirwallexTransferService::class,
    'beneficiary' => \App\Services\Airwallex\AirwallexBeneficiaryService::class,
    'payload_mapper' => \App\Services\Airwallex\AirwallexPayloadMapper::class,
],
```

Only declare capabilities that the provider really supports.

Current default approach for new providers such as Wise and Airwallex:

- enable `onboarding` first for manual link-based flows
- keep API-driven capabilities commented out until live credentials and payload mapping are confirmed

## 2. Add runtime service config

Add the provider credentials and endpoints to `config/services.php` and `.env.example`.

Minimum requirement for a provider to be considered configured:

- `base_url`

Typical extras:

- API keys or client credentials
- endpoint paths
- timeout
- webhook verification settings

Supported auth patterns in `ProviderHttpClient` now include:

- `static_headers`
- `bearer_token`
- `client_credentials`

Use `client_credentials` when a provider exposes an OAuth token endpoint and short-lived access tokens.

## 3. Keep user-visible flows capability-aware

Current backend behavior:

- `/api/providers` exposes capability flags per provider
- `/api/user/users/{userId}/provider-accounts` only lists providers with onboarding capability
- user `link`, `sync`, `quote`, `beneficiary`, and `transfer` actions return `422` when the provider does not support that capability

Practical rule:

- do not mark a provider active in the UI just because it exists in `integration_providers`
- it should have both implementation support and the required runtime config

## 4. Choose the right onboarding shape

Not every provider follows the same pattern.

Current generic onboarding base class assumes:

1. create customer
2. create account

Before reusing that flow for a new provider, confirm whether the provider needs:

- hosted onboarding or redirect flow
- OAuth consent flow
- async KYB review without direct account creation
- manual admin provisioning

If the provider does not match the current pattern, create a dedicated onboarding service instead of forcing it into the generic base class.

## 5. Verify provider-specific status mapping

Make sure provider account statuses map cleanly into local statuses used by:

- `ProviderAccountStatusManager`
- transfer eligibility checks
- frontend onboarding states

Common statuses that need mapping:

- submitted
- under_review
- active
- rejected
- failed

## 6. Test before enabling for users

Recommended minimum tests:

- provider appears in `/api/providers` with correct capability flags
- provider appears in `/provider-accounts` only when it supports onboarding
- unsupported actions return `422`
- quote, beneficiary, transfer, and sync flows work against sandbox credentials
- webhook signature verification passes with real sample payloads

## 7. Go-live checklist

- sandbox credentials verified
- production credentials stored
- webhook endpoint registered
- webhook secret configured
- request logs reviewed with real provider responses
- rate limits documented
- retry and failure behavior confirmed
