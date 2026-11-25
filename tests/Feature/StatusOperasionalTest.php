<?php

namespace Tests\Feature;

use App\Support\Katalog;
use App\Support\StatusOperasional;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatusOperasionalTest extends TestCase
{
    private array $pengaturan = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));
        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
        ]);
        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), '/pengaturan_situs')) {
                return Http::response([], 404);
            }

            if ($request->method() === 'GET') {
                return Http::response(array_values($this->pengaturan));
            }

            $baris = $request->data();
            $this->pengaturan[$baris['kunci']] = [
                'kunci' => $baris['kunci'],
                'nilai' => $baris['nilai'],
            ];

            return Http::response([]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_admin_override_can_open_or_close_a_place_outside_its_schedule(): void
    {
        $tempat = [
            'slug' => 'medan-zoo',
            'jam' => 'Setiap hari, 09.00-17.00',
            'jam_menit' => [540, 1020],
        ];
        $pagi = Carbon::parse('2026-11-23 10:00:00', 'Asia/Jakarta');
        $malam = Carbon::parse('2026-11-23 18:00:00', 'Asia/Jakarta');

        $this->assertTrue(Katalog::bukaSekarang($tempat, $pagi));

        StatusOperasional::simpan('medan-zoo', 'tutup', 7);
        $this->assertFalse(Katalog::bukaSekarang($tempat, $pagi));

        StatusOperasional::simpan('medan-zoo', 'buka', 7);
        $this->assertTrue(Katalog::bukaSekarang($tempat, $malam));
        $this->assertFalse(Katalog::statusMenurutJadwal($tempat, $malam));

        StatusOperasional::simpan('medan-zoo', 'jadwal', 7);
        $this->assertFalse(Katalog::bukaSekarang($tempat, $malam));
        $this->assertSame(
            '2026-11-23 17:00:00',
            Katalog::batasOperasional($tempat, '2026-11-23')->format('Y-m-d H:i:s'),
        );

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/pengaturan_situs?')
            && str_starts_with($request['kunci'], 'status_operasional_')
            && strlen($request['kunci']) <= 60);
    }

    public function test_overnight_opening_hours_expire_tickets_at_the_next_day_closing_time(): void
    {
        $tempat = [
            'jam' => 'Setiap hari, 22.00-02.00',
            'jam_menit' => [1320, 120],
        ];

        $this->assertSame(
            '2026-11-24 02:00:00',
            Katalog::batasOperasional($tempat, '2026-11-23')->format('Y-m-d H:i:s'),
        );
    }
}
