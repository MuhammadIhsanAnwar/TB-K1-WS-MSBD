<?php

namespace Tests\Unit;

use App\Services\PostgreSQLService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PostgreSQLServiceTest extends TestCase
{
    public function test_order_count_uses_authenticated_user_id_binding(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT COUNT(*) AS jumlah FROM pesanan WHERE id_pengguna = ?', [42])
            ->andReturn([(object) ['jumlah' => '12']]);
        DB::shouldReceive('connection')->once()->with('transaksi')->andReturn($connection);

        $this->assertSame(12, app(PostgreSQLService::class)->jumlahPesanan(42));
    }

    public function test_profile_lookup_binds_authenticated_user_id(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->with('SELECT id, name, email, peran FROM users WHERE id = ? LIMIT 1', [42])
            ->andReturn([(object) ['id' => 42, 'name' => 'Pengguna', 'email' => 'user@example.test', 'peran' => 'wisatawan']]);
        DB::shouldReceive('connection')->once()->with('katalog')->andReturn($connection);

        $this->assertSame(
            ['id' => 42, 'name' => 'Pengguna', 'email' => 'user@example.test', 'peran' => 'wisatawan'],
            app(PostgreSQLService::class)->profilPengguna(42),
        );
    }
}
