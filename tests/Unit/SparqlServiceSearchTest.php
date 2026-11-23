<?php

namespace Tests\Unit;

use App\Support\SparqlService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SparqlServiceSearchTest extends TestCase
{
    public function test_search_uses_ontology_terms_and_escapes_user_literals(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit']);
        Http::fake(['sparql.test/*' => Http::response([
            'head' => ['vars' => ['tempat', 'kelas', 'nama', 'deskripsi', 'kecamatan', 'foto', 'lat', 'long']],
            'results' => ['bindings' => []],
        ])]);

        $hasil = SparqlService::cariTempat(
            kataKunci: 'taman") } UNION { ?s ?p ?o',
            kategori: 'ruang-publik',
            kecamatan: 'Medan Baru',
            fasilitas: 'Parkir',
            batas: 5,
            offset: 10,
        );

        $this->assertTrue($hasil['ok']);
        $request = Http::recorded()->first()[0];
        $query = $request->data()['query'] ?? '';

        $this->assertStringContainsString('mv:RuangPublik', $query);
        $this->assertStringContainsString('mv:beradaDi', $query);
        $this->assertStringContainsString('mv:fasilitas', $query);
        $this->assertStringContainsString('OFFSET 10', $query);
        $this->assertStringContainsString('taman\\") } union { ?s', $query);
    }

    public function test_updates_are_rejected_by_the_read_query_executor(): void
    {
        $hasil = SparqlService::jalankan('DELETE WHERE { ?s ?p ?o }');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('hanya menerima query SELECT', $hasil['pesan']);
    }

    public function test_mutation_after_a_read_query_is_rejected_before_http_request(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit']);
        Http::fake();

        $hasil = SparqlService::jalankan('SELECT * WHERE { ?s ?p ?o }; DELETE WHERE { ?s ?p ?o }');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('baca-saja', $hasil['pesan']);
        Http::assertNothingSent();
    }
}
