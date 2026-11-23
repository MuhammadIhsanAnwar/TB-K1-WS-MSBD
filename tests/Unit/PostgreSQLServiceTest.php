<?php

namespace Tests\Unit;

use App\Services\PostgreSQLService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PostgreSQLServiceTest extends TestCase
{
    public function test_review_summary_looks_up_a_place_with_a_bound_slug(): void
    {
        $query = 'SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
             FROM ringkasan_ulasan
             WHERE tempat_slug = ?
             LIMIT 1';
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->with($query, ['istana-maimun'])
            ->andReturn([(object) ['tempat_slug' => 'istana-maimun', 'jumlah_ulasan' => 9, 'rata_rata_bintang' => '4.7']]);
        DB::shouldReceive('connection')->once()->with('transaksi')->andReturn($connection);

        $this->assertSame(
            ['tempat_slug' => 'istana-maimun', 'jumlah_ulasan' => 9, 'rata_rata_bintang' => '4.7'],
            app(PostgreSQLService::class)->ringkasanUlasanTempat('istana-maimun'),
        );
    }

    public function test_review_ranking_uses_summary_table_and_bounded_limit(): void
    {
        $query = 'SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
                 FROM ringkasan_ulasan
                 WHERE jumlah_ulasan > 0
                 ORDER BY jumlah_ulasan DESC, rata_rata_bintang DESC NULLS LAST
                 LIMIT ?';
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->with($query, [5])
            ->andReturn([(object) ['tempat_slug' => 'istana-maimun', 'jumlah_ulasan' => 17, 'rata_rata_bintang' => '4.8']]);
        DB::shouldReceive('connection')->once()->with('transaksi')->andReturn($connection);

        $this->assertSame(
            [['tempat_slug' => 'istana-maimun', 'jumlah_ulasan' => 17, 'rata_rata_bintang' => '4.8']],
            app(PostgreSQLService::class)->peringkatUlasan(5),
        );
    }

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
