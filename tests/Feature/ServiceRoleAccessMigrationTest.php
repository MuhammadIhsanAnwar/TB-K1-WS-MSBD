<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ServiceRoleAccessMigrationTest extends TestCase
{
    public function test_db1_rest_migration_grants_service_role_access_to_runtime_tables(): void
    {
        $sql = $this->runMigration('2026_12_01_000100_beri_akses_rest_service_role_db1.php', 'katalog');

        foreach ([
            'public.admin_tempat',
            'public.password_reset_tokens',
            'public.pesan_pengguna',
            'public.balasan_pesan',
            'public.pengaturan_situs',
            'GRANT USAGE, SELECT ON SEQUENCE',
            'TO service_role',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }
    }

    public function test_db2_analytics_migration_grants_service_role_access_to_runtime_objects(): void
    {
        $sql = $this->runMigration('2026_12_01_000200_beri_akses_analitik_service_role_db2.php', 'transaksi');

        foreach ([
            'public.log_pencarian',
            'public.v_celah_konten',
            'GRANT USAGE, SELECT ON SEQUENCE',
            'TO service_role',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }
    }

    private function runMigration(string $file, string $connectionName): string
    {
        $sql = '';
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('unprepared')->once()->withArgs(function (string $query) use (&$sql): bool {
            $sql = $query;

            return true;
        })->andReturn(true);

        DB::shouldReceive('connection')->once()->with($connectionName)->andReturn($connection);
        $migration = require base_path('database/migrations/'.$file);
        $migration->up();

        return $sql;
    }
}
