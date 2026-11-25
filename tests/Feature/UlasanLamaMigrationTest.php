<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UlasanLamaMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.transaksi' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('transaksi');

        Schema::connection('transaksi')->create('ulasan', function ($table): void {
            $table->bigIncrements('id_ulasan');
            $table->string('status', 10);
        });
        DB::connection('transaksi')->table('ulasan')->insert([
            ['status' => 'menunggu'],
            ['status' => 'tayang'],
            ['status' => 'ditolak'],
        ]);
    }

    public function test_it_publishes_legacy_waiting_reviews_without_changing_other_statuses(): void
    {
        $migration = require database_path('migrations/2026_12_03_000100_tayangkan_ulasan_lama.php');
        $migration->up();

        $this->assertSame(
            ['tayang', 'tayang', 'ditolak'],
            DB::connection('transaksi')->table('ulasan')->orderBy('id_ulasan')->pluck('status')->all(),
        );
    }
}
