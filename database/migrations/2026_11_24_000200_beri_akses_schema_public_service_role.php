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
            $db->statement('GRANT USAGE ON SCHEMA public TO service_role');
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('REVOKE USAGE ON SCHEMA public FROM service_role');
        }
    }
};
