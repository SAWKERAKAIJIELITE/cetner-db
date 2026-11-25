<?php

return [
    // Days, months and "today" of the business are those of this zone; storage stays UTC (D-004, D-012).
    'timezone' => env('HR_TIMEZONE', 'Asia/Damascus'),

    // The key of the identity fingerprint (D-038). Its own key, so that a new APP_KEY does not invalidate the
    // fingerprints; set it once at installation and keep it with the backups. Defaults to APP_KEY.
    'identity_hash_key' => env('HR_IDENTITY_HASH_KEY', env('APP_KEY')),

    // The records (Step 4): the most changes one submission carries (a unit's month of attendance). The absence
    // rate that flags a month is a branch setting (work_settings.high_absence_rate).
    'records_batch_limit' => (int) env('HR_RECORDS_BATCH_LIMIT', 500),
];
