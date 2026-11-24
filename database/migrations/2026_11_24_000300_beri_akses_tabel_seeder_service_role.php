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
                GRANT SELECT, INSERT, UPDATE, DELETE
                    ON TABLE public.rating_tempat, public.ulasan, public.suka_tempat
                    TO service_role;
                GRANT USAGE, SELECT ON SEQUENCE public.ulasan_id_ulasan_seq TO service_role;
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                REVOKE SELECT, INSERT, UPDATE, DELETE
                    ON TABLE public.rating_tempat, public.ulasan, public.suka_tempat
                    FROM service_role;
                REVOKE USAGE, SELECT ON SEQUENCE public.ulasan_id_ulasan_seq FROM service_role;
                SQL);
        }
    }
};
