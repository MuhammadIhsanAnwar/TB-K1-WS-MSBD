<?php

namespace Tests\Feature;

use App\Services\TanyaMasNonovMemori;
use App\Services\TanyaMasNonovService;
use Illuminate\Database\Schema\Blueprint;
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

    public function test_guest_must_log_in_before_opening_or_sending_a_chat_message(): void
    {
        $this->get(route('tanya'))
            ->assertRedirect(route('masuk', ['kembali' => '/tanya']));
        $this->postJson(route('tanya.jawab'), ['pesan' => 'Apa tempat wisata di Medan?'])
            ->assertRedirect(route('masuk', ['kembali' => '/tanya']));
    }

    public function test_chat_messages_are_encrypted_hashed_and_used_as_the_users_private_memory(): void
    {
        $pertanyaanLama = 'Saya suka tempat bersejarah.';
        $pertanyaanBaru = 'Rekomendasikan tempat yang cocok.';
        app(TanyaMasNonovMemori::class)->simpan(18, 'Jangan bocorkan pertanyaan pengguna lain.');
        $jumlahPemanggilan = 0;
        $asisten = Mockery::mock(TanyaMasNonovService::class);
        $asisten->shouldReceive('jawab')
            ->twice()
            ->withArgs(function (string $pertanyaan, ?array $pengguna, array $riwayat) use (&$jumlahPemanggilan, $pertanyaanLama, $pertanyaanBaru): bool {
                $jumlahPemanggilan++;

                if ($jumlahPemanggilan === 1) {
                    return $pertanyaan === $pertanyaanLama && $riwayat === [];
                }

                return $pertanyaan === $pertanyaanBaru
                    && count($riwayat) === 1
                    && ($riwayat[0]['pertanyaan'] ?? null) === $pertanyaanLama
                    && ! array_key_exists('jawaban', $riwayat[0]);
            })
            ->andReturn(['teks' => ['Silakan kunjungi Istana Maimun.'], 'tempat' => []]);
        $this->app->instance(TanyaMasNonovService::class, $asisten);

        $pengguna = [
            'id' => 17,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ];
        $this->withSession(['pengguna' => $pengguna])
            ->postJson(route('tanya.jawab'), ['pesan' => $pertanyaanLama])
            ->assertOk();
        $this->withSession([
            'pengguna' => $pengguna,
            'tanya_mas_nonov' => ['pemilik' => 'sesi-baru', 'giliran' => []],
        ])->postJson(route('tanya.jawab'), ['pesan' => $pertanyaanBaru])
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
}
