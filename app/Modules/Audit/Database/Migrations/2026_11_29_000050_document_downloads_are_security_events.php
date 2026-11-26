<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reading an employee's document is an access (D-049): `documents.downloaded` is in the security category, as
 * AuditTrail::SECURITY_EVENTS says. No such event was recorded before, so every row already satisfies the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                DROP CONSTRAINT audit_events_category_follows_event,
                ADD CONSTRAINT audit_events_category_follows_event CHECK (
                    (category = 'security') = (event LIKE 'access.%' OR event LIKE 'device.%' OR event LIKE 'auth.%' OR event = 'documents.downloaded')
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                DROP CONSTRAINT audit_events_category_follows_event,
                ADD CONSTRAINT audit_events_category_follows_event CHECK (
                    (category = 'security') = (event LIKE 'access.%' OR event LIKE 'device.%' OR event LIKE 'auth.%')
                )
            SQL);
    }
};
