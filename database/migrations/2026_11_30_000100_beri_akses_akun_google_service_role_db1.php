<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                GRANT USAGE ON SCHEMA public TO service_role;
                GRANT SELECT, INSERT, UPDATE, DELETE
                    ON TABLE public.users, public.email_verification_tokens
                    TO service_role;
                GRANT USAGE, SELECT
                    ON SEQUENCE public.users_id_seq, public.email_verification_tokens_id_seq
                    TO service_role;
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                REVOKE SELECT, INSERT, UPDATE, DELETE
                    ON TABLE public.users, public.email_verification_tokens
                    FROM service_role;
                REVOKE USAGE, SELECT
                    ON SEQUENCE public.users_id_seq, public.email_verification_tokens_id_seq
                    FROM service_role;
                REVOKE USAGE ON SCHEMA public FROM service_role;
                SQL);
        }
    }
};
