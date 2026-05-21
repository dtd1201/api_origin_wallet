# Nium KYC/KYB Customer Flow

Last reviewed: 2026-05-20

This document defines the customer-facing KYC/KYB flow for Origin Wallet, based on Nium's onboarding model and the current wallet backend structure.

## Nium Model To Follow

Nium separates onboarding into these customer types:

- Individual customer: natural person, requires KYC.
- Corporate customer: legal entity, requires KYB plus KYC for applicant and stakeholders.
- Corporate employee: follows an individual flow, with simplified requirements for some spend-management use cases.

Nium's current onboarding direction is Customer Onboarding v5, using one Create Customer request for individual and corporate customers, with region-specific objects and requirements.

Sources:

- Nium Customer Onboarding: https://docs.nium.com/docs/onboarding/customer-onboarding
- Nium Individual Customers: https://docs.nium.com/docs/onboarding/individual-customers
- Nium Corporate Customers: https://docs.nium.com/docs/onboarding/corporate-customers

## Status Model

Use one normalized internal status model and map Nium statuses into it.

| Internal status | Meaning | Nium mapping |
| --- | --- | --- |
| `draft` | User started but has not submitted | Local only |
| `submitted` | User submitted profile and documents | Local only or Nium `pending` |
| `awaiting_kyc` | User must complete hosted/e-doc verification | Nium `pending` + `awaiting_kyc` |
| `under_review` | Nium or internal team is reviewing | Nium `pending` + `under_review` |
| `needs_more_info` | More information is required | Nium `rfi_requested` |
| `verified` | Customer approved and can continue | Nium `clear` |
| `rejected` | Customer rejected | Nium `rejected` |
| `suspended` | Account temporarily blocked | Nium `suspended` |
| `closed` | Account closed | Nium `closed` |
| `terminated` | Account terminated by compliance | Nium `terminated` |

Important Nium behavior:

- Nium sends webhook events whenever onboarding `status` or `subStatus` changes.
- `clear` means the customer is onboarded and can start processing transactions.
- RFIs can occur during onboarding and may also happen after approval as part of ongoing due diligence.

## Customer Entry Flow

1. User registers and logs in.
2. User chooses account type:
   - Personal account: KYC.
   - Business account: KYB.
3. User chooses operating country and provider, for example `nium`.
4. App shows consent:
   - Privacy and data processing consent.
   - Consent to submit KYC/KYB data to provider.
   - Confirmation that false information can lead to rejection.
5. App creates or updates local profile.
6. App collects documents.
7. Backend runs internal validation and AML screening.
8. Backend submits to Nium or marks as ready for manual provider submission.
9. App tracks status until approved, rejected, or RFI requested.

## Individual KYC Flow

### Customer Screens

1. Personal details
   - Legal full name.
   - Date of birth.
   - Nationality.
   - Residence country.
   - Email and phone.

2. Residential address
   - Address line 1 and 2.
   - City.
   - State or province.
   - Postal code.
   - Country.

3. Identity document
   - Passport, national ID, driver license, or equivalent.
   - Document number.
   - Issuing country.
   - Issue date.
   - Expiry date.
   - Front/back images when applicable.

4. Proof of address
   - Bank statement, utility bill, residence certificate, or provider-accepted document.

5. Electronic verification, when required
   - Redirect the user to Nium hosted KYC/e-document verification.
   - Save Nium `redirectUrl` and `referenceId`.

6. Review and submit
   - Show summary.
   - User confirms and submits.

### Backend Steps

1. Save data to `kyc_profiles`.
2. Save files to `kyc_documents`.
3. Create requirements in `kyc_requirements`.
4. Run AML screening.
5. Submit to Nium Create Customer v5 when provider submission is enabled.
6. Store Nium identifiers:
   - `customerHashId`
   - `walletHashId`
   - `clientHashId`
   - `externalId`
   - `status`
   - `subStatus`
   - `redirectUrl`
7. Wait for webhook.
8. Update local status.

## Business KYB Flow

For a business customer, KYB includes company verification and KYC for the people connected to the company.

### Customer Screens

1. Business details
   - Legal business name.
   - Registration number.
   - Country of incorporation.
   - Date of incorporation.
   - Registered address.
   - Principal place of business.
   - Business activity and industry.
   - Operating countries.
   - Website URL or business evidence.
   - Tax ID, where applicable.

2. Applicant details
   - The applicant is the person submitting the application for the company.
   - Usually an authorized representative or signatory.
   - Collect personal KYC details and identity document.

