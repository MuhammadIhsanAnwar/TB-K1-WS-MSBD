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
        $schema->create('pesan_tanya_mas_nonov', function (Blueprint $table) {
            $table->id();
            // null menandakan pesan berasal dari tamu; identitas tamu dipisahkan dengan token sesi ter-hash.
            $table->foreignId('id_pengguna')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pengirim', 12)->default('pengguna');
            $table->char('pemilik_tamu', 64)->nullable();
            $table->longText('pesan_terenkripsi');
            $table->longText('jawaban_terenkripsi')->nullable();
            $table->char('pesan_hash', 64);
            $table->timestamps();
            $table->index(['id_pengguna', 'created_at']);
            $table->index(['pemilik_tamu', 'created_at']);
        });

        $connection = DB::connection($this->connection);
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE pesan_tanya_mas_nonov ENABLE ROW LEVEL SECURITY');
            $connection->statement('CREATE POLICY "server can manage chat messages" ON pesan_tanya_mas_nonov FOR ALL TO service_role USING (true) WITH CHECK (true)');
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('pesan_tanya_mas_nonov');
    }
};
