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
            GRANT USAGE ON SCHEMA public TO service_role;
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE
                public.admin_tempat,
                public.password_reset_tokens,
                public.pesan_pengguna,
                public.balasan_pesan,
                public.pengaturan_situs
                TO service_role;

            DO $grant_sequences$
            DECLARE
                v_sequence regclass;
            BEGIN
                FOREACH v_sequence IN ARRAY ARRAY[
                    pg_get_serial_sequence('public.pesan_pengguna', 'id')::regclass,
                    pg_get_serial_sequence('public.balasan_pesan', 'id')::regclass
                ] LOOP
                    IF v_sequence IS NOT NULL THEN
                        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO service_role', v_sequence);
                    END IF;
                END LOOP;
            END;
            $grant_sequences$;

            NOTIFY pgrst, 'reload schema';
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->unprepared(<<<'SQL'
            REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE
                public.admin_tempat,
                public.password_reset_tokens,
                public.pesan_pengguna,
                public.balasan_pesan,
                public.pengaturan_situs
                FROM service_role;

            DO $revoke_sequences$
            DECLARE
                v_sequence regclass;
            BEGIN
                FOREACH v_sequence IN ARRAY ARRAY[
                    pg_get_serial_sequence('public.pesan_pengguna', 'id')::regclass,
                    pg_get_serial_sequence('public.balasan_pesan', 'id')::regclass
                ] LOOP
                    IF v_sequence IS NOT NULL THEN
                        EXECUTE format('REVOKE USAGE, SELECT ON SEQUENCE %s FROM service_role', v_sequence);
                    END IF;
                END LOOP;
            END;
            $revoke_sequences$;

            NOTIFY pgrst, 'reload schema';
            SQL);
    }
};
