<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RatingTempatMigrationTest extends TestCase
{
    public function test_it_creates_rating_storage_and_backfills_public_review_ratings(): void
    {
        config(['database.connections.transaksi' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('transaksi');

        Schema::connection('transaksi')->create('ulasan', function ($table): void {
            $table->bigIncrements('id_ulasan');
            $table->bigInteger('id_pengguna');
            $table->string('tempat_slug', 80);
            $table->smallInteger('bintang');
            $table->string('teks');
            $table->string('status', 10);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
        DB::connection('transaksi')->table('ulasan')->insert([
            [
                'id_pengguna' => 1,
                'tempat_slug' => 'taman-cadika',
                'bintang' => 4,
                'teks' => 'Ulasan pertama',
                'status' => 'tayang',
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'id_pengguna' => 2,
                'tempat_slug' => 'taman-cadika',
                'bintang' => 5,
                'teks' => 'Ulasan menunggu',
                'status' => 'menunggu',
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $migration = require database_path('migrations/2026_11_26_000200_buat_rating_tempat.php');
        $migration->up();

        $this->assertTrue(Schema::connection('transaksi')->hasTable('rating_tempat'));
        $ringkasan = DB::connection('transaksi')->select(
            'SELECT jumlah_rating, rata_rata_bintang FROM ringkasan_rating_tempat WHERE tempat_slug = ?',
            ['taman-cadika'],
        );
        $this->assertSame(1, (int) $ringkasan[0]->jumlah_rating);
        $this->assertSame(4.0, (float) $ringkasan[0]->rata_rata_bintang);

        $rating = DB::connection('transaksi')->table('rating_tempat');
        $rating->insert([
            'id_pengguna' => 3,
            'tempat_slug' => 'taman-cadika',
            'bintang' => 5,
        ]);

        $ringkasan = DB::connection('transaksi')->select(
            'SELECT jumlah_rating, rata_rata_bintang FROM ringkasan_rating_tempat WHERE tempat_slug = ?',
            ['taman-cadika'],
        );
        $this->assertSame(2, (int) $ringkasan[0]->jumlah_rating);
        $this->assertSame(4.5, (float) $ringkasan[0]->rata_rata_bintang);

        $this->expectException(QueryException::class);
        $rating->insert([
            'id_pengguna' => 1,
            'tempat_slug' => 'taman-cadika',
            'bintang' => 3,
        ]);
    }
}
