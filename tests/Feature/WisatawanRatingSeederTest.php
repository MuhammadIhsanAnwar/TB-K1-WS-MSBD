<?php

namespace Tests\Feature;

use App\Support\SupabaseApi;
use Database\Seeders\WisatawanRatingSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WisatawanRatingSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
    }

    public function test_rating_seeder_covers_a_target_place_and_sends_bounded_batches(): void
    {
        Http::fake(['https://transaksi.test/*' => Http::response([])]);

        $wisatawan = [];
        for ($id = 1; $id <= 10000; $id++) {
            $wisatawan[] = ['id' => $id];
        }

        $jumlah = WisatawanRatingSeeder::seedRatingTempat(
            new SupabaseApi('transaksi'),
            ['taman-cadika' => ['nama' => 'Taman Cadika Pramuka']],
            $wisatawan,
        );

        $this->assertSame(6032, $jumlah);
        Http::assertSentCount(13);

        $jumlahPerBatch = Http::recorded()
            ->map(static fn (array $terkirim) => count($terkirim[0]->data()))
            ->all();
        $this->assertSame(500, max($jumlahPerBatch));
        $this->assertSame(32, end($jumlahPerBatch));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rating_tempat?on_conflict=id_pengguna%2Ctempat_slug')
            && $request[0]['tempat_slug'] === 'taman-cadika'
            && $request[0]['bintang'] >= 1
            && $request[0]['bintang'] <= 5);
    }
}
