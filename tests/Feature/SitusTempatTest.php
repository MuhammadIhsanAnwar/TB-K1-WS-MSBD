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

    public function test_place_detail_omits_the_1930_map_and_removed_entity_graph_helper(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);

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

            return Http::response([]);
        });

        $this->get('/tempat/kolam-renang-primbana')
            ->assertOk()
            ->assertSee('Kolam Renang Primbana')
            ->assertSee('property="og:title"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('property="mainEntity" typeof="TouristAttraction" resource="'.$place.'"', false)
            ->assertDontSee('Di peta Medan 1930')
            ->assertDontSee('peta-1930')
            ->assertDontSee('peta-medan-1930')
            ->assertDontSee('property="mainEntityOfPage"', false);
    }
}
