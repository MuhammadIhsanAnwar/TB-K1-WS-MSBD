<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    private const REALTIME_POLICIES = [
        'p2p presence listen',
        'p2p presence publish',
        'p2p inbox listen',
        'p2p inbox publish',
        'p2p session listen',
        'p2p session publish',
    ];

    public function up(): void
    {
        $connection = Schema::connection($this->connection)->getConnection();

        if ($connection->selectOne("SELECT to_regclass('realtime.messages') AS table_name")?->table_name) {
            foreach (self::REALTIME_POLICIES as $policy) {
                $connection->statement('DROP POLICY IF EXISTS "'.$policy.'" ON realtime.messages');
            }
        }

        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('peer_blocks');
        $schema->dropIfExists('peer_sessions');
        $schema->dropIfExists('peer_devices');
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Penghapusan data peer-to-peer tidak dapat dibatalkan. Pulihkan backup sebelum mencoba mengembalikan fitur.'
        );
    }
};
