<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('users', 'ditangguhkan_pada')) {
            $schema->table('users', function (Blueprint $table) {
                $table->timestamp('ditangguhkan_pada')->nullable();
                $table->string('alasan_tangguh', 240)->nullable();
            });
        }

        $connection = $schema->getConnection();
        $connection->statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        $connection->statement('DROP POLICY IF EXISTS "server can delete users" ON users');
        $connection->statement('CREATE POLICY "server can delete users" ON users FOR DELETE TO anon, authenticated USING (true)');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->getConnection()->statement('DROP POLICY IF EXISTS "server can delete users" ON users');

        if ($schema->hasColumn('users', 'ditangguhkan_pada')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn(['ditangguhkan_pada', 'alasan_tangguh']);
            });
        }
    }
};
