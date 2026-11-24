<?php

namespace Tests\Unit;

use App\Services\AIService;
use App\Services\PostgreSQLService;
use App\Services\TanyaMasNonovService;
use App\Support\Katalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TanyaMasNonovServiceTest extends TestCase
{
    public function test_review_and_like_ranking_combines_postgresql_and_rdf_names(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        Katalog::forgetCache();
        Cache::put('medanvisit.favorit.istana-maimun', 5);
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('istana-maimun', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#SitusSejarah', 'uri'),
                    $this->triple('istana-maimun', 'https://schema.org/name', 'Istana Maimun', 'literal'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks, array $riwayat): bool {
                return $pertanyaan === 'Mana tempat yang paling banyak diulas dan banyak dilike?'
                    && str_contains($sumber, 'PostgreSQL')
                    && ($konteks['peringkat_ulasan_postgresql'][0]['nama'] ?? null) === 'Istana Maimun'
                    && ($konteks['peringkat_ulasan_postgresql'][0]['jumlah_ulasan'] ?? null) === 9
                    && ($konteks['peringkat_suka_cache_aplikasi'][0]['jumlah_suka'] ?? null) === 5;
            })
            ->andReturn('Istana Maimun memiliki jumlah ulasan dan suka tertinggi pada data saat ini.');
        $postgres = Mockery::mock(PostgreSQLService::class);
        $postgres->shouldReceive('peringkatUlasan')
            ->once()
            ->with(100)
            ->andReturn([[
                'tempat_slug' => 'istana-maimun',
                'jumlah_ulasan' => 9,
                'rata_rata_bintang' => '4.7',
            ]]);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Mana tempat yang paling banyak diulas dan banyak dilike?');

        $this->assertSame('Istana Maimun memiliki jumlah ulasan dan suka tertinggi pada data saat ini.', $jawaban['teks'][0]);
        $this->assertSame('Istana Maimun', $jawaban['tempat'][0]['nama']);
    }

    public function test_place_rating_and_likes_use_rdf_identity_postgresql_and_application_cache(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        Katalog::forgetCache();
        Cache::put('medanvisit.favorit.istana-maimun', 5);
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('istana-maimun', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#SitusSejarah', 'uri'),
                    $this->triple('istana-maimun', 'https://schema.org/name', 'Istana Maimun', 'literal'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks, array $riwayat): bool {
                return $pertanyaan === 'Berapa rating dan jumlah suka Istana Maimun?'
                    && str_contains($sumber, 'PostgreSQL')
                    && ($konteks['ulasan']['jumlah'] ?? null) === 9
                    && ($konteks['ulasan']['rata_rata_bintang'] ?? null) === 4.7
                    && ($konteks['jumlah_suka_cache_aplikasi'] ?? null) === 5;
            })
            ->andReturn('Istana Maimun memiliki 9 ulasan dan 5 suka.');
        $postgres = Mockery::mock(PostgreSQLService::class);
        $postgres->shouldReceive('ringkasanUlasanTempat')
            ->once()
            ->with('istana-maimun')
            ->andReturn(['tempat_slug' => 'istana-maimun', 'jumlah_ulasan' => 9, 'rata_rata_bintang' => '4.7']);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Berapa rating dan jumlah suka Istana Maimun?');

        $this->assertSame('Istana Maimun memiliki 9 ulasan dan 5 suka.', $jawaban['teks'][0]);
        $this->assertSame('Istana Maimun', $jawaban['tempat'][0]['nama']);
    }

    public function test_food_recommendations_use_rdf_culinary_catalog_when_ai_key_is_unavailable(): void
    {
        config([
            'services.openrouter.api_key' => null,
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        Katalog::forgetCache();
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('soto-kesawan', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#TempatKuliner', 'uri'),
                    $this->triple('soto-kesawan', 'https://schema.org/name', 'Soto Kesawan', 'literal'),
                    $this->triple('soto-kesawan', 'https://schema.org/description', 'Soto khas Medan di pusat kota.', 'literal'),
                    $this->triple('soto-kesawan', 'https://medanvisit.neoverse.my.id/ontologi#beradaDi', 'https://medanvisit.neoverse.my.id/id/kec-medan-barat', 'uri'),
                    $this->triple('kec-medan-barat', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#Kecamatan', 'uri'),
                    $this->triple('kec-medan-barat', 'https://schema.org/name', 'Medan Barat', 'literal'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Tempat makan enak');

        $this->assertStringContainsString('Soto Kesawan', $jawaban['teks'][0]);
        $this->assertCount(1, $jawaban['tempat']);
        $this->assertSame('Soto Kesawan', $jawaban['tempat'][0]['nama']);
    }

    public function test_lowercase_entity_questions_use_more_database3_facts_as_ai_context(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        Katalog::forgetCache();
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('istana-maimun', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#SitusSejarah', 'uri'),
                    $this->triple('istana-maimun', 'https://schema.org/name', 'Istana Maimun', 'literal'),
                    $this->triple('istana-maimun', 'https://schema.org/description', 'Istana Kesultanan Deli.', 'literal'),
                    $this->triple('istana-maimun', 'https://medanvisit.neoverse.my.id/ontologi#masa', '1888', 'literal'),
                    $this->triple('istana-maimun', 'https://medanvisit.neoverse.my.id/ontologi#caraMenuju', 'Dapat dicapai dengan angkutan umum.', 'literal'),
                    $this->triple('istana-maimun', 'http://purl.org/dc/terms/source', 'Catatan sejarah MedanVisit', 'literal'),
                    $this->triple('istana-maimun', 'https://medanvisit.neoverse.my.id/ontologi#dibangunOleh', 'https://medanvisit.neoverse.my.id/id/sultan-maimun', 'uri'),
                    $this->triple('sultan-maimun', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#Tokoh', 'uri'),
                    $this->triple('sultan-maimun', 'https://schema.org/name', "Sultan Ma'mun", 'literal'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks): bool {
                $this->assertSame('ceritakan tentang istana maimun', $pertanyaan);
                $this->assertStringContainsString('AllegroGraph', $sumber);
                $this->assertSame('1888', $konteks['entitas_rdf']['masa'] ?? null);
                $this->assertSame('Dapat dicapai dengan angkutan umum.', $konteks['entitas_rdf']['cara_menuju'][0] ?? null);
                $this->assertSame('Catatan sejarah MedanVisit', $konteks['entitas_rdf']['sumber'] ?? null);
                $this->assertStringContainsString('Sultan Ma\'mun', $konteks['relasi_rdf'][0]['fakta'] ?? '');
                $this->assertSame('Database 3', $konteks['relasi_rdf'][0]['sumber'] ?? null);

                return true;
            })
            ->andReturn('Istana Maimun dibangun pada 1888 oleh Sultan Ma\'mun.');
        $postgres = Mockery::mock(PostgreSQLService::class);

        $routing = TanyaMasNonovService::klasifikasi('ceritakan tentang istana maimun');
        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('ceritakan tentang istana maimun');

        $this->assertSame('tourism_knowledge', $routing['intent']);
        $this->assertSame('Istana Maimun dibangun pada 1888 oleh Sultan Ma\'mun.', $jawaban['teks'][0]);
        $this->assertSame('Istana Maimun', $jawaban['tempat'][0]['nama']);
    }

    public function test_ticket_price_question_uses_rdf_ticket_offers_as_ai_context(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        Katalog::forgetCache();
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('istana-maimun', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#SitusSejarah', 'uri'),
                    $this->triple('istana-maimun', 'https://schema.org/name', 'Istana Maimun', 'literal'),
                    $this->triple('istana-maimun', 'https://schema.org/description', 'Istana Kesultanan Deli.', 'literal'),
                    $this->triple('istana-maimun', 'https://schema.org/offers', 'offer-dewasa', 'bnode'),
                    $this->triple('istana-maimun', 'https://schema.org/offers', 'offer-anak', 'bnode'),
                    $this->triple('offer-dewasa', 'rdf:type', 'https://schema.org/Offer', 'uri'),
                    $this->triple('offer-dewasa', 'https://schema.org/name', 'Dewasa', 'literal'),
                    $this->triple('offer-dewasa', 'https://schema.org/price', '10000', 'typed-literal', 'http://www.w3.org/2001/XMLSchema#integer'),
                    $this->triple('offer-anak', 'rdf:type', 'https://schema.org/Offer', 'uri'),
                    $this->triple('offer-anak', 'https://schema.org/name', 'Anak', 'literal'),
                    $this->triple('offer-anak', 'https://schema.org/price', '5000', 'typed-literal', 'http://www.w3.org/2001/XMLSchema#integer'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks): bool {
                $teksKatalog = implode(' ', $konteks['teks'] ?? []);

                return $pertanyaan === 'Berapa harga tiket Istana Maimun?'
                    && str_contains($sumber, 'AllegroGraph')
                    && str_contains($teksKatalog, 'Dewasa Rp10.000')
                    && str_contains($teksKatalog, 'Anak Rp5.000');
            })
            ->andReturn('Tiket dewasa Rp10.000 dan anak Rp5.000.');
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Berapa harga tiket Istana Maimun?');

        $this->assertSame('Tiket dewasa Rp10.000 dan anak Rp5.000.', $jawaban['teks'][0]);
        $this->assertCount(1, $jawaban['tempat']);
        $this->assertSame('Istana Maimun', $jawaban['tempat'][0]['nama']);
    }

    private function triple(
        string $subjek,
        string $predikat,
        string $objek,
        string $jenisObjek,
        ?string $datatype = null,
    ): array {
        $subject = str_starts_with($subjek, 'offer-')
            ? ['type' => 'bnode', 'value' => $subjek]
            : ['type' => 'uri', 'value' => 'https://medanvisit.neoverse.my.id/id/'.$subjek];
        $predicate = match ($predikat) {
            'rdf:type' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type',
            default => $predikat,
        };
        $object = $jenisObjek === 'uri'
            ? ['type' => 'uri', 'value' => $objek]
            : ['type' => $jenisObjek === 'bnode' ? 'bnode' : 'literal', 'value' => $objek];
        if ($datatype !== null) {
            $object['datatype'] = $datatype;
        }

        return [
            's' => $subject,
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => $object,
        ];
    }
}
