<?php

namespace Tests\Unit;

use App\Services\AIService;
use App\Services\PostgreSQLService;
use App\Services\TanyaMasNonovService;
use App\Support\Katalog;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TanyaMasNonovServiceTest extends TestCase
{
    public function test_review_and_like_ranking_uses_postgresql_view_and_rdf_names(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Katalog::forgetCache();
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
                    && ($konteks['peringkat_suka_db2'][0]['jumlah_suka'] ?? null) === 5;
            })
            ->andReturn('Istana Maimun memiliki jumlah ulasan dan suka tertinggi pada data saat ini.');
        $postgres = Mockery::mock(PostgreSQLService::class);
        $postgres->shouldReceive('peringkatUlasan')
            ->once()
            ->with(100)
            ->andReturn([[
                'tempat_slug' => 'istana-maimun',
                'jumlah_ulasan' => 9,
                'rata_rata_ulasan' => '4.7',
                'jumlah_rating' => 12,
                'rata_rata_bintang' => '4.7',
                'peringkat_ulasan' => 1,
            ]]);
        $postgres->shouldReceive('peringkatSuka')
            ->once()
            ->with(1000)
            ->andReturn([[
                'tempat_slug' => 'istana-maimun',
                'jumlah_suka' => 5,
                'peringkat_suka' => 1,
            ]]);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Mana tempat yang paling banyak diulas dan banyak dilike?');

        $this->assertSame('Istana Maimun memiliki jumlah ulasan dan suka tertinggi pada data saat ini.', $jawaban['teks'][0]);
        $this->assertSame('Istana Maimun', $jawaban['tempat'][0]['nama']);
    }

    public function test_place_rating_and_likes_use_postgresql_popularity_view_and_rdf_identity(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Katalog::forgetCache();
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
                    && ($konteks['jumlah_suka_db2'] ?? null) === 5;
            })
            ->andReturn('Istana Maimun memiliki 9 ulasan dan 5 suka.');
        $postgres = Mockery::mock(PostgreSQLService::class);
        $postgres->shouldReceive('ringkasanPopularitasTempat')
            ->once()
            ->with('istana-maimun')
            ->andReturn([
                'tempat_slug' => 'istana-maimun',
                'jumlah_ulasan' => 9,
                'rata_rata_ulasan' => '4.7',
                'jumlah_rating' => 12,
                'rata_rata_bintang' => '4.7',
                'jumlah_suka' => 5,
            ]);

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
        $jawabanAyam = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Ayam enak');

        $this->assertStringContainsString('Soto Kesawan', $jawaban['teks'][0]);
        $this->assertCount(1, $jawaban['tempat']);
        $this->assertSame('Soto Kesawan', $jawaban['tempat'][0]['nama']);
        $this->assertStringContainsString('Soto Kesawan', $jawabanAyam['teks'][0]);
        $this->assertSame('Soto Kesawan', $jawabanAyam['tempat'][0]['nama']);
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

    public function test_general_questions_are_sent_to_ai_and_answered_as_conversation(): void
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
                    $this->triple('soto-kesawan', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#TempatKuliner', 'uri'),
                    $this->triple('soto-kesawan', 'https://schema.org/name', 'Soto Kesawan', 'literal'),
                    $this->triple('soto-kesawan', 'https://schema.org/description', 'Soto khas Medan.', 'literal'),
                ]],
            ]),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->times(3)
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks, array $riwayat): bool {
                if ($pertanyaan === 'Kamu siapa?') {
                    return str_contains($sumber, 'fakta publik MedanVisit')
                        && ($konteks['website']['nama'] ?? null) === 'MedanVisit';
                }

                if ($pertanyaan === 'Ayam enak') {
                    return str_contains($sumber, 'AllegroGraph RDF')
                        && ($konteks['kategori'] ?? null) === 'kuliner'
                        && ($konteks['hasil'][0]['nama'] ?? null) === 'Soto Kesawan';
                }

                return $pertanyaan === 'Apa yang ada di MedanVisit?'
                    && str_contains($sumber, 'Database 3')
                    && ($konteks['katalog_database3']['contoh_tempat'][0]['nama'] ?? null) === 'Soto Kesawan';
            })
            ->andReturn(
                'Saya Mas Nonov, asisten AI MedanVisit.',
                'Iya, ayam memang enak. Kamu ingin rekomendasi kuliner ayam di Medan?',
                'MedanVisit memiliki informasi tentang tempat wisata dan kuliner di Medan.',
            );
        $postgres = Mockery::mock(PostgreSQLService::class);
        $service = new TanyaMasNonovService($ai, $postgres);

        $this->assertSame(
            'Saya Mas Nonov, asisten AI MedanVisit.',
            $service->jawab('Kamu siapa?')['teks'][0],
        );
        $this->assertSame(
            'Iya, ayam memang enak. Kamu ingin rekomendasi kuliner ayam di Medan?',
            $service->jawab('Ayam enak')['teks'][0],
        );
        $this->assertSame(
            'MedanVisit memiliki informasi tentang tempat wisata dan kuliner di Medan.',
            $service->jawab('Apa yang ada di MedanVisit?')['teks'][0],
        );
    }

    public function test_nearby_food_uses_browser_location_and_returns_sorted_distances_without_sending_coordinates_to_ai(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        $this->fakeKatalogDenganKoordinat();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks): bool {
                $hasil = $konteks['hasil'] ?? [];

                return $pertanyaan === 'Tempat makan terdekat'
                    && ($konteks['titik_awal'] ?? null) === 'lokasimu saat ini'
                    && count($hasil) === 2
                    && ($hasil[0]['nama'] ?? null) === 'Soto Kesawan'
                    && is_int($hasil[0]['jarak_meter'] ?? null)
                    && ! str_contains(json_encode($konteks), '3.5613')
                    && ! str_contains(json_encode($konteks), '98.6568');
            })
            ->andReturn('Soto Kesawan adalah pilihan terdekat.');
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Tempat makan terdekat', null, [], ['lat' => 3.5613, 'lng' => 98.6568]);

        $this->assertSame('Soto Kesawan adalah pilihan terdekat.', $jawaban['teks'][0]);
        $this->assertSame(['Soto Kesawan', 'Mie Aceh'], array_column($jawaban['tempat'], 'nama'));
        $this->assertGreaterThanOrEqual(0, $jawaban['tempat'][0]['jarak']);
        $this->assertLessThan($jawaban['tempat'][1]['jarak'], $jawaban['tempat'][0]['jarak']);
    }

    public function test_nearby_followup_from_usu_uses_conversation_context_and_reference_coordinates(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        $this->fakeKatalogDenganKoordinat();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks): bool {
                return $pertanyaan === 'Tempat makan terdekat dari USU'
                    && ($konteks['titik_awal'] ?? null) === 'Universitas Sumatera Utara (USU)'
                    && ($konteks['hasil'][0]['nama'] ?? null) === 'Soto Kesawan'
                    && is_int($konteks['hasil'][0]['jarak_meter'] ?? null);
            })
            ->andReturn('Soto Kesawan adalah pilihan terdekat dari USU.');
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Dari USU', null, [[
                'pertanyaan' => 'Tempat makan terdekat',
                'jawaban' => 'Sebutkan titik awal.',
            ]]);

        $this->assertSame('Soto Kesawan adalah pilihan terdekat dari USU.', $jawaban['teks'][0]);
        $this->assertSame('Soto Kesawan', $jawaban['tempat'][0]['nama']);
    }

    public function test_distance_question_from_user_location_returns_the_named_destination_only(): void
    {
        config([
            'services.openrouter.api_key' => 'test-openrouter-key',
            'services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit',
        ]);
        $this->fakeKatalogDenganKoordinat();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('jawabBerbasisKonteks')
            ->once()
            ->withArgs(function (string $pertanyaan, string $sumber, array $konteks): bool {
                return $pertanyaan === 'Berapa jarak saya ke Museum Negeri?'
                    && ($konteks['titik_awal'] ?? null) === 'lokasimu saat ini'
                    && count($konteks['hasil'] ?? []) === 1
                    && ($konteks['hasil'][0]['nama'] ?? null) === 'Museum Negeri';
            })
            ->andReturn('Museum Negeri berjarak sekitar 1 meter.');
        $postgres = Mockery::mock(PostgreSQLService::class);

        $jawaban = (new TanyaMasNonovService($ai, $postgres))
            ->jawab('Berapa jarak saya ke Museum Negeri?', null, [], ['lat' => 3.5613, 'lng' => 98.6568]);

        $this->assertSame('Museum Negeri berjarak sekitar 1 meter.', $jawaban['teks'][0]);
        $this->assertCount(1, $jawaban['tempat']);
        $this->assertSame('Museum Negeri', $jawaban['tempat'][0]['nama']);
    }

    private function fakeKatalogDenganKoordinat(): void
    {
        Katalog::forgetCache();
        Http::fake([
            'sparql.test/*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => [
                    $this->triple('soto-kesawan', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#TempatKuliner', 'uri'),
                    $this->triple('soto-kesawan', 'https://schema.org/name', 'Soto Kesawan', 'literal'),
                    $this->triple('soto-kesawan', 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', '3.5614', 'literal'),
                    $this->triple('soto-kesawan', 'http://www.w3.org/2003/01/geo/wgs84_pos#long', '98.6568', 'literal'),
                    $this->triple('mie-aceh', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#TempatKuliner', 'uri'),
                    $this->triple('mie-aceh', 'https://schema.org/name', 'Mie Aceh', 'literal'),
                    $this->triple('mie-aceh', 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', '3.5620', 'literal'),
                    $this->triple('mie-aceh', 'http://www.w3.org/2003/01/geo/wgs84_pos#long', '98.6570', 'literal'),
                    $this->triple('museum-negeri', 'rdf:type', 'https://medanvisit.neoverse.my.id/ontologi#Museum', 'uri'),
                    $this->triple('museum-negeri', 'https://schema.org/name', 'Museum Negeri', 'literal'),
                    $this->triple('museum-negeri', 'http://www.w3.org/2003/01/geo/wgs84_pos#lat', '3.56131', 'literal'),
                    $this->triple('museum-negeri', 'http://www.w3.org/2003/01/geo/wgs84_pos#long', '98.6568', 'literal'),
                ]],
            ]),
        ]);
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
