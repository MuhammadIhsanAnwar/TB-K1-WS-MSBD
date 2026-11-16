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
        if (! $schema->hasTable('admin_tempat')) {
            $schema->create('admin_tempat', function (Blueprint $table) {
                $table->foreignId('id_admin')->constrained('users')->cascadeOnDelete();
                $table->string('tempat_slug', 120)->unique();
                $table->timestamps();
                $table->primary(['id_admin', 'tempat_slug']);
            });
        }

        $connection = DB::connection($this->connection);
        $connection->statement('ALTER TABLE admin_tempat ENABLE ROW LEVEL SECURITY');
        $connection->statement(
            'CREATE POLICY "server can manage admin places" ON admin_tempat FOR ALL TO anon, authenticated USING (true) WITH CHECK (true)'
        );
        $connection->table('users')
            ->where('peran', 'admin')
            ->whereNotNull('tempat_slug')
            ->orderBy('id')
            ->get()
            ->each(function (object $user) use ($connection): void {
                $connection->table('admin_tempat')->insertOrIgnore([
                    'id_admin' => $user->id,
                    'tempat_slug' => $user->tempat_slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('admin_tempat');
    }
};
