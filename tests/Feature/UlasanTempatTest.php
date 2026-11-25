<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class UlasanTempatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.secret_key' => 'secret-key',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
        Katalog::forgetCache();
        (new ReflectionProperty(Katalog::class, 'entities'))->setValue(null, ['istana-maimun' => $this->samplePlace()]);
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_it_returns_the_next_review_batch_in_latest_first_order(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([[
                    'id_ulasan' => 6,
                    'id_pengguna' => 4,
                    'tempat_slug' => 'istana-maimun',
                    'nama_pengguna' => 'Rina',
                    'email_pengguna' => 'rina@example.test',
                    'teks' => '<script>alert(1)</script> Ulasan yang aman ditampilkan.',
                    'status' => 'tayang',
                    'created_at' => '2026-11-25T10:00:00Z',
                ]], 200, ['Content-Range' => '5-5/11']);
            }

            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([[
                    'id_pengguna' => 4,
                    'tempat_slug' => 'istana-maimun',
                    'bintang' => 5,
                ]]);
            }

            return Http::response([]);
        });

        $response = $this->getJson('/tempat/istana-maimun/ulasan?offset=5')
            ->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonPath('nextOffset', 6);

        $html = $response->json('html');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/ulasan?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['limit'] === '5'
                && $query['offset'] === '5'
                && $query['order'] === 'created_at.desc,id_ulasan.desc';
        });
    }

    public function test_it_rejects_non_place_slugs_and_invalid_offsets(): void
    {
        $this->getJson('/tempat/bukan-tempat/ulasan?offset=0')->assertNotFound();
        $this->getJson('/tempat/istana-maimun/ulasan?offset=-1')->assertUnprocessable();
    }

    public function test_place_page_shows_a_recent_preview_and_places_the_star_review_form_above_it(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ulasan?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if (isset($query['id_pengguna'])) {
                    return Http::response([]);
                }

                return Http::response([[
                    'id_ulasan' => 12,
                    'id_pengguna' => 8,
                    'tempat_slug' => 'istana-maimun',
                    'nama_pengguna' => 'Rina',
                    'email_pengguna' => 'rina@example.test',
                    'teks' => 'Tempat bersejarah yang menarik.',
                    'status' => 'tayang',
                    'created_at' => '2026-11-25T10:00:00Z',
                ]], 200, ['Content-Range' => '0-0/8']);
            }

            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([[
                    'id_pengguna' => 8,
                    'tempat_slug' => 'istana-maimun',
                    'bintang' => 5,
                ]]);
            }

            if (str_contains($request->url(), '/ringkasan_rating_tempat?')) {
                return Http::response([[
                    'tempat_slug' => 'istana-maimun',
                    'rata_rata_bintang' => 4.5,
                    'jumlah_rating' => 8,
                ]]);
            }

            return Http::response([]);
        });

        $response = $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ]])->get('/tempat/istana-maimun')
            ->assertOk()
            ->assertSee('Muat ulasan lainnya')
            ->assertSee('Rina')
            ->assertSee('data-ulasan-daftar')
            ->assertSee('5 bintang');

        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'Rina'), strpos($html, 'class="form-ulasan"'));
        $this->assertSame(5, substr_count($html, 'name="bintang"'));
        $this->assertGreaterThanOrEqual(5, substr_count($html, '<svg'));
    }

    private function samplePlace(): array
    {
        return [
            'slug' => 'istana-maimun',
            'jenis' => 'tempat',
            'nama' => 'Istana Maimun',
            'kategori' => 'sejarah',
            'ringkas' => 'Tempat bersejarah di Medan.',
            'alamat' => 'Medan',
            'kelurahan' => 'Aur',
            'kecamatan' => 'Medan Maimun',
            'lat' => 3.5752,
            'lng' => 98.6839,
            'radius_checkin' => 100,
            'foto' => null,
            'foto_lama' => null,
            'foto_keterangan' => null,
            'foto_posisi' => '50% 50%',
            'foto_lama_posisi' => '50% 50%',
            'tiket' => [],
            'jam' => '',
            'jam_contoh' => false,
            'akses_gratis' => true,
            'fasilitas' => [],
            'cara' => [],
        ];
    }
}
