<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO service_role;
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.rencana, public.rencana_minat TO service_role;

            DO $grant_sequence$
            DECLARE
                v_sequence regclass;
            BEGIN
                v_sequence := pg_get_serial_sequence('public.rencana', 'id_rencana')::regclass;
                IF v_sequence IS NOT NULL THEN
                    EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE %s TO service_role', v_sequence);
                END IF;
            END;
            $grant_sequence$;

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
            REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE public.rencana, public.rencana_minat FROM service_role;

            DO $revoke_sequence$
            DECLARE
                v_sequence regclass;
            BEGIN
                v_sequence := pg_get_serial_sequence('public.rencana', 'id_rencana')::regclass;
                IF v_sequence IS NOT NULL THEN
                    EXECUTE format('REVOKE USAGE, SELECT ON SEQUENCE %s FROM service_role', v_sequence);
                END IF;
            END;
            $revoke_sequence$;

            NOTIFY pgrst, 'reload schema';
            SQL);
    }
};
