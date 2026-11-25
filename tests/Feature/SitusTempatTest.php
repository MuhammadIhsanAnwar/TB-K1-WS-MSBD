<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitusTempatTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_place_detail_reads_like_count_from_database_2_and_omits_removed_map(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);

        $base = 'https://medanvisit.neoverse.my.id/';
        $place = $base.'id/kolam-renang-primbana';
        $ontology = $base.'ontologi#';
        $binding = static fn (string $value, string $type = 'literal', ?string $datatype = null): array => array_filter([
            'type' => $type,
            'value' => $value,
            'datatype' => $datatype,
        ], fn ($value) => $value !== null);
        $triple = static fn (string $subject, string $predicate, string $object, string $objectType = 'literal', ?string $datatype = null): array => [
            's' => ['type' => 'uri', 'value' => $subject],
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => $binding($object, $objectType, $datatype),
        ];
        $bindings = [
            $triple($place, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'RekreasiAir', 'uri'),
            $triple($place, 'https://schema.org/name', 'Kolam Renang Primbana'),
            $triple($place, 'https://schema.org/description', 'Kolam renang di Medan Selayang.'),
            $triple($place, 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', '3.53961', 'literal', 'http://www.w3.org/2001/XMLSchema#decimal'),
            $triple($place, 'http://www.w3.org/2003/01/geo/wgs84_pos#long', '98.62883', 'literal', 'http://www.w3.org/2001/XMLSchema#decimal'),
            $triple($place, $ontology.'beradaDi', $base.'id/kec-medan-selayang', 'uri'),
            $triple($place, $ontology.'radiusCheckin', '100', 'literal', 'http://www.w3.org/2001/XMLSchema#integer'),
            $triple($base.'id/kec-medan-selayang', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'Kecamatan', 'uri'),
            $triple($base.'id/kec-medan-selayang', 'https://schema.org/name', 'Medan Selayang'),
        ];

        Http::fake(function (Request $request) use ($bindings) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/ringkasan_suka_tempat?')) {
                return Http::response([['tempat_slug' => 'kolam-renang-primbana', 'jumlah_suka' => 12]]);
            }
            if (str_contains($request->url(), '/ringkasan_rating_tempat?')) {
                return Http::response([[
                    'tempat_slug' => 'kolam-renang-primbana',
                    'rata_rata_bintang' => '4.5',
                    'jumlah_rating' => 10,
                ]]);
            }
            if (str_contains($request->url(), '/suka_tempat?')) {
                return Http::response([['tempat_slug' => 'kolam-renang-primbana']]);
            }

            return Http::response([]);
        });

        $this->get('/tempat/kolam-renang-primbana')
            ->assertOk()
            ->assertSee('Kolam Renang Primbana')
            ->assertSee('<span><b>12</b> like</span>', false)
            ->assertSee('<span><b>4,5</b> rating</span>', false)
            ->assertSee('Suka tempat ini')
            ->assertSee('<svg aria-hidden="true" focusable="false" width="18" height="18"', false)
            ->assertSee('&gt;', false)
            ->assertDontSee('â€º', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('property="mainEntity" typeof="TouristAttraction" resource="'.$place.'"', false)
            ->assertDontSee('Di peta Medan 1930')
            ->assertDontSee('peta-1930')
            ->assertDontSee('peta-medan-1930')
            ->assertDontSee('property="mainEntityOfPage"', false);

        $this->withSession(['pengguna' => [
            'id' => 17,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ]])->get('/tempat/kolam-renang-primbana')
            ->assertOk()
            ->assertSee('mv-btn--suka-aktif', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('fill="currentColor"', false);

        $admin = ['id' => 18, 'nama' => 'Admin', 'peran' => 'admin'];
        $this->withSession(['pengguna' => $admin])
            ->get('/tempat/kolam-renang-primbana')
            ->assertOk()
            ->assertSee('Batal suka')
            ->assertSee('mv-btn--suka-aktif', false);

        $csrf = 'test-csrf';
        $this->from('/tempat/kolam-renang-primbana')
            ->withSession(['pengguna' => $admin, '_token' => $csrf])
            ->withHeader('X-CSRF-TOKEN', $csrf)
            ->post('/tempat/kolam-renang-primbana/sukai')
            ->assertRedirect('/tempat/kolam-renang-primbana')
            ->assertSessionHas('status', 'Suka berhasil dibatalkan.');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/suka_tempat?')
            && str_contains($request->url(), 'id_pengguna=eq.18'));
    }
}
