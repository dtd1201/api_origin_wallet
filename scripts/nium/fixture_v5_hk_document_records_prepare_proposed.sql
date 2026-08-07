\set ON_ERROR_STOP on

-- Do not execute until the diagnostic and reviewed artifact placement have been approved.
\set expected_fixture_marker '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__'

BEGIN;

LOCK TABLE kyc_profiles, kyc_related_persons, kyc_documents, user_provider_accounts, api_request_logs
    IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE fixture_v5_hk_document_guard AS
SELECT
    :'expected_fixture_marker'::text AS expected_fixture_marker,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4) AS account_4_before,
    (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7) AS account_7_before,
    (
        SELECT jsonb_agg(to_jsonb(kd) ORDER BY kd.id)
        FROM kyc_documents kd
        WHERE kd.id IN (18, 19, 20)
    ) AS historical_documents_before,
    (SELECT count(*) FROM api_request_logs) AS request_count_before,
    (
        SELECT count(*)
        FROM api_request_logs
        WHERE operation = 'customer_create'
          AND request_method = 'POST'
    ) AS customer_post_count_before;

CREATE TEMP TABLE fixture_v5_hk_roles AS
SELECT
    count(*) FILTER (
        WHERE lower(krp.relationship_type) IN (
            'applicant',
            'authorized_representative',
            'authorised_representative'
        )
    ) AS applicant_count,
    min(krp.id) FILTER (
        WHERE lower(krp.relationship_type) IN (
            'applicant',
            'authorized_representative',
            'authorised_representative'
        )
    ) AS applicant_related_person_id,
    count(*) FILTER (
        WHERE lower(krp.relationship_type) NOT IN (
            'applicant',
            'authorized_representative',
            'authorised_representative'
        )
    ) AS stakeholder_count,
    min(krp.id) FILTER (
        WHERE lower(krp.relationship_type) NOT IN (
            'applicant',
            'authorized_representative',
            'authorised_representative'
        )
    ) AS stakeholder_related_person_id
FROM kyc_related_persons krp
WHERE krp.kyc_profile_id = 9;

DO $$
BEGIN
    IF (SELECT expected_fixture_marker FROM fixture_v5_hk_document_guard)
        = '__REPLACE_WITH_EXACT_FIXTURE_V4_MARKER__' THEN
        RAISE EXCEPTION 'Replace expected_fixture_marker with the reviewed diagnostic value.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM kyc_profiles kp
        WHERE kp.id = 9
          AND kp.user_id = 9
          AND kp.applicant_type = 'business'
          AND (SELECT expected_fixture_marker FROM fixture_v5_hk_document_guard) = ANY (ARRAY[
              kp.metadata->>'fixture_marker',
              kp.metadata->>'fixture_version',
              kp.metadata->>'synthetic_fixture'
          ])
    ) THEN
        RAISE EXCEPTION 'Fixture V4 identity or exact marker mismatch.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM user_provider_accounts upa
        WHERE upa.id = 7
          AND upa.user_id = 9
          AND upa.external_customer_id IS NULL
          AND upa.external_account_id IS NULL
    ) THEN
        RAISE EXCEPTION 'Provider Account 7 is not the expected unresolved fixture account.';
    END IF;

    IF (SELECT request_count_before FROM fixture_v5_hk_document_guard) <> 56
       OR (SELECT customer_post_count_before FROM fixture_v5_hk_document_guard) <> 3 THEN
        RAISE EXCEPTION 'Locked provider request counts changed.';
    END IF;

    IF (SELECT applicant_count FROM fixture_v5_hk_roles) <> 1
       OR (SELECT stakeholder_count FROM fixture_v5_hk_roles) <> 1 THEN
        RAISE EXCEPTION 'Exactly one applicant and one stakeholder related-person record are required.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM kyc_documents kd
        WHERE kd.kyc_profile_id = 9
          AND kd.metadata->>'fixture_marker' = 'nium-corporate-synthetic-v5-hk'
    ) THEN
        RAISE EXCEPTION 'HK V5 document records already exist.';
    END IF;
END
$$;

