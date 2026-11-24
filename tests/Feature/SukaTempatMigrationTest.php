<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SukaTempatMigrationTest extends TestCase
{
    public function test_it_creates_one_suka_per_user_and_place(): void
    {
        config(['database.connections.transaksi' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('transaksi');

        $migration = require database_path('migrations/2026_11_26_000100_buat_suka_tempat.php');
        $migration->up();

        $this->assertTrue(Schema::connection('transaksi')->hasTable('suka_tempat'));

        $suka = DB::connection('transaksi')->table('suka_tempat');
        $suka->insert(['id_pengguna' => 1, 'tempat_slug' => 'taman-cadika']);
        $suka->insert(['id_pengguna' => 2, 'tempat_slug' => 'taman-cadika']);
        $ringkasan = DB::connection('transaksi')->select(
            'SELECT jumlah_suka FROM ringkasan_suka_tempat WHERE tempat_slug = ?',
            ['taman-cadika'],
        );
        $this->assertSame(2, (int) $ringkasan[0]->jumlah_suka);

        $this->expectException(QueryException::class);
        $suka->insert(['id_pengguna' => 1, 'tempat_slug' => 'taman-cadika']);
    }
}
