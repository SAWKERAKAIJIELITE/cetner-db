<?php

return [
    'device_key_invalid' => 'The public key is not valid: it must be an ECDSA P-256 key in PEM.',
    'device_pending_limit' => 'Too many devices are waiting for approval: ask the system administrator.',
    'device_not_pending' => 'This device is not waiting for approval.',
    'device_fingerprint_mismatch' => 'The fingerprint typed does not match the device.',
    'device_owner_inactive' => 'A device is not approved for an inactive user.',
    'device_owner_has_device' => 'This user has an approved device: revoke it first.',
    'device_already_revoked' => 'This device is revoked already.',
    'last_user_manager' => 'This change is refused: at least one active user must remain able to manage users.',
    'admin_role_fixed' => 'The system administrator\'s role always holds every permission and is not edited.',
    'read_only_role' => 'The general manager\'s role takes read-only permissions only: :permissions',
    'admin_role_by_admin_only' => 'Only the system administrator gives the system administrator\'s role.',
    'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    'already_inactive' => 'This account is inactive already.',
    'already_active' => 'This account is active already.',
    'created_inactive' => 'The account was created inactive.',
    'logged_out' => 'Logged out.',
];
