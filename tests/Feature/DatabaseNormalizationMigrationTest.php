<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseNormalizationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['katalog', 'transaksi'] as $connection) {
            config(["database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            DB::purge($connection);
        }
    }

    public function test_it_normalizes_user_place_reviews_ratings_and_travel_modes(): void
    {
        Schema::connection('katalog')->create('users', function ($table): void {
            $table->id();
            $table->string('peran');
            $table->string('tempat_slug', 120)->nullable()->unique();
        });
        Schema::connection('katalog')->create('admin_tempat', function ($table): void {
            $table->unsignedBigInteger('id_admin');
            $table->string('tempat_slug', 120)->unique();
            $table->timestamps();
            $table->primary(['id_admin', 'tempat_slug']);
        });
        DB::connection('katalog')->table('users')->insert([
            'id' => 1,
            'peran' => 'admin',
            'tempat_slug' => 'taman-cadika',
        ]);

        Schema::connection('transaksi')->create('rating_tempat', function ($table): void {
            $table->bigInteger('id_pengguna');
            $table->string('tempat_slug', 80);
            $table->smallInteger('bintang');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['id_pengguna', 'tempat_slug']);
        });
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
        Schema::connection('transaksi')->create('ringkasan_ulasan', function ($table): void {
            $table->string('tempat_slug', 80)->primary();
            $table->integer('jumlah_ulasan');
            $table->decimal('rata_rata_bintang', 3, 2)->nullable();
            $table->timestampTz('diperbarui_pada')->useCurrent();
        });
        Schema::connection('transaksi')->create('rencana', function ($table): void {
            $table->bigIncrements('id_rencana');
            $table->boolean('moda_jalan')->default(true);
            $table->boolean('moda_becak')->default(false);
        });
        Schema::connection('transaksi')->create('rencana_minat', function ($table): void {
            $table->bigInteger('id_rencana');
            $table->string('kode_kategori', 20);
            $table->primary(['id_rencana', 'kode_kategori']);
        });

        $now = now()->toIso8601String();
        DB::connection('transaksi')->table('ulasan')->insert([
            ['id_pengguna' => 1, 'tempat_slug' => 'taman-cadika', 'bintang' => 4, 'teks' => 'ulasan tayang', 'status' => 'tayang', 'created_at' => $now, 'updated_at' => $now],
            ['id_pengguna' => 2, 'tempat_slug' => 'taman-cadika', 'bintang' => 2, 'teks' => 'ulasan menunggu', 'status' => 'menunggu', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::connection('transaksi')->table('rencana')->insert([
            ['id_rencana' => 9, 'moda_jalan' => true, 'moda_becak' => true],
        ]);

        $migration = require database_path('migrations/2026_11_27_000100_normalisasi_db1_db2.php');
        $migration->up();

        $this->assertFalse(Schema::connection('katalog')->hasColumn('users', 'tempat_slug'));
        $this->assertSame('taman-cadika', DB::connection('katalog')->table('admin_tempat')->value('tempat_slug'));
        $this->assertFalse(Schema::connection('transaksi')->hasColumn('ulasan', 'bintang'));
        $this->assertSame(2, DB::connection('transaksi')->table('rating_tempat')->count());
        $ringkasan = DB::connection('transaksi')->table('ringkasan_ulasan')->first();
        $this->assertSame(1, (int) $ringkasan->jumlah_ulasan);
        $this->assertSame(4.0, (float) $ringkasan->rata_rata_bintang);
        $this->assertFalse(Schema::connection('transaksi')->hasColumn('rencana', 'moda_jalan'));
        $this->assertSame(
            ['becak', 'jalan'],
            DB::connection('transaksi')->table('rencana_moda')->orderBy('moda')->pluck('moda')->all(),
        );
    }

    public function test_it_drops_tables_for_removed_features(): void
    {
        foreach (['penukaran', 'voucher', 'riwayat_poin', 'checkin', 'menu_usaha', 'usaha_mitra'] as $table) {
            Schema::connection('transaksi')->create($table, function ($definition): void {
                $definition->id();
            });
        }

        $migration = require database_path('migrations/2026_11_27_000200_hapus_tabel_fitur_lama.php');
        $migration->up();

        foreach (['penukaran', 'voucher', 'riwayat_poin', 'checkin', 'menu_usaha', 'usaha_mitra'] as $table) {
            $this->assertFalse(Schema::connection('transaksi')->hasTable($table));
        }
    }
}
