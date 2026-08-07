\set ON_ERROR_STOP on

-- Review-only artifact. Replace the historical snapshot fingerprint from the current diagnostic.
\set expected_fixture_marker '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__'
\set expected_historical_snapshot_b64 '__REPLACE_WITH_DOCS_18_19_20_JSON_BASE64__'

BEGIN;

LOCK TABLE profiles, kyc_profiles, kyc_related_persons, kyc_documents,
    integration_providers, user_provider_accounts, api_request_logs
    IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE fixture_v4_hk_transition_guard AS
SELECT
    :'expected_fixture_marker'::text AS expected_fixture_marker,
    :'expected_historical_snapshot_b64'::text AS expected_historical_snapshot_b64,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4) AS account_4_before,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7) AS account_7_before,
    (SELECT jsonb_agg(to_jsonb(kd) ORDER BY kd.id) FROM kyc_documents kd WHERE kd.id IN (18, 19, 20)) AS historical_before,
    (SELECT count(*) FROM api_request_logs) AS request_count_before,
    (
        SELECT count(*) FROM api_request_logs arl
        JOIN integration_providers ip ON ip.id = arl.provider_id
        WHERE ip.code = 'nium' AND arl.user_id = 9
          AND arl.operation = 'customer_create' AND arl.request_method = 'POST'
    ) AS customer_post_count_before;

DO $$
DECLARE
    expected_snapshot jsonb;
BEGIN
    IF (SELECT expected_fixture_marker FROM fixture_v4_hk_transition_guard) = '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__'
       OR (SELECT expected_historical_snapshot_b64 FROM fixture_v4_hk_transition_guard) = '__REPLACE_WITH_DOCS_18_19_20_JSON_BASE64__' THEN
        RAISE EXCEPTION 'Replace all reviewed current-checkpoint placeholders before execution.';
    END IF;

    BEGIN
        expected_snapshot := convert_from(
            decode((SELECT expected_historical_snapshot_b64 FROM fixture_v4_hk_transition_guard), 'base64'),
            'UTF8'
        )::jsonb;
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'Historical snapshot base64 or decoded JSON is invalid.';
    END;

    IF (SELECT historical_before FROM fixture_v4_hk_transition_guard)
        IS DISTINCT FROM expected_snapshot THEN
        RAISE EXCEPTION 'Historical documents 18/19/20 snapshot mismatch.';
    END IF;

    IF (SELECT request_count_before FROM fixture_v4_hk_transition_guard) <> 65
       OR (SELECT customer_post_count_before FROM fixture_v4_hk_transition_guard) <> 3 THEN
        RAISE EXCEPTION 'Locked request checkpoint mismatch.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM kyc_profiles kp
        WHERE kp.id = 9 AND kp.user_id = 9 AND kp.applicant_type = 'business'
          AND kp.country_code = 'SG'
          AND (SELECT expected_fixture_marker FROM fixture_v4_hk_transition_guard) = ANY (ARRAY[
              kp.metadata->>'fixture_marker', kp.metadata->>'fixture_version', kp.metadata->>'synthetic_fixture'
          ])
    ) THEN
        RAISE EXCEPTION 'Fixture V4 factual SG profile checkpoint mismatch.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM user_provider_accounts upa
        JOIN integration_providers ip ON ip.id = upa.provider_id
        WHERE upa.id = 7 AND upa.user_id = 9 AND ip.code = 'nium'
          AND upa.external_customer_id IS NULL AND upa.external_account_id IS NULL
    ) THEN
        RAISE EXCEPTION 'Provider Account 7 mismatch.';
    END IF;

    IF (
        SELECT count(*) FROM kyc_documents kd
        WHERE kd.id IN (21, 22, 23)
          AND kd.kyc_profile_id = 9
          AND kd.status IN ('approved', 'verified')
          AND kd.metadata->>'fixture_marker' = 'nium-corporate-synthetic-v5-hk'
          AND kd.metadata->>'nium_file_state' = 'AVAILABLE'
          AND kd.metadata ? 'nium_file_id'
          AND (kd.id, kd.file_hash, kd.metadata->>'logical_role') IN (
              (21, '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a', 'corporate_registration'),
              (22, '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2', 'applicant_authorized_person_identity'),
              (23, 'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953', 'beneficial_owner_stakeholder_identity')
          )
    ) <> 3 OR (
        SELECT count(DISTINCT kd.metadata->>'nium_file_id') FROM kyc_documents kd WHERE kd.id IN (21, 22, 23)
    ) <> 3 THEN
        RAISE EXCEPTION 'Reviewed HK documents are incomplete, unavailable, or non-unique.';
    END IF;

    IF (SELECT kyc_related_person_id FROM kyc_documents WHERE id = 21) IS NOT NULL
       OR NOT EXISTS (
           SELECT 1 FROM kyc_documents kd JOIN kyc_related_persons krp ON krp.id = kd.kyc_related_person_id
           WHERE kd.id = 22 AND krp.kyc_profile_id = 9
             AND lower(krp.relationship_type) IN ('applicant', 'authorized_representative', 'authorised_representative')
       )
       OR NOT EXISTS (
           SELECT 1 FROM kyc_documents kd JOIN kyc_related_persons krp ON krp.id = kd.kyc_related_person_id
           WHERE kd.id = 23 AND krp.kyc_profile_id = 9
             AND lower(krp.relationship_type) NOT IN ('applicant', 'authorized_representative', 'authorised_representative')
       ) THEN
        RAISE EXCEPTION 'Reviewed HK document role binding mismatch.';
    END IF;
