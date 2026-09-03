<?php

return [
    'roles' => [
        'super_admin' => ['*'],
        'compliance_officer' => ['users.view', 'kyc.view', 'kyc.approve', 'kyc.reject', 'aml.view', 'aml.review', 'rfi.view', 'rfi.manage', 'transactions.view', 'audit.view'],
        'finance_operator' => ['users.view', 'transfers.view', 'transfers.manage', 'transfers.approve', 'transfers.reject', 'transactions.view', 'transactions.manage', 'wallet.view', 'wallet.sync', 'wallet.manage', 'providers.view'],
        'operations_operator' => ['users.view', 'transfers.view', 'transactions.view', 'wallet.view', 'wallet.sync', 'providers.view', 'providers.sync', 'beneficiaries.view', 'bank_accounts.view'],
        'support_agent' => ['users.view', 'kyc.view', 'transfers.view', 'transactions.view', 'wallet.view', 'providers.view', 'beneficiaries.view', 'bank_accounts.view'],
        'auditor' => ['users.view', 'kyc.view', 'aml.view', 'rfi.view', 'transfers.view', 'transactions.view', 'wallet.view', 'providers.view', 'beneficiaries.view', 'bank_accounts.view', 'audit.view'],
    ],
    'permissions' => [
        'users.view', 'users.manage', 'kyc.view', 'kyc.approve', 'kyc.reject',
        'aml.view', 'aml.review', 'rfi.view', 'rfi.manage',
        'transfers.view', 'transfers.manage', 'transfers.approve', 'transfers.reject',
        'transactions.view', 'transactions.manage', 'wallet.view', 'wallet.sync', 'wallet.manage',
        'providers.view', 'providers.sync', 'providers.manage',
        'beneficiaries.view', 'beneficiaries.manage', 'bank_accounts.view', 'bank_accounts.manage',
        'audit.view',
    ],
];
