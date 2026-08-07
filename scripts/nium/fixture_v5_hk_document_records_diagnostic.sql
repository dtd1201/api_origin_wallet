\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;

SELECT
    kp.id AS kyc_profile_id,
    kp.user_id,
    kp.applicant_type,
    kp.metadata->>'fixture_marker' AS fixture_marker,
    kp.metadata->>'nium_region' AS nium_region
FROM kyc_profiles kp
WHERE kp.id = 9
  AND kp.user_id = 9;

SELECT
    krp.id AS related_person_id,
    krp.relationship_type,
    CASE
        WHEN lower(krp.relationship_type) IN (
            'applicant',
            'authorized_representative',
            'authorised_representative'
        ) THEN 'applicant_authorized_person_identity'
        ELSE 'beneficial_owner_stakeholder_identity'
    END AS resolved_hk_document_role
FROM kyc_related_persons krp
WHERE krp.kyc_profile_id = 9
ORDER BY krp.id;

SELECT
    kd.id,
    kd.type,
    kd.status,
    kd.file_hash,
    kd.metadata->>'fixture_marker' AS fixture_marker,
    kd.metadata->>'logical_role' AS logical_role,
    kd.metadata ? 'nium_file_id' AS has_nium_file_id
FROM kyc_documents kd
WHERE kd.kyc_profile_id = 9
ORDER BY kd.id;

SELECT count(*) AS api_request_log_count FROM api_request_logs;

SELECT count(*) AS customer_create_post_count
FROM api_request_logs
WHERE operation = 'customer_create'
  AND request_method = 'POST';

ROLLBACK;
