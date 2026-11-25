<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM PUBLIC, anon, authenticated;
            REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM PUBLIC, anon, authenticated;
            REVOKE EXECUTE ON ALL ROUTINES IN SCHEMA public FROM PUBLIC, anon, authenticated;
            REVOKE CREATE ON SCHEMA public FROM PUBLIC, anon, authenticated;

            GRANT USAGE ON SCHEMA public TO service_role;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO service_role;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO service_role;
            GRANT EXECUTE ON ALL ROUTINES IN SCHEMA public TO service_role;

            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM PUBLIC, anon, authenticated;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM PUBLIC, anon, authenticated;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC, anon, authenticated;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO service_role;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO service_role;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO service_role;

            DO $$
            DECLARE
                v_policy record;
                v_table text;
            BEGIN
                FOR v_policy IN
                    SELECT schemaname, tablename, policyname
                    FROM pg_policies
                    WHERE schemaname = 'public'
                      AND roles::text[] && ARRAY['anon', 'authenticated', 'public']::text[]
                LOOP
                    EXECUTE format('DROP POLICY IF EXISTS %I ON %I.%I',
                        v_policy.policyname, v_policy.schemaname, v_policy.tablename);
                END LOOP;

                FOREACH v_table IN ARRAY ARRAY[
                    'users',
                    'password_reset_tokens',
                    'email_verification_tokens',
                    'admin_tempat',
                    'pesan_pengguna',
                    'balasan_pesan',
                    'pengaturan_situs',
                    'pesan_tanya_mas_nonov'
                ]
                LOOP
                    IF to_regclass(format('public.%I', v_table)) IS NOT NULL THEN
                        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', v_table);
                    END IF;
                END LOOP;
            END
            $$;

            NOTIFY pgrst, 'reload schema';
            SQL);
    }

    public function down(): void
    {
        // Keep public roles restricted; restoring the previous grants would expose account data.
    }
};