INSERT INTO kyc_documents (
    kyc_profile_id, kyc_related_person_id, type, status, file_url,
    storage_disk, file_path, original_name, mime_type, file_size,
    file_hash, issuing_country_code, metadata, created_at, updated_at
)
VALUES
(
    9, NULL, 'business_registration', 'approved',
    'private://kyc/9/nium-v5-hk/hk-corporate-sandbox-test.pdf',
    'kyc_private', 'kyc/9/nium-v5-hk/hk-corporate-sandbox-test.pdf',
    'hk-corporate-sandbox-test.pdf', 'application/pdf', 1075,
    '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a', 'HK',
    jsonb_build_object(
        'fixture_marker', 'nium-corporate-synthetic-v5-hk',
        'logical_role', 'corporate_registration',
        'expected_sha256', '68e006d3f97f33b24e5ced1a07aaa4ff970270acba6fcee05e7658814a57822a',
        'synthetic_test', true
    ), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
),
(
    9, (SELECT applicant_related_person_id FROM fixture_v5_hk_roles), 'passport_front', 'approved',
    'private://kyc/9/nium-v5-hk/hk-applicant-sandbox-test.pdf',
    'kyc_private', 'kyc/9/nium-v5-hk/hk-applicant-sandbox-test.pdf',
    'hk-applicant-sandbox-test.pdf', 'application/pdf', 1037,
    '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
    (SELECT COALESCE(krp.nationality_country_code, krp.country_code) FROM kyc_related_persons krp WHERE krp.id = (SELECT applicant_related_person_id FROM fixture_v5_hk_roles)),
    jsonb_build_object(
        'fixture_marker', 'nium-corporate-synthetic-v5-hk',
        'logical_role', 'applicant_authorized_person_identity',
        'expected_sha256', '310f7f2716bf6945d4591e459e13449df2f41e044487ff4c3f36b97228f397a2',
        'synthetic_test', true
    ), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
),
(
    9, (SELECT stakeholder_related_person_id FROM fixture_v5_hk_roles), 'passport_front', 'approved',
    'private://kyc/9/nium-v5-hk/hk-stakeholder-sandbox-test.pdf',
    'kyc_private', 'kyc/9/nium-v5-hk/hk-stakeholder-sandbox-test.pdf',
    'hk-stakeholder-sandbox-test.pdf', 'application/pdf', 1050,
    'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
    (SELECT COALESCE(krp.nationality_country_code, krp.country_code) FROM kyc_related_persons krp WHERE krp.id = (SELECT stakeholder_related_person_id FROM fixture_v5_hk_roles)),
    jsonb_build_object(
        'fixture_marker', 'nium-corporate-synthetic-v5-hk',
        'logical_role', 'beneficial_owner_stakeholder_identity',
        'expected_sha256', 'd4b5d6945d047f8a892c7cb93694e37c2dd6efb98b8f63e86b18394f0c2ad953',
        'synthetic_test', true
    ), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF (SELECT count(*) FROM kyc_documents kd WHERE kd.kyc_profile_id = 9 AND kd.metadata->>'fixture_marker' = 'nium-corporate-synthetic-v5-hk') <> 3 THEN
        RAISE EXCEPTION 'Exactly three HK V5 document records were not created.';
    END IF;

    IF EXISTS (
        SELECT 1 FROM kyc_documents kd
        WHERE kd.kyc_profile_id = 9
          AND kd.metadata->>'fixture_marker' = 'nium-corporate-synthetic-v5-hk'
          AND kd.metadata ? 'nium_file_id'
    ) THEN
        RAISE EXCEPTION 'New HK document records must not contain Nium File IDs.';
    END IF;

    IF (SELECT jsonb_agg(to_jsonb(kd) ORDER BY kd.id) FROM kyc_documents kd WHERE kd.id IN (18, 19, 20))
        IS DISTINCT FROM (SELECT historical_documents_before FROM fixture_v5_hk_document_guard) THEN
        RAISE EXCEPTION 'Historical documents 18, 19, or 20 changed.';
    END IF;

    IF (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 4)
        IS DISTINCT FROM (SELECT account_4_before FROM fixture_v5_hk_document_guard)
       OR (SELECT to_jsonb(upa) FROM user_provider_accounts upa WHERE upa.id = 7)
        IS DISTINCT FROM (SELECT account_7_before FROM fixture_v5_hk_document_guard) THEN
        RAISE EXCEPTION 'Protected provider accounts changed.';
    END IF;

    IF (SELECT count(*) FROM api_request_logs) <> (SELECT request_count_before FROM fixture_v5_hk_document_guard)
       OR (SELECT count(*) FROM api_request_logs WHERE operation = 'customer_create' AND request_method = 'POST')
        <> (SELECT customer_post_count_before FROM fixture_v5_hk_document_guard) THEN
        RAISE EXCEPTION 'Provider request counts changed.';
    END IF;
END
$$;

SELECT
    kd.id AS new_document_id,
    kd.metadata->>'logical_role' AS logical_role,
    kd.file_hash AS sha256,
    kd.metadata ? 'nium_file_id' AS has_nium_file_id
FROM kyc_documents kd
WHERE kd.kyc_profile_id = 9
  AND kd.metadata->>'fixture_marker' = 'nium-corporate-synthetic-v5-hk'
ORDER BY kd.id;

-- Rollback before any upload: delete only rows with this marker and no nium_file_id,
-- then remove only the three reviewed files from kyc_private after verifying their hashes.
COMMIT;
