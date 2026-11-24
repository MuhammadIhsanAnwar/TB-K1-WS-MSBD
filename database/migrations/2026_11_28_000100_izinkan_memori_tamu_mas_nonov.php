<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $namaTabel = 'pesan_tanya_mas_nonov';

        if (! $schema->hasTable($namaTabel)) {
            return;
        }

        $sudahAdaPemilikTamu = $schema->hasColumn($namaTabel, 'pemilik_tamu');
        $schema->table($namaTabel, function (Blueprint $table) use ($sudahAdaPemilikTamu): void {
            if (! Schema::connection('katalog')->hasColumn('pesan_tanya_mas_nonov', 'pengirim')) {
                $table->string('pengirim', 12)->default('pengguna');
            }
            if (! $sudahAdaPemilikTamu) {
                $table->char('pemilik_tamu', 64)->nullable();
            }
            if (! Schema::connection('katalog')->hasColumn('pesan_tanya_mas_nonov', 'jawaban_terenkripsi')) {
                $table->longText('jawaban_terenkripsi')->nullable();
            }
        });

        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->statement('ALTER TABLE pesan_tanya_mas_nonov ALTER COLUMN id_pengguna DROP NOT NULL');
        }

        if (! $sudahAdaPemilikTamu) {
            $schema->table($namaTabel, function (Blueprint $table): void {
                $table->index(['pemilik_tamu', 'created_at'], 'pesan_nonov_tamu_created_at_index');
            });
        }
    }

    public function down(): void
    {
        // Riwayat tamu yang sudah tersimpan sengaja tidak dihapus saat rollback.
    }
};
