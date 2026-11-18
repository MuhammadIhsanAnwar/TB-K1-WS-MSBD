<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB 1 (akun dan konfigurasi website): pengaturan situs yang diubah super admin dari panel,
 * satu baris per kunci dengan nilai JSON. Kunci pertama: sampul_beranda (foto hero beranda).
 */
return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('pengaturan_situs')) {
            $schema->create('pengaturan_situs', function (Blueprint $table) {
                $table->string('kunci', 60)->primary();
                $table->jsonb('nilai');
                $table->foreignId('diubah_oleh')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $connection = DB::connection($this->connection);
        $connection->statement('ALTER TABLE pengaturan_situs ENABLE ROW LEVEL SECURITY');
        $connection->statement('DROP POLICY IF EXISTS "server can manage pengaturan_situs" ON pengaturan_situs');
        $connection->statement('CREATE POLICY "server can manage pengaturan_situs" ON pengaturan_situs FOR ALL TO service_role USING (true) WITH CHECK (true)');
    }
