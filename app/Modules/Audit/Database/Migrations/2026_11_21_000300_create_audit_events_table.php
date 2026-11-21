<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The audit log: one row per action, written in the action's transaction, never changed (D-017). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 100);
            $table->string('category', 20);
            $table->foreignUuid('actor_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->jsonb('context')->default('{}');
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        DB::statement("ALTER TABLE audit_events ADD COLUMN related_ids text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX audit_events_context_gin ON audit_events USING gin (context)');
        DB::statement('CREATE INDEX audit_events_related_ids_gin ON audit_events USING gin (related_ids)');

        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_event_format CHECK (event ~ '^[a-z_]+(\.[a-z_]+)+$'),
                ADD CONSTRAINT audit_events_category CHECK (category IN ('security', 'operations')),
                ADD CONSTRAINT audit_events_category_follows_event CHECK (
                    (category = 'security') = (event LIKE 'access.%' OR event LIKE 'device.%' OR event LIKE 'auth.%')
                )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_refuse_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only' USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_refuse_change();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION audit_events_refuse_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_refuse_change()');
    }
};
