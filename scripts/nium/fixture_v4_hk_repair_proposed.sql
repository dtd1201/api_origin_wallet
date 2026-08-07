\set ON_ERROR_STOP on

-- Do not run until fixture_v4_hk_diagnostic.sql has been reviewed.
-- Replace this placeholder with the exact existing marker shown by the diagnostic.
\set expected_fixture_marker '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__'

BEGIN;

LOCK TABLE kyc_profiles, profiles, user_provider_accounts, kyc_documents, api_request_logs
    IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE fixture_v4_hk_guard AS
SELECT
    :'expected_fixture_marker'::text AS expected_fixture_marker,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4) AS account_4_before,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7) AS account_7_before,
    (SELECT count(*) FROM api_request_logs) AS request_count_before,
    (
        SELECT count(*)
        FROM api_request_logs arl
        JOIN user_provider_accounts upa ON upa.user_id = arl.user_id
        WHERE upa.id = 7
          AND arl.request_method = 'POST'
          AND arl.operation = 'customer_create'
    ) AS customer_post_count_before;

DO $$
DECLARE
    expected_marker text := (SELECT expected_fixture_marker FROM fixture_v4_hk_guard);
BEGIN
    IF expected_marker = '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__' THEN
        RAISE EXCEPTION 'Replace expected_fixture_marker with the diagnostic value before running.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM kyc_profiles kp
        WHERE kp.id = 9
          AND kp.user_id = 9
          AND kp.applicant_type = 'business'
          AND kp.metadata->>'nium_region' = 'SG'
          AND expected_marker = ANY (ARRAY[
              kp.metadata->>'fixture_marker',
              kp.metadata->>'fixture_version',
              kp.metadata->>'synthetic_fixture'
          ])
    ) THEN
        RAISE EXCEPTION 'Fixture V4 profile identity or marker mismatch.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM user_provider_accounts upa
        JOIN integration_providers ip ON ip.id = upa.provider_id
        WHERE upa.id = 7
          AND upa.user_id = 9
          AND ip.code = 'nium'
          AND upa.external_customer_id IS NULL
          AND upa.external_account_id IS NULL
    ) THEN
        RAISE EXCEPTION 'Provider Account 7 is not the expected unresolved Nium fixture account.';
    END IF;

    IF (SELECT request_count_before FROM fixture_v4_hk_guard) <> 56 THEN
        RAISE EXCEPTION 'Provider request log count is not the locked value 56.';
    END IF;

    IF (SELECT customer_post_count_before FROM fixture_v4_hk_guard) <> 3 THEN
        RAISE EXCEPTION 'Fixture V4 customer POST count is not the locked value 3.';
    END IF;

    IF (
        SELECT count(*)
        FROM kyc_documents kd
        WHERE (kd.id, kd.metadata->>'nium_file_id', kd.metadata->>'nium_file_state') IN (
            (18, '5dde122a-c143-4358-8b1c-ffaeb397c27c', 'AVAILABLE'),
            (19, '6afef4a-a845-4900-9623-c327844e4323', 'AVAILABLE'),
            (20, '9cd49d73-7006-4bbb-8801-45c4ad1a9177', 'AVAILABLE')
        )
    ) <> 3 THEN
        RAISE EXCEPTION 'Fixture V4 Nium file identity or AVAILABLE state mismatch.';
    END IF;
END
$$;

UPDATE profiles
SET country_code = 'HK',
    updated_at = CURRENT_TIMESTAMP
WHERE user_id = 9;

UPDATE kyc_profiles
SET registered_country_code = 'HK',
    address_line1 = '1 Synthetic Harbour Road',
    address_line2 = NULL,
    city = 'Hong Kong',
    state = 'Hong Kong',
    postal_code = NULL,
    country_code = 'HK',
    metadata = jsonb_set(
        jsonb_set(
            jsonb_set(
                jsonb_set(
                    jsonb_set(
                        jsonb_set(
                            metadata #- '{nium_v5_fields,addresses}',
                            '{nium_region}',
                            '"HK"'::jsonb,
                            true
                        ),
                        '{nium_v5_fields,natureOfBusiness,operatingCountries}',
                        '["HK"]'::jsonb,
                        true
                    ),
                    '{nium_v5_fields,expectedAccountUsage,credit,topTransactionCountries}',
                    '["HK"]'::jsonb,
                    true
                ),
                '{nium_v5_fields,expectedAccountUsage,debit,topTransactionCountries}',
                '["HK"]'::jsonb,
                true
            ),
            '{nium_v5_fields,bankAccountDetails,bankCountry}',
            '"HK"'::jsonb,
            true
        ),
        '{nium_v5_fields,bankAccountDetails,currency}',
        '"HKD"'::jsonb,
        true
    ),
    updated_at = CURRENT_TIMESTAMP
