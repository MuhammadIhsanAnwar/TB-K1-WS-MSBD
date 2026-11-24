<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                GRANT USAGE ON SCHEMA public TO service_role;
                GRANT SELECT
                    ON TABLE public.ringkasan_rating_tempat, public.ringkasan_suka_tempat
                    TO service_role;
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->statement(
                'REVOKE SELECT ON TABLE public.ringkasan_rating_tempat, public.ringkasan_suka_tempat FROM service_role'
            );
        }
    }
};
