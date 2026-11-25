<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class RencanaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.routing.osrm_url' => 'https://osrm.test',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Katalog::forgetCache();
        (new ReflectionProperty(Katalog::class, 'entities'))->setValue(null, [
            'awal' => $this->tempat('awal', 3.5900, 98.6700),
            'tujuan-satu' => $this->tempat('tujuan-satu', 3.5904, 98.6700),
            'tujuan-dua' => $this->tempat('tujuan-dua', 3.5910, 98.6700),
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/rest/v1/rencana?')
                && str_contains($request->url(), 'id_pengguna=eq.7')) {
                return Http::response([[
                    'id_rencana' => 9,
                    'kode' => 'mdn-abcd',
                    'id_pengguna' => 7,
                    'judul' => 'Rute museum',
                    'durasi_jam' => 4,
                    'tempat_mulai_slug' => 'awal',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }
            if (str_contains($request->url(), '/rest/v1/rencana_minat?')) {
                return Http::response([['id_rencana' => 9, 'kode_kategori' => 'sejarah']]);
            }
            if (str_contains($request->url(), '/rest/v1/rencana_moda?')) {
                return Http::response([['id_rencana' => 9, 'moda' => 'motor']]);
            }
            if (str_contains($request->url(), '/rest/v1/rencana_tujuan?')) {
                return Http::response([['id_rencana' => 9, 'urutan' => 1, 'tempat_slug' => 'tujuan-satu']]);
            }

            return str_contains($request->url(), 'osrm.test')
                ? Http::response([], 500)
                : Http::response([]);
        });
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_route_page_accepts_multiple_manual_destinations_and_three_transport_modes(): void
    {
        $query = http_build_query([
            'minat' => ['sejarah'],
            'durasi' => 4,
            'moda' => ['jalan', 'motor', 'mobil'],
            'mulai' => 'awal',
            'tujuan' => ['tujuan-satu', 'tujuan-dua'],
            'tujuan_dikirim' => 1,
        ]);

        $response = $this->get('/rencana?'.$query);
        $response
            ->assertOk()
            ->assertSee('Susun Sendiri')
            ->assertSee('name="mode" value="manual"', false)
            ->assertSee('name="tujuan[]"', false)
            ->assertSee('tujuan-satu')
            ->assertSee('tujuan-dua')
            ->assertSee('2 tempat')
            ->assertSee('data-peta-mode="rute"', false)
            ->assertSee('data-rute=', false);

        preg_match('/data-rute="([^"]+)"/', $response->getContent(), $matches);
        $rute = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
        $this->assertSame('awal', $rute[0]['nama'] ?? null);
        $this->assertTrue($rute[0]['awal'] ?? false);
        $this->assertCount(3, $rute);
    }

    public function test_route_page_first_shows_only_the_two_planning_modes(): void
    {
        $this->get('/rencana')
            ->assertOk()
            ->assertSee('Susun Otomatis')
            ->assertSee('Susun Sendiri')
            ->assertSee('Pilih salah satu cara penyusunan rute')
            ->assertDontSee('data-rencana-form', false)
            ->assertDontSee('name="minat[]"', false)
            ->assertDontSee('name="tujuan[]"', false);
    }

    public function test_automatic_mode_shows_preferences_but_not_manual_destinations(): void
    {
        $query = http_build_query([
            'mode' => 'otomatis',
            'minat' => ['sejarah'],
            'durasi' => 4,
            'moda' => ['motor'],
            'mulai' => 'awal',
        ]);

        $this->get('/rencana?'.$query)
            ->assertOk()
            ->assertSee('Susun Otomatis')
            ->assertSee('name="minat[]"', false)
            ->assertSee('name="durasi"', false)
            ->assertSee('name="moda[]"', false)
            ->assertDontSee('name="tujuan[]"', false);
    }

    public function test_manual_mode_needs_only_a_start_and_selected_destinations(): void
    {
        $query = http_build_query([
            'mode' => 'manual',
            'mulai' => 'awal',
            'tujuan' => ['tujuan-satu', 'tujuan-dua'],
        ]);

        $this->get('/rencana?'.$query)
            ->assertOk()
            ->assertSee('name="tujuan[]"', false)
            ->assertSee('name="mulai"', false)
            ->assertDontSee('name="minat[]"', false)
            ->assertDontSee('name="durasi"', false)
            ->assertDontSee('name="moda[]"', false)
            ->assertSee('2 tujuan dipilih');
    }

    public function test_guest_manual_route_map_includes_a_place_start_and_one_destination(): void
    {
        $query = http_build_query([
            'minat' => ['sejarah'],
            'durasi' => 4,
            'moda' => ['motor'],
            'mulai' => 'awal',
            'tujuan' => ['tujuan-satu'],
            'tujuan_dikirim' => 1,
        ]);

        $response = $this->get('/rencana?'.$query)
            ->assertOk()
            ->assertSee('Masuk untuk menyimpan')
            ->assertSee('data-peta-mode="rute"', false);

        preg_match('/data-rute="([^"]+)"/', $response->getContent(), $matches);
        $rute = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
        $this->assertSame('awal', $rute[0]['nama'] ?? null);
        $this->assertSame('tujuan-satu', $rute[1]['nama'] ?? null);
        $this->assertCount(2, $rute);
    }

    public function test_saved_plan_edit_loads_only_for_its_owner_and_preserves_destinations(): void
    {
        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ]])
            ->get('/rencana?edit=mdn-abcd')
            ->assertOk()
            ->assertSee('Simpan perubahan')
            ->assertSee('action="'.route('rencana.perbarui', 'mdn-abcd').'"', false)
            ->assertSee('value="tujuan-satu" checked', false);

        $this->withSession(['pengguna' => [
            'id' => 8,
            'nama' => 'Wisatawan lain',
            'email' => 'lain@example.test',
            'peran' => 'wisatawan',
        ]])
            ->get('/rencana?edit=mdn-abcd')
            ->assertNotFound();
    }

    public function test_saved_plans_page_shows_edit_and_delete_actions(): void
    {
        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ]])
            ->get('/rencana/saya')
            ->assertOk()
            ->assertSee('Rencana saya')
            ->assertSee('Rute museum')
            ->assertSee(route('rencana', ['edit' => 'mdn-abcd']), false)
            ->assertSee(route('rencana.hapus', 'mdn-abcd'), false)
            ->assertSee('name="_method" value="DELETE"', false);
    }

    private function tempat(string $slug, float $lat, float $lng): array
    {
        return [
            'slug' => $slug,
            'jenis' => 'tempat',
            'nama' => $slug,
            'kategori' => 'sejarah',
            'foto' => null,
            'kecamatan' => 'Medan',
            'lat' => $lat,
            'lng' => $lng,
            'singgah' => 15,
            'jam' => '',
            'jam_menit' => null,
            'tiket' => [],
            'akses_gratis' => true,
        ];
    }
}