WHERE id = 9
  AND user_id = 9;

UPDATE kyc_profiles
SET metadata = jsonb_set(
        metadata,
        '{nium_v5_fields,deviceDetails,ipCountryCode}',
        '"HK"'::jsonb,
        true
    ),
    updated_at = CURRENT_TIMESTAMP
WHERE id = 9
  AND user_id = 9;

UPDATE kyc_documents
SET issuing_country_code = 'HK',
    updated_at = CURRENT_TIMESTAMP
WHERE id = 18
  AND kyc_profile_id = 9
  AND metadata->>'nium_file_id' = '5dde122a-c143-4358-8b1c-ffaeb397c27c';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM kyc_profiles kp
        WHERE kp.id = 9
          AND kp.user_id = 9
          AND kp.registered_country_code = 'HK'
          AND kp.country_code = 'HK'
          AND kp.metadata->>'nium_region' = 'HK'
          AND kp.metadata#>>'{nium_v5_fields,bankAccountDetails,bankCountry}' = 'HK'
          AND kp.metadata#>>'{nium_v5_fields,bankAccountDetails,currency}' = 'HKD'
          AND kp.metadata#>>'{nium_v5_fields,deviceDetails,ipCountryCode}' = 'HK'
    ) THEN
        RAISE EXCEPTION 'Fixture V4 HK profile update did not reach the expected state.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM kyc_documents kd
        WHERE kd.id = 18
          AND kd.kyc_profile_id = 9
          AND kd.issuing_country_code = 'HK'
          AND kd.metadata->>'nium_file_id' = '5dde122a-c143-4358-8b1c-ffaeb397c27c'
          AND kd.metadata->>'nium_file_state' = 'AVAILABLE'
    ) THEN
        RAISE EXCEPTION 'Fixture V4 business document update did not reach the expected state.';
    END IF;

    IF (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4)
        IS DISTINCT FROM (SELECT account_4_before FROM fixture_v4_hk_guard) THEN
        RAISE EXCEPTION 'Protected Account 4 changed.';
    END IF;

    IF (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7)
        IS DISTINCT FROM (SELECT account_7_before FROM fixture_v4_hk_guard) THEN
        RAISE EXCEPTION 'Provider Account 7 changed.';
    END IF;

    IF (SELECT count(*) FROM api_request_logs)
        <> (SELECT request_count_before FROM fixture_v4_hk_guard) THEN
        RAISE EXCEPTION 'Provider request log count changed.';
    END IF;

    IF (
        SELECT count(*)
        FROM api_request_logs arl
        JOIN user_provider_accounts upa ON upa.user_id = arl.user_id
        WHERE upa.id = 7
          AND arl.request_method = 'POST'
          AND arl.operation = 'customer_create'
    ) <> (SELECT customer_post_count_before FROM fixture_v4_hk_guard) THEN
        RAISE EXCEPTION 'Fixture V4 customer POST count changed.';
    END IF;
END
$$;

SELECT
    kp.id,
    kp.user_id,
    kp.registered_country_code,
    kp.country_code,
    kp.city,
    kp.state,
    kp.postal_code,
    kp.metadata->>'nium_region' AS nium_region,
    kp.metadata->'nium_v5_fields' AS nium_v5_fields
FROM kyc_profiles kp
WHERE kp.id = 9
  AND kp.user_id = 9;

-- Rollback plan:
-- 1. Before execution, export the diagnostic rows for profiles(user_id=9),
--    kyc_profiles(id=9), and kyc_documents(id=18).
-- 2. Restore those exact exported column values in one transaction.
-- 3. Re-run fixture_v4_hk_diagnostic.sql and verify Account 4, Account 7,
--    request count 56, customer POST count 3, and all three File IDs.
COMMIT;
