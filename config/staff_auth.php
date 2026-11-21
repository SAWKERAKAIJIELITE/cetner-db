<?php

return [

    // Prefix of the signed login payload (section 7.6 of code-documentation.md).
    'payload_prefix' => 'hr-staff-login-v1',

    // A login challenge is single use and lives this long.
    'challenge_ttl_seconds' => (int) env('STAFF_CHALLENGE_TTL_SECONDS', 60),

    // Unapproved devices waiting at once; registration is refused beyond it (spam guard).
    'max_pending_devices' => (int) env('STAFF_MAX_PENDING_DEVICES', 100),

];