3. Stakeholder details
   - Directors.
   - UBOs.
   - Shareholders.
   - Control persons.
   - Legal-entity shareholders, if any.
   - Ownership percentage.
   - Relationship type.
   - Personal KYC details for natural-person stakeholders.

4. Business documents
   - Business registration certificate.
   - Ownership chart or shareholder registry.
   - Articles, constitution, or equivalent if required.
   - Letter of authorization or board resolution, when applicant authority is not obvious.
   - Proof of business address if required.

5. Applicant and stakeholder verification
   - If Nium returns applicant/stakeholder KYC URLs, show or distribute those links.
   - Track each applicant/stakeholder `referenceId`.

6. Review and submit
   - Show business, applicant, stakeholder, and document summary.
   - User confirms and submits.

### Backend Steps

1. Save business data in `kyc_profiles` with `applicant_type = business`.
2. Save applicant and stakeholder data in `kyc_related_persons`.
3. Save business and person documents in `kyc_documents`.
4. Create requirements:
   - `profile_information`
   - `identity_document`
   - `proof_of_address`
   - `business_registration`
   - `authorized_representative`
   - `beneficial_owner`
5. Run AML screening for:
   - Business.
   - Applicant.
   - Each stakeholder.
6. Submit to Nium Create Customer v5.
7. Store Nium response:
   - `caseId`
   - `customerHashId`
   - `walletHashId`
   - `redirectUrl`
   - applicant KYC URL.
   - stakeholder KYC URLs.
   - errors and remarks.
8. Wait for Nium webhook.
9. If RFI is requested, create local requirements and show them to customer.
10. When Nium status is `clear`, mark local KYC/KYB as `verified` and allow provider onboarding.

## RFI Flow

RFI means Nium needs more information.

1. Webhook receives `rfi_requested`.
2. Backend stores RFI details against the KYC profile.
3. App shows RFI tasks to the customer.
4. Customer uploads missing documents or edits required fields.
5. Backend responds using Nium RFI endpoint or redirects to Nium hosted RFI form.
6. Local status changes to `under_review`.
7. Wait for next webhook.

## Admin Operations

Admin must be able to:

- View all KYC/KYB submissions.
- Filter by status.
- Review documents.
- Review AML screening results.
- Approve internal KYC/KYB before provider submission.
- Reject with a structured reason.
- Open provider submission status for Nium.
- View Nium identifiers and webhook history.
- Trigger provider resubmission only when Nium allows it.

Current backend already has:

- `PUT /api/user/users/{user}/kyc-profile`
- `GET /api/user/users/{user}/kyc-profile`
- `GET /api/admin/kyc-profiles`
- `GET /api/admin/users/{user}/kyc-profile`
- `POST /api/admin/users/{user}/kyc-profile/approve`
- `POST /api/admin/users/{user}/kyc-profile/reject`
- `GET /api/admin/kyc-provider-submissions`
- `POST /api/admin/users/{user}/kyc-profile/providers/{provider}/approve`
- `POST /api/admin/users/{user}/kyc-profile/providers/{provider}/reject`

## Mobile App Experience

Recommended mobile tabs or steps:

1. Account type
2. Personal or business details
3. People
4. Documents
5. Review
6. Verification
7. Status

Do not allow transfers, beneficiaries, or full provider account actions until:

- Internal KYC/KYB is `verified`.
- Provider submission for Nium is approved or Nium status is `clear`.
- A Nium `customerHashId` and `walletHashId` are stored on the user provider account.

## Nium Integration Checklist

Before production:

- Confirm supported regulatory region with Nium account manager.
- Configure Nium client account for the target region.
- Enable Customer Status webhook.
- Add webhook endpoint in backend.
- Whitelist server IPs with Nium when required.
- Store Nium `clientHashId` by region.
- Implement Create File and Upload Document calls.
- Implement Create Customer v5.
- Implement Customer Details polling fallback.
- Implement RFI response or hosted RFI redirect.
- Implement ODD webhook handling for ongoing due diligence.

## MVP Recommendation

Build this in two phases:

### Phase 1: Internal KYC/KYB

- Use existing local KYC tables.
- Customer submits KYC/KYB in mobile.
- Admin reviews internally.
- Admin approves provider submission readiness.
- This can go live before Nium direct onboarding is fully enabled.

### Phase 2: Direct Nium Submission

- Add Nium submission service.
- Submit KYC/KYB to Nium after internal review.
- Save Nium IDs and redirects.
- Process Nium webhooks.
- Support RFI and ODD.

