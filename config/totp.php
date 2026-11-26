<?php

return [
    'ownership_hold_hours' => 72,
    // Mandatory enforcement has no off switch. These gates authorize identity
    // proof policies only, never password-only access or factor disabling.
    'migration' => [
        'enabled' => false,
        'policy_reference' => null,
        'deadline' => null,
        'reset_hold_hours' => 72,
    ],
    'assisted' => [
        'enabled' => false,
        'policy_reference' => null,
        'approver_user_ids' => [],
        'required_approvals' => 2,
        // Approved independent Ed25519 operator identities for bootstrap.
        // Entries: operator-id => ['user_id' => staff ID, 'public_key' => base64].
        // No private keys or production identities belong in source control.
        'operator_public_keys' => [],
    ],
    'challenge_seconds' => 300,
    'enrollment_seconds' => 600,
    'ceremony_seconds' => 1200,
    'max_attempts' => 5,
    'proof_send_cooldown_seconds' => 120,
    'proof_send_limit' => 3,
    'rates' => [
        'credentials' => [5, 20],
        'verification' => [10, 30],
        'identity_send' => [3, 10],
        'assisted_intake' => [3, 10],
        'registration' => [5, 20],
    ],
];
