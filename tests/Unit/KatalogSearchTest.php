<?php

namespace Tests\Unit;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KatalogSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_search_uses_sparql_aliases_and_finds_typo_candidates(): void
    {
        $tempat = 'https://medanvisit.neoverse.my.id/id/lapangan-merdeka';
        $bindings = [
            $this->binding($tempat, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'https://medanvisit.neoverse.my.id/ontologi#RuangPublik', 'uri'),
            $this->binding($tempat, 'https://schema.org/name', 'Lapangan Merdeka'),
            $this->binding($tempat, 'https://medanvisit.neoverse.my.id/ontologi#alias', 'Lapmer'),
            $this->binding($tempat, 'https://medanvisit.neoverse.my.id/ontologi#alias', 'Lap Mer'),
        ];

        Http::fake(['sparql.test/*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => $bindings],
        ])]);

        $hasilSingkatan = Katalog::cari('lapmer');
        $hasilTypo = Katalog::cari('lapangan merdeak');

        $this->assertSame('lapangan-merdeka', $hasilSingkatan[0]['slug']);
        $this->assertSame('lapangan-merdeka', $hasilTypo[0]['slug']);
        $this->get('/cari/saran.json?q=lapmer')
            ->assertOk()
            ->assertJsonPath('saran.0.nama', 'Lapangan Merdeka');
        $this->get('/cari/saran.json?q=lapangan%20merdeak')
            ->assertOk()
            ->assertJsonPath('saran.0.nama', 'Lapangan Merdeka');
        $this->get('/cari?q=lapmer&catat=0')
            ->assertOk()
            ->assertSee('Lapangan Merdeka')
            ->assertSee('data-cari-cepat', false);
        Http::assertSent(fn (Request $request) => str_contains($request->data()['query'] ?? '', 'mv:alias'));
    }

    private function binding(string $subject, string $predicate, string $value, string $type = 'literal'): array
    {
        return [
            's' => ['type' => 'uri', 'value' => $subject],
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => ['type' => $type, 'value' => $value],
        ];
    }
}
