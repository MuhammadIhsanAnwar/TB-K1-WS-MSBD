<?php

namespace Tests\Unit;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class KatalogRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();
        Cache::store('array')->flush();

        parent::tearDown();
    }

    private function panggilFungsiPrivate(
        string $fungsi,
        array $argumen
    ): mixed {
        $method = new ReflectionMethod(Katalog::class, $fungsi);

        return $method->invokeArgs(null, $argumen);
    }

    private function gunakanTempat(array $tempat): void
    {
        $property = new \ReflectionProperty(Katalog::class, 'entities');
        $property->setValue(null, $tempat);
    }

    private function buatTempat(
        string $slug,
        float $lat,
        float $lng,
        string $kategori = 'sejarah',
        int $singgah = 15,
    ): array {
        return [
            'slug' => $slug,
            'jenis' => 'tempat',
            'nama' => $slug,
            'kategori' => $kategori,
            'lat' => $lat,
            'lng' => $lng,
            'singgah' => $singgah,
            'jam' => '',
            'jam_menit' => null,
            'tiket' => [],
            'akses_gratis' => true,
        ];
    }

    public function test_valhalla_returns_walking_distance_and_duration(): void
    {
        config([
            'services.routing.valhalla_url' => 'https://valhalla.test',
            'services.routing.timeout' => 15,
        ]);

        Http::fake([
            '*valhalla.test/route' => Http::response([
                'routes' => [[
                    'distance' => 939.149,
                    'duration' => 670.613,
                ]],
            ], 200),
        ]);

        $hasil = $this->panggilFungsiPrivate('ambilRuteApi', [
            ['lat' => 3.5897, 'lng' => 98.6738],
            ['lat' => 3.5952, 'lng' => 98.6722],
            'jalan',
        ]);

        $this->assertSame(939, $hasil['meter']);
        $this->assertSame(11, $hasil['menit']);

        Http::assertSent(fn (Request $request) =>
            $request->url() === 'https://valhalla.test/route'
            && $request['costing'] === 'pedestrian'
        );
    }

    public function test_osrm_returns_vehicle_distance_and_duration(): void
    {
        config([
            'services.routing.osrm_url' => 'https://osrm.test',
            'services.routing.timeout' => 15,
        ]);

        Http::fake([
            '*osrm.test/route/v1/driving/*' => Http::response([
                'routes' => [[
                    'distance' => 1600.4,
                    'duration' => 116.2,
                ]],
            ], 200),
        ]);

        $hasil = $this->panggilFungsiPrivate('ambilRuteApi', [
            ['lat' => 3.5897, 'lng' => 98.6738],
            ['lat' => 3.5952, 'lng' => 98.6722],
            'motor',
        ]);

        $this->assertSame(1600, $hasil['meter']);
        $this->assertSame(2, $hasil['menit']);

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), 'osrm.test/route/v1/driving/')
        );
    }

    public function test_failed_routing_api_returns_null(): void
    {
        config([
            'services.routing.osrm_url' => 'https://osrm.test',
        ]);

        Http::fake([
            '*osrm.test/*' => Http::response([], 500),
        ]);

        $hasil = $this->panggilFungsiPrivate('ambilRuteApi', [
            ['lat' => 3.5897, 'lng' => 98.6738],
            ['lat' => 3.5952, 'lng' => 98.6722],
            'mobil',
        ]);

        $this->assertNull($hasil);
    }

    public function test_motor_route_uses_actual_route_distance_without_adding_transport_cost(): void
    {
        config([
            'services.routing.osrm_url' => 'https://osrm.test',
        ]);

        Http::fake([
            '*osrm.test/*' => Http::response([
                'routes' => [[
                    'distance' => 1600.4,
                    'duration' => 116.2,
                ]],
            ], 200),
        ]);

        $hasil = $this->panggilFungsiPrivate('perpindahanDenganRute', [
            ['lat' => 3.5897, 'lng' => 98.6738],
            ['lat' => 3.5952, 'lng' => 98.6722],
            ['motor'],
        ]);

        $this->assertSame('Motor', $hasil['moda']);
        $this->assertSame(1600, $hasil['meter']);
        $this->assertSame(2, $hasil['menit']);
        $this->assertSame(0, $hasil['biaya']);
    }

    public function test_selected_destinations_are_all_included_even_when_their_category_is_not_selected(): void
    {
        $this->gunakanTempat([
            'awal' => $this->buatTempat('awal', 3.5900, 98.6700),
            'museum-sumut' => $this->buatTempat('museum-sumut', 3.5904, 98.6700, 'museum'),
            'taman-cadika' => $this->buatTempat('taman-cadika', 3.5910, 98.6700, 'ruang-publik'),
            'tempat-sejarah-lain' => $this->buatTempat('tempat-sejarah-lain', 3.5915, 98.6700),
        ]);
        config(['services.routing.osrm_url' => 'https://osrm.test']);
        Http::fake(['*' => Http::response([], 500)]);

        $hasil = Katalog::susunRute(
            ['sejarah'],
            2,
            ['motor'],
            'awal',
            tujuan: ['museum-sumut', 'taman-cadika'],
        );

        $this->assertSame(
            ['museum-sumut', 'taman-cadika'],
            array_column(array_column($hasil['singgah'], 'tempat'), 'slug'),
        );
    }

    public function test_automatic_recommendations_still_follow_selected_interests(): void
    {
        $this->gunakanTempat([
            'awal' => $this->buatTempat('awal', 3.5900, 98.6700),
            'museum-sumut' => $this->buatTempat('museum-sumut', 3.5904, 98.6700, 'museum'),
            'tempat-sejarah' => $this->buatTempat('tempat-sejarah', 3.5910, 98.6700),
        ]);
        config(['services.routing.valhalla_url' => 'https://valhalla.test']);
        Http::fake(['*' => Http::response([], 500)]);

        $hasil = Katalog::susunRute(['sejarah'], 2, ['jalan'], 'awal');
        $slug = array_column(array_column($hasil['singgah'], 'tempat'), 'slug');

        $this->assertContains('tempat-sejarah', $slug);
        $this->assertNotContains('museum-sumut', $slug);
    }

    public function test_manual_route_does_not_silently_drop_a_selected_stop_when_duration_is_too_short(): void
    {
        $this->gunakanTempat([
            'awal' => $this->buatTempat('awal', 3.5900, 98.6700),
            'tujuan-satu' => $this->buatTempat('tujuan-satu', 3.5904, 98.6700, 'museum', 90),
            'tujuan-dua' => $this->buatTempat('tujuan-dua', 3.5910, 98.6700, 'museum', 90),
        ]);
        config(['services.routing.osrm_url' => 'https://osrm.test']);
        Http::fake(['*' => Http::response([], 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Semua tempat tujuan pilihan harus muat');

        Katalog::susunRute(
            ['museum'],
            2,
            ['motor'],
            'awal',
            tujuan: ['tujuan-satu', 'tujuan-dua'],
        );
    }

    public function test_motor_and_car_are_available_as_separate_travel_modes(): void
    {
        $awal = ['lat' => 3.5900, 'lng' => 98.6700];
        $dekat = ['lat' => 3.5901, 'lng' => 98.6700];
        $jauh = ['lat' => 3.6000, 'lng' => 98.6700];

        $this->assertSame('Jalan kaki', Katalog::perpindahan($awal, $dekat, ['jalan', 'motor'])['moda']);
        $this->assertSame('Motor', Katalog::perpindahan($awal, $jauh, ['motor'])['moda']);
        $this->assertSame('Mobil', Katalog::perpindahan($awal, $jauh, ['mobil'])['moda']);
    }
}
