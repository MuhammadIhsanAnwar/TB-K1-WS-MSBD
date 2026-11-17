<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('peer_devices')) {
            $schema->create('peer_devices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('label', 80);
                $table->timestampTz('last_seen_at')->nullable()->index();
                $table->timestampTz('revoked_at')->nullable()->index();
                $table->timestampsTz();
                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (! $schema->hasTable('peer_sessions')) {
            $schema->create('peer_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('requester_peer_id')->constrained('peer_devices')->cascadeOnDelete();
                $table->foreignUuid('target_peer_id')->constrained('peer_devices')->cascadeOnDelete();
                $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 12)->default('pending');
                $table->timestampTz('expires_at')->index();
                $table->timestampsTz();
                $table->index(['target_peer_id', 'status', 'expires_at']);
                $table->index(['requester_peer_id', 'status', 'expires_at']);
            });
        }

        if (! $schema->hasTable('peer_blocks')) {
            $schema->create('peer_blocks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
                $table->timestampTz('created_at')->useCurrent();
                $table->unique(['blocker_user_id', 'blocked_user_id']);
                $table->index(['blocked_user_id', 'blocker_user_id']);
            });
        }

        $connection = $schema->getConnection();
        $this->assertColumnTypes($connection, 'peer_devices', [
            'id' => 'uuid',
            'user_id' => 'int8',
            'label' => 'varchar',
            'last_seen_at' => 'timestamptz',
            'revoked_at' => 'timestamptz',
            'created_at' => 'timestamptz',
            'updated_at' => 'timestamptz',
        ]);
        $this->assertColumnTypes($connection, 'peer_sessions', [
            'id' => 'uuid',
            'requester_peer_id' => 'uuid',
            'target_peer_id' => 'uuid',
            'requester_user_id' => 'int8',
            'target_user_id' => 'int8',
            'status' => 'varchar',
            'expires_at' => 'timestamptz',
            'created_at' => 'timestamptz',
            'updated_at' => 'timestamptz',
        ]);
        $this->assertColumnTypes($connection, 'peer_blocks', [
            'id' => 'uuid',
            'blocker_user_id' => 'int8',
            'blocked_user_id' => 'int8',
            'created_at' => 'timestamptz',
        ]);
        $hasSupabaseAuth = $connection->selectOne("SELECT to_regprocedure('auth.jwt()') AS function_name")?->function_name;

        if ($hasSupabaseAuth) {
            foreach (['peer_devices', 'peer_sessions', 'peer_blocks'] as $table) {
                $connection->statement('ALTER TABLE '.$table.' ENABLE ROW LEVEL SECURITY');
            }
            $connection->statement('GRANT SELECT ON peer_devices, peer_sessions, peer_blocks TO authenticated');

            $connection->statement('DROP POLICY IF EXISTS "peer devices belong to own account" ON peer_devices');
            $connection->statement(<<<'SQL'
            CREATE POLICY "peer devices belong to own account" ON peer_devices
            FOR SELECT TO authenticated
            USING (user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint))
            SQL);
            $connection->statement('DROP POLICY IF EXISTS "peer sessions belong to participant" ON peer_sessions');
            $connection->statement(<<<'SQL'
            CREATE POLICY "peer sessions belong to participant" ON peer_sessions
            FOR SELECT TO authenticated
            USING (
                requester_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                OR target_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
            )
            SQL);
            $connection->statement('DROP POLICY IF EXISTS "peer blocks visible to participant" ON peer_blocks');
            $connection->statement(<<<'SQL'
            CREATE POLICY "peer blocks visible to participant" ON peer_blocks
            FOR SELECT TO authenticated
            USING (
                blocker_user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                OR blocked_user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
            )
            SQL);

            if ($connection->selectOne("SELECT to_regclass('realtime.messages') AS table_name")?->table_name) {
                $this->createRealtimePolicies($connection);
            }
        }
    }

    public function down(): void
    {
        $connection = Schema::connection($this->connection)->getConnection();
        if ($connection->selectOne("SELECT to_regclass('realtime.messages') AS table_name")?->table_name) {
            foreach ([
                'p2p presence listen',
                'p2p presence publish',
                'p2p inbox listen',
                'p2p inbox publish',
                'p2p session listen',
                'p2p session publish',
            ] as $policy) {
                $connection->statement('DROP POLICY IF EXISTS "'.$policy.'" ON realtime.messages');
            }
        }

        Schema::connection($this->connection)->dropIfExists('peer_blocks');
        Schema::connection($this->connection)->dropIfExists('peer_sessions');
        Schema::connection($this->connection)->dropIfExists('peer_devices');
    }

    private function createRealtimePolicies(Connection $connection): void
    {
        foreach ([
            'p2p presence listen',
            'p2p presence publish',
            'p2p inbox listen',
            'p2p inbox publish',
            'p2p session listen',
            'p2p session publish',
        ] as $policy) {
            $connection->statement('DROP POLICY IF EXISTS "'.$policy.'" ON realtime.messages');
        }

        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p presence listen" ON realtime.messages
            FOR SELECT TO authenticated
            USING (
                extension = 'presence'
                AND realtime.topic() = 'p2p:presence'
                AND EXISTS (
                    SELECT 1 FROM public.peer_devices d
                    WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                      AND d.revoked_at IS NULL
                )
            )
            SQL);
        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p presence publish" ON realtime.messages
            FOR INSERT TO authenticated
            WITH CHECK (
                extension = 'presence'
                AND realtime.topic() = 'p2p:presence'
                AND EXISTS (
                    SELECT 1 FROM public.peer_devices d
                    WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                      AND d.revoked_at IS NULL
                )
            )
            SQL);
        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p inbox listen" ON realtime.messages
            FOR SELECT TO authenticated
            USING (
                extension = 'broadcast'
                AND realtime.topic() = 'p2p:inbox:' || (auth.jwt() -> 'app_metadata' ->> 'peer_id')
                AND EXISTS (
                    SELECT 1 FROM public.peer_devices d
                    WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                      AND d.revoked_at IS NULL
                )
            )
            SQL);
        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p inbox publish" ON realtime.messages
            FOR INSERT TO authenticated
            WITH CHECK (
                extension = 'broadcast'
                AND EXISTS (
                    SELECT 1 FROM public.peer_devices d
                    WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                      AND d.revoked_at IS NULL
                )
                AND (
                    EXISTS (
                        SELECT 1 FROM public.peer_sessions s
                        WHERE s.status = 'pending'
                          AND s.expires_at > now()
                          AND s.requester_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                          AND realtime.topic() = 'p2p:inbox:' || s.target_peer_id::text
                    )
                    OR EXISTS (
                        SELECT 1 FROM public.peer_sessions s
                        WHERE s.status IN ('accepted', 'rejected')
                          AND s.expires_at > now()
                          AND s.target_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                          AND realtime.topic() = 'p2p:inbox:' || s.requester_peer_id::text
                    )
                )
            )
            SQL);
        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p session listen" ON realtime.messages
            FOR SELECT TO authenticated
            USING (
                extension = 'broadcast'
                AND realtime.topic() ~ '^p2p:session:[0-9a-f-]{36}$'
                AND EXISTS (
                    SELECT 1 FROM public.peer_sessions s
                    WHERE s.id::text = substring(realtime.topic() FROM 13)
                      AND s.status = 'accepted'
                      AND s.expires_at > now()
                      AND (
                        s.requester_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                        OR s.target_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      )
                      AND EXISTS (
                        SELECT 1 FROM public.peer_devices d
                        WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                          AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                          AND d.revoked_at IS NULL
                      )
                      AND NOT EXISTS (
                        SELECT 1 FROM public.peer_blocks pb
                        WHERE (pb.blocker_user_id = s.requester_user_id AND pb.blocked_user_id = s.target_user_id)
                           OR (pb.blocker_user_id = s.target_user_id AND pb.blocked_user_id = s.requester_user_id)
                      )
                )
            )
            SQL);
        $connection->statement(<<<'SQL'
            CREATE POLICY "p2p session publish" ON realtime.messages
            FOR INSERT TO authenticated
            WITH CHECK (
                extension = 'broadcast'
                AND realtime.topic() = 'p2p:session:' || (realtime.messages.payload ->> 'session_id')
                AND EXISTS (
                    SELECT 1 FROM public.peer_sessions s
                    WHERE s.id = (realtime.messages.payload ->> 'session_id')::uuid
                      AND s.status = 'accepted'
                      AND s.expires_at > now()
                      AND (
                        s.requester_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                        OR s.target_peer_id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                      )
                      AND EXISTS (
                        SELECT 1 FROM public.peer_devices d
                        WHERE d.id = ((auth.jwt() -> 'app_metadata' ->> 'peer_id')::uuid)
                          AND d.user_id = ((auth.jwt() -> 'app_metadata' ->> 'user_id')::bigint)
                          AND d.revoked_at IS NULL
                      )
                      AND NOT EXISTS (
                        SELECT 1 FROM public.peer_blocks pb
                        WHERE (pb.blocker_user_id = s.requester_user_id AND pb.blocked_user_id = s.target_user_id)
                           OR (pb.blocker_user_id = s.target_user_id AND pb.blocked_user_id = s.requester_user_id)
                      )
                )
            )
            SQL);
    }

    private function assertColumnTypes(Connection $connection, string $table, array $expected): void
    {
        $columns = $connection->select(
            'SELECT column_name, udt_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
            [$table],
        );
        $actual = [];
        foreach ($columns as $column) {
            $actual[$column->column_name] = $column->udt_name;
        }

        foreach ($expected as $name => $type) {
            if (($actual[$name] ?? null) !== $type) {
                throw new RuntimeException('Skema '.$table.'.'.$name.' tidak sesuai. Migrasi P2P dihentikan tanpa mengubah tabel yang tidak kompatibel.');
            }
        }
    }
};
