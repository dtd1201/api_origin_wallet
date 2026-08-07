\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;

SELECT
    kp.id AS kyc_profile_id,
    kp.user_id,
    kp.applicant_type,
    kp.registered_country_code,
    kp.country_code,
    kp.city,
    kp.state,
    kp.postal_code,
    kp.metadata->>'fixture_marker' AS fixture_marker,
    kp.metadata->>'fixture_version' AS fixture_version,
    kp.metadata->>'synthetic_fixture' AS synthetic_fixture,
    kp.metadata->>'nium_region' AS nium_region,
    kp.metadata->'nium_v5_fields' AS nium_v5_fields
FROM kyc_profiles kp
WHERE kp.id = 9
  AND kp.user_id = 9;

SELECT
    upa.id AS provider_account_id,
    upa.user_id,
    ip.code AS provider_code,
    upa.external_customer_id,
    upa.external_account_id,
    upa.external_reference,
    upa.status,
    upa.reconciliation_error,
    upa.metadata
FROM user_provider_accounts upa
JOIN integration_providers ip ON ip.id = upa.provider_id
WHERE upa.id IN (4, 7)
ORDER BY upa.id;

SELECT
    kd.id AS kyc_document_id,
    kd.kyc_profile_id,
    kd.kyc_related_person_id,
    kd.type,
    kd.status,
    kd.issuing_country_code,
    kd.storage_disk,
    kd.file_path IS NOT NULL AS has_local_storage_path,
    kd.original_name IS NOT NULL AS has_original_filename,
    lower(substring(kd.original_name FROM '\.([^.]+)$')) AS original_extension,
    kd.mime_type,
    kd.file_size,
    kd.file_hash AS safe_content_hash_if_retained,
    CASE
        WHEN kd.kyc_related_person_id IS NULL THEN 'documents[*].fileIds'
        WHEN krp.relationship_type = 'authorized_person' THEN 'applicant.documents[*].fileIds'
        ELSE 'stakeholders.individual[*].documents[*].fileIds'
    END AS customer_payload_reference,
    kd.metadata->>'nium_file_id' AS nium_file_id,
    kd.metadata->>'nium_file_state' AS nium_file_state,
    kd.metadata ? 'sha256' AS has_metadata_sha256,
    kd.metadata ? 'content_sha256' AS has_metadata_content_sha256
FROM kyc_documents kd
LEFT JOIN kyc_related_persons krp ON krp.id = kd.kyc_related_person_id
WHERE kd.id IN (18, 19, 20)
ORDER BY kd.id;

SELECT
    count(*) AS provider_api_request_log_count
FROM api_request_logs;

SELECT
    count(*) AS fixture_v4_customer_post_count
FROM api_request_logs arl
JOIN user_provider_accounts upa ON upa.user_id = arl.user_id
WHERE upa.id = 7
  AND arl.request_method = 'POST'
  AND arl.operation = 'customer_create';

ROLLBACK;
