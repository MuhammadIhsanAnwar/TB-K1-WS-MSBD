<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        DB::connection($this->connection)
            ->table('ulasan')
            ->where('status', 'menunggu')
            ->update(['status' => 'tayang']);
    }

    public function down(): void
    {
        // Published reviews stay published when rolling back application code.
    }
};
