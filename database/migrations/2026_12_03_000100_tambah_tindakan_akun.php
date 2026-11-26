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
        if (! $schema->hasColumn('users', 'dinonaktifkan_pada')) {
            $schema->table('users', function (Blueprint $table): void {
                $table->timestamp('dinonaktifkan_pada')->nullable();
                $table->timestamp('hapus_otomatis_pada')->nullable()->index();
            });
        }

        if (! $schema->hasTable('account_action_otps')) {
            $schema->create('account_action_otps', function (Blueprint $table): void {
                $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
                $table->string('tindakan', 20);
                $table->char('code_hash', 64);
                $table->timestamp('expires_at')->index();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamps();
            });
        }

        $connection = $schema->getConnection();
        $connection->statement('ALTER TABLE account_action_otps ENABLE ROW LEVEL SECURITY');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.account_action_otps TO service_role;
                NOTIFY pgrst, 'reload schema';
                SQL);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('account_action_otps');

        if ($schema->hasColumn('users', 'dinonaktifkan_pada')) {
            $schema->table('users', function (Blueprint $table): void {
                $table->dropColumn(['dinonaktifkan_pada', 'hapus_otomatis_pada']);
            });
        }
    }
};