END
$$;

UPDATE kyc_documents
SET status = 'superseded', updated_at = CURRENT_TIMESTAMP
WHERE id IN (18, 19, 20);

UPDATE profiles
SET country_code = 'HK', updated_at = CURRENT_TIMESTAMP
WHERE user_id = 9;

UPDATE kyc_profiles
SET registered_country_code = 'HK',
    address_line1 = '1 Synthetic Harbour Road', address_line2 = NULL,
    city = 'Hong Kong', state = 'Hong Kong', postal_code = NULL, country_code = 'HK',
    metadata = jsonb_set(jsonb_set(jsonb_set(jsonb_set(jsonb_set(jsonb_set(
        metadata #- '{nium_v5_fields,addresses}',
        '{nium_region}', '"HK"'::jsonb, true),
        '{nium_v5_fields,natureOfBusiness,operatingCountries}', '["HK"]'::jsonb, true),
        '{nium_v5_fields,expectedAccountUsage,credit,topTransactionCountries}', '["HK"]'::jsonb, true),
        '{nium_v5_fields,expectedAccountUsage,debit,topTransactionCountries}', '["HK"]'::jsonb, true),
        '{nium_v5_fields,bankAccountDetails,bankCountry}', '"HK"'::jsonb, true),
        '{nium_v5_fields,bankAccountDetails,currency}', '"HKD"'::jsonb, true),
    updated_at = CURRENT_TIMESTAMP
WHERE id = 9 AND user_id = 9;

UPDATE kyc_profiles
SET metadata = jsonb_set(metadata, '{nium_v5_fields,deviceDetails,ipCountryCode}', '"HK"'::jsonb, true),
    updated_at = CURRENT_TIMESTAMP
WHERE id = 9 AND user_id = 9;

DO $$
BEGIN
    IF (SELECT count(*) FROM kyc_documents WHERE id IN (18, 19, 20) AND status = 'superseded') <> 3
       OR (SELECT count(*) FROM kyc_documents WHERE id IN (21, 22, 23) AND status IN ('approved', 'verified')) <> 3 THEN
        RAISE EXCEPTION 'Historical supersession transition failed.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM kyc_profiles kp WHERE kp.id = 9 AND kp.user_id = 9
          AND kp.registered_country_code = 'HK' AND kp.country_code = 'HK'
          AND kp.metadata->>'nium_region' = 'HK'
          AND kp.metadata#>>'{nium_v5_fields,bankAccountDetails,bankCountry}' = 'HK'
          AND kp.metadata#>>'{nium_v5_fields,bankAccountDetails,currency}' = 'HKD'
          AND kp.metadata#>>'{nium_v5_fields,deviceDetails,ipCountryCode}' = 'HK'
    ) THEN
        RAISE EXCEPTION 'HK factual profile transition failed.';
    END IF;

    IF (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4)
        IS DISTINCT FROM (SELECT account_4_before FROM fixture_v4_hk_transition_guard)
       OR (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7)
        IS DISTINCT FROM (SELECT account_7_before FROM fixture_v4_hk_transition_guard) THEN
        RAISE EXCEPTION 'Protected provider accounts changed.';
    END IF;

    IF (SELECT count(*) FROM api_request_logs) <> 65
       OR (SELECT count(*) FROM api_request_logs arl JOIN integration_providers ip ON ip.id = arl.provider_id
           WHERE ip.code = 'nium' AND arl.user_id = 9 AND arl.operation = 'customer_create' AND arl.request_method = 'POST') <> 3 THEN
        RAISE EXCEPTION 'Provider request evidence changed.';
    END IF;
END
$$;

COMMIT;
