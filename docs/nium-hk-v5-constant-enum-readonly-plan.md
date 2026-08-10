# Nium HK V5 Constant Enum Read-Only Plan

## Current audit

The repository has no implementation or configured endpoint for Nium's Fetch Constant Enums API. The public material audited for this remediation did not prove an exact endpoint path, so this repository must not add a guessed default.

HK sample values such as `MVHK01`, `ATVHK01`, `ATC01`, `HK008`, and `EM006` are examples, not a fixture migration source. Existing SG-derived values remain unverified for HK.

## Diagnostic contract

Implement the diagnostic only after Nium supplies an authoritative endpoint and category contract. It must:

1. Require an explicitly configured relative endpoint with the existing `{clientHashId}` placeholder policy.
2. Accept an explicit allowlist drawn only from: `businessType`, `annualTurnover`, `averageTransactionValue`, `countryName`, `countryOfOperation`, `documentType`, `intendedUseOfAccount`, `industrySector`, `monthlyTransactionVolume`, `monthlyTransactions`, `position`, and `totalEmployees`.
3. Issue one GET per explicitly approved category, with no automatic retry or pagination guessing.
4. Never call Customer Create, File, Payment ID, VA, beneficiary, FX, or transfer APIs.
5. Never update KYC, provider-account, submission-marker, or customer lifecycle state.
6. Persist only safe evidence: category, HTTP status, transport outcome, response timestamp, item count, and a SHA-256 response fingerprint.
7. Never log credentials, raw request headers, customer identifiers, fixture PII, or full enum responses.
8. Stop on the first unknown transport outcome or malformed response.

## One-shot execution sequence

1. Confirm the deployed commit and a clean worktree.
2. Confirm Customer Create count and durable submission marker are unchanged.
3. Confirm the authoritative enum endpoint and requested categories out of band.
4. Run a dry preflight that prints only categories and the redacted relative endpoint shape.
5. Obtain explicit approval for the read-only provider GET operation.
6. Execute each approved category once and record safe evidence.
7. Reconfirm Customer Create count, provider-account state, and submission marker are unchanged.
8. Review returned enum values before any separate fixture or validation change.

Until the endpoint is proven, the executable diagnostic remains intentionally unimplemented.
