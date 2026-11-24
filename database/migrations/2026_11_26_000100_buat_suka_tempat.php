<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        Schema::connection($this->connection)->create('suka_tempat', function (Blueprint $table) {
            $table->bigInteger('id_pengguna');
            $table->string('tempat_slug', 80);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['id_pengguna', 'tempat_slug']);
            $table->index('tempat_slug');
        });

        DB::connection($this->connection)->statement(
            'CREATE VIEW ringkasan_suka_tempat AS '
            .'SELECT tempat_slug, COUNT(*) AS jumlah_suka '
            .'FROM suka_tempat GROUP BY tempat_slug'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP VIEW IF EXISTS ringkasan_suka_tempat');
        Schema::connection($this->connection)->dropIfExists('suka_tempat');
    }
};
