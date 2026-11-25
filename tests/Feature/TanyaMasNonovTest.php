<?php

namespace Tests\Feature;

use App\Services\TanyaMasNonovMemori;
use App\Services\TanyaMasNonovService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TanyaMasNonovTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.connections.katalog' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('katalog');

        Schema::connection('katalog')->create('users', function (Blueprint $table): void {
            $table->id();
        });
        $migration = require base_path('database/migrations/2026_11_25_000100_buat_pesan_tanya_mas_nonov.php');
        $migration->up();
        DB::connection('katalog')->table('users')->insert([['id' => 17], ['id' => 18]]);
    }

    protected function tearDown(): void
    {
        DB::purge('katalog');

        parent::tearDown();
    }

    public function test_guest_can_use_widget_chat_without_a_full_page_and_messages_are_stored_as_guest_data(): void
    {
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldReceive('jawab')
            ->once()
            ->with('Apa tempat wisata di Medan?', null, [], null)
            ->andReturn(['teks' => ['Ada banyak pilihan wisata di Medan.'], 'tempat' => []]);
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('tanya'));
        $this->get('/tanya')->assertNotFound();
        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), ['pesan' => 'Apa tempat wisata di Medan?'])
            ->assertOk();

        $pesan = DB::connection('katalog')->table('pesan_tanya_mas_nonov')->first();
        $this->assertNull($pesan->id_pengguna);
        $this->assertSame('tamu', $pesan->pengirim);
        $this->assertNotEmpty($pesan->pemilik_tamu);
        $this->assertNotNull($pesan->jawaban_terenkripsi);
    }

    public function test_guest_still_receives_an_answer_when_chat_memory_storage_is_unavailable(): void
    {
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldReceive('jawab')
            ->once()
            ->with('Tempat wisata dekat Bihun Bebek Asie Kumango', null, [], null)
            ->andReturn(['teks' => ['Ada beberapa tempat wisata di sekitar Bihun Bebek Asie Kumango.'], 'tempat' => []]);
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $memori = Mockery::mock(TanyaMasNonovMemori::class);
        $memori->shouldReceive('simpan')
            ->once()
            ->andThrow(new \PDOException('Database unavailable'));
        $this->app->instance(TanyaMasNonovMemori::class, $memori);

        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), ['pesan' => 'Tempat wisata dekat Bihun Bebek Asie Kumango'])
            ->assertOk()
            ->assertJsonPath('teks.0', 'Ada beberapa tempat wisata di sekitar Bihun Bebek Asie Kumango.')
            ->assertJsonPath('peringatan', 'Jawaban berhasil dimuat, tetapi riwayat percakapan tidak dapat disimpan saat ini.');
    }

    public function test_chat_messages_are_encrypted_hashed_and_used_as_the_users_private_memory(): void
    {
        $pertanyaanLama = 'Saya suka tempat bersejarah.';
        $pertanyaanBaru = 'Rekomendasikan tempat yang cocok.';
        app(TanyaMasNonovMemori::class)->simpan(18, null, 'Jangan bocorkan pertanyaan pengguna lain.');
        $jumlahPemanggilan = 0;
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldReceive('jawab')
            ->twice()
            ->withArgs(function (string $pertanyaan, ?array $pengguna, array $riwayat, ?array $lokasi) use (&$jumlahPemanggilan, $pertanyaanLama, $pertanyaanBaru): bool {
                $jumlahPemanggilan++;

                if ($jumlahPemanggilan === 1) {
                    return $pertanyaan === $pertanyaanLama && $riwayat === [] && $lokasi === null;
                }

                return $pertanyaan === $pertanyaanBaru
                    && $lokasi === null
                    && count($riwayat) === 1
                    && ($riwayat[0]['pertanyaan'] ?? null) === $pertanyaanLama
                    && ($riwayat[0]['jawaban'] ?? null) === 'Silakan kunjungi Istana Maimun.';
            })
            ->andReturn(['teks' => ['Silakan kunjungi Istana Maimun.'], 'tempat' => []]);
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $pengguna = [
            'id' => 17,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ];
        $this->withSession(['pengguna' => $pengguna, '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), ['pesan' => $pertanyaanLama])
            ->assertOk();
        $this->withSession([
            'pengguna' => $pengguna,
            '_token' => 'test-csrf',
            'tanya_mas_nonov' => ['pemilik' => 'sesi-baru', 'giliran' => []],
        ])->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), ['pesan' => $pertanyaanBaru])
            ->assertOk();

        $pesan = DB::connection('katalog')->table('pesan_tanya_mas_nonov')
            ->where('id_pengguna', 17)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $pesan);
        $this->assertNotSame($pertanyaanLama, $pesan[0]->pesan_terenkripsi);
        $this->assertStringNotContainsString($pertanyaanLama, $pesan[0]->pesan_terenkripsi);
        $this->assertSame(
            hash_hmac('sha256', $pertanyaanLama, (string) config('app.key')),
            $pesan[0]->pesan_hash,
        );
        $this->assertSame($pertanyaanLama, Crypt::decryptString($pesan[0]->pesan_terenkripsi));
    }

    public function test_valid_chat_location_is_passed_to_the_assistant_but_not_saved_as_chat_memory(): void
    {
        $lokasi = ['lat' => 3.5613, 'lng' => 98.6568];
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldReceive('jawab')
            ->once()
            ->with('Tempat makan terdekat', null, [], $lokasi)
            ->andReturn(['teks' => ['Soto Kesawan berjarak sekitar 1 km.'], 'tempat' => []]);
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), [
                'pesan' => 'Tempat makan terdekat',
                'lokasi' => $lokasi,
            ])
            ->assertOk()
            ->assertJsonPath('teks.0', 'Soto Kesawan berjarak sekitar 1 km.');

        $pesan = DB::connection('katalog')->table('pesan_tanya_mas_nonov')->first();
        $this->assertSame('Tempat makan terdekat', Crypt::decryptString($pesan->pesan_terenkripsi));
        $this->assertStringNotContainsString('3.5613', Crypt::decryptString($pesan->pesan_terenkripsi));
        $this->assertStringNotContainsString('98.6568', Crypt::decryptString($pesan->pesan_terenkripsi));
    }

    public function test_invalid_chat_location_is_rejected(): void
    {
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldNotReceive('jawab');
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->postJson(route('tanya.jawab'), [
                'pesan' => 'Tempat makan terdekat',
                'lokasi' => ['lat' => 100, 'lng' => 98.6568],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lokasi.lat']);
    }
}
