<?php

return [
    /*
     * Add a reviewed combination here only after its production template payload has
     * been captured and the POST contract has been verified. Each entry must bind
     * environment, endpoint, method, request_schema_version, contract_version,
     * template_fingerprint, and required_field_shape_fingerprint. Runtime Admin
     * input cannot add to or alter this registry.
     */
    'corporate' => [
        /*
         * Production reflog and claimed_at timestamps place cases #3-#5 on f8ac4db.
         * Its adapter and the current adapter share SHA-256
         * a5b8d752f9a953515495fc6f0704ae59aba37a0dd7e72ce91ebedec70b0c31f5.
         * The schema and contract versions are internal Origin Wallet review labels,
         * not Nium-issued versions. Historical .env values and the precise submitted
         * JSON bodies were not independently archived.
         */
        [
            'environment' => 'production',
            'endpoint' => '/api/v1/client/{clientHashId}/corporate/rfi',
            'method' => 'POST',
            'request_schema_version' => 'legacy-corporate-rfi-v1-hk-operator-reviewed-20261016',
            'contract_version' => 'legacy-corporate-rfi-v1-hk-operator-reviewed-20261016',
            'template_fingerprint' => 'cec6ae9c8b0fe4dc77867126695a05708002289f9cce70ad0458581758d1d964',
            'required_field_shape_fingerprint' => '4c3e3dbb248a6d9f1ae0771060169601e90a3b1c20dc79a8d6a45333e0dc9fce',
        ],
    ],
];
