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

        if (! $schema->hasTable('pesan_pengguna')) {
            $schema->create('pesan_pengguna', function (Blueprint $table) {
                $table->id();
                $table->foreignId('id_pengguna')->nullable()->constrained('users')->nullOnDelete();
                $table->string('nama_pengguna', 80);
                $table->string('email_pengguna', 120);
                $table->string('kategori', 12);
                $table->string('tempat_slug', 120)->nullable();
                $table->string('nama_tempat', 120)->nullable();
                $table->foreignId('id_admin_tujuan')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('pesan');
                $table->string('status', 12)->default('baru');
                $table->timestamps();
                $table->index(['id_admin_tujuan', 'status', 'created_at']);
                $table->index(['kategori', 'status', 'created_at']);
            });
        }

        if (! $schema->hasTable('balasan_pesan')) {
            $schema->create('balasan_pesan', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pesan_id')->constrained('pesan_pengguna')->cascadeOnDelete();
                $table->foreignId('id_pengirim')->nullable()->constrained('users')->nullOnDelete();
                $table->string('peran_pengirim', 12);
                $table->text('isi');
                $table->string('status_email', 12)->default('menunggu');
                $table->timestamp('email_terkirim_pada')->nullable();
                $table->timestamps();
                $table->index(['pesan_id', 'created_at']);
            });
        }

        $connection = DB::connection($this->connection);
        $connection->statement(
            "ALTER TABLE pesan_pengguna ADD CONSTRAINT ck_pesan_pengguna_tujuan CHECK ((kategori = 'tempat' AND tempat_slug IS NOT NULL AND nama_tempat IS NOT NULL AND id_admin_tujuan IS NOT NULL) OR (kategori IN ('akun', 'lainnya') AND tempat_slug IS NULL AND nama_tempat IS NULL AND id_admin_tujuan IS NULL))"
        );
        $connection->statement(
            "ALTER TABLE pesan_pengguna ADD CONSTRAINT ck_pesan_pengguna_status CHECK (kategori IN ('tempat', 'akun', 'lainnya') AND status IN ('baru', 'dibalas'))"
        );
        $connection->statement(
            "ALTER TABLE balasan_pesan ADD CONSTRAINT ck_balasan_pesan_peran CHECK (peran_pengirim IN ('admin', 'superadmin') AND status_email IN ('menunggu', 'terkirim', 'gagal'))"
        );

        foreach (['pesan_pengguna', 'balasan_pesan'] as $table) {
            $connection->statement('ALTER TABLE '.$table.' ENABLE ROW LEVEL SECURITY');
            $policy = 'server can manage '.$table;
            $connection->statement('DROP POLICY IF EXISTS "'.$policy.'" ON '.$table);
            $connection->statement('CREATE POLICY "'.$policy.'" ON '.$table.' FOR ALL TO service_role USING (true) WITH CHECK (true)');
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('balasan_pesan');
        $schema->dropIfExists('pesan_pengguna');
    }
};
