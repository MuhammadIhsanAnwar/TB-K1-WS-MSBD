<?php

namespace Tests\Feature;

use App\Support\Database3CatalogWriter;
use App\Support\Katalog;
use App\Support\Kunjungan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDataTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_visit_counts_are_aggregated_without_recording_visitor_identity(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();

        Kunjungan::catat('beranda');
        Kunjungan::catat('tempat', 'istana-maimun');
        $summary = Kunjungan::ringkasan(1);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['rute']['beranda']);
        $this->assertSame(1, $summary['tempat']['istana-maimun']);
    }

    public function test_public_page_visits_are_counted_by_the_middleware(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Http::fake(['*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => []],
        ], 200)]);

        $this->get('/ketentuan')->assertOk();

        $summary = Kunjungan::ringkasan(1);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['rute']['ketentuan']);
    }

    public function test_superadmin_can_open_account_management_but_admin_cannot(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/akun')->assertOk()->assertSee('Kelola akun');

        $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/akun')->assertForbidden();
    }

    public function test_editor_relasi_navigation_is_only_visible_to_superadmin(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://supabase.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'test-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/rest/v1/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'istana-maimun']], 200);
            }
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => []],
                ], 200);
            }

            return Http::response([], 200);
        });

        $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin')->assertOk()
            ->assertSee('Insight')
            ->assertDontSee('Editor relasi')
            ->assertDontSee('Antrean kerja kurator')
            ->assertDontSee('Dicari tetapi tanpa hasil')
            ->assertDontSee('Relasi bersumber survei, perlu verifikasi')
            ->assertDontSee('Tempat yang perlu dilengkapi')
            ->assertDontSee('Usulan relasi baru')
            ->assertSee('Ulasan terbaru');

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin')->assertOk()
            ->assertSee('Insight')
            ->assertSee('Editor relasi')
            ->assertSee('Antrean kerja kurator')
            ->assertSee('Dicari tetapi tanpa hasil')
            ->assertSee('Relasi bersumber survei, perlu verifikasi')
            ->assertSee('Tempat yang perlu dilengkapi')
            ->assertSee('Usulan relasi baru');
    }

    public function test_admin_role_is_refreshed_from_database_before_denying_admin_dashboard(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        config([
            'services.supabase.katalog.url' => 'https://supabase.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'test-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Http::fake([
            'https://supabase.test/rest/v1/users*' => Http::response([[
                'id' => 3,
                'name' => 'Super Admin',
                'email' => 'superadmin@example.test',
                'peran' => 'superadmin',
                'foto_profil' => null,
            ]], 200),
            'https://transaksi.test/*' => Http::response([], 200),
            '*' => Http::response([
                'head' => ['vars' => ['s', 'p', 'o']],
                'results' => ['bindings' => []],
            ], 200),
        ]);

        $response = $this->withSession(['pengguna' => [
            'id' => 3,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'wisatawan',
        ]])->get('/admin');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/v1/users'));
        $response->assertOk()->assertSee('Ringkasan');

        $this->withSession(['pengguna' => [
            'id' => 3,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin')->assertOk()->assertSee('Ringkasan');
    }

    public function test_superadmin_insight_and_place_creation_pages_render(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Http::fake(['*' => fn (Request $request) => str_contains($request->url(), '/rest/v1/')
            ? Http::response([], 200)
            : Http::response(['head' => ['vars' => ['s', 'p', 'o']], 'results' => ['bindings' => []]], 200)]);
        Cache::put('database3.catalog.triples.v8', [
            ['res:museum-test', 'rdf:type', 'mv:Museum'],
            ['res:museum-test', 'schema:name', 'Museum Test'],
        ]);
        $session = ['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]];

        $response = $this->withSession($session)->get('/admin/insight');
        $response
            ->assertOk()
            ->assertSee('Insight')
            ->assertSee('Jumlah tempat wisata')
            ->assertSee('data-grafik=', false)
            ->assertDontSee('p-batang', false);
        $this->assertMatchesRegularExpression(
            '/<div class="label">Jumlah tempat wisata<\/div>\s*<div class="nilai">1<\/div>\s*<div class="ket">Terdaftar di Database 3<\/div>/',
            $response->getContent(),
        );
        $this->withSession($session)->get('/admin/tempat/baru')->assertOk()->assertSee('Tambah tempat wisata');
    }

    public function test_admin_insight_renders_graph_for_managed_place(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://supabase.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'test-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'istana-maimun']], 200);
            }
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => []],
                ], 200);
            }

            return Http::response([], 200);
        });

        $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/insight')
            ->assertOk()
            ->assertSee('Grafik tayangan halaman per hari')
            ->assertDontSee('Jumlah tempat wisata')
            ->assertSee('data-grafik=', false)
            ->assertDontSee('p-batang', false);
    }

    public function test_sync_page_uses_database_labels_and_omits_transaction_totals(): void
    {
        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'test-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
            'services.sparql.endpoint' => 'https://sparql.test/query',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        $session = ['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]];

        $this->withSession($session)->get('/admin/sinkronisasi?periksa=1')
            ->assertOk()
            ->assertSee('Database 1 PostgreSQL Supabase - Akun dan Konfigurasi Website')
            ->assertSee('Database 2 PostgreSQL Supabase - Transaksi')
            ->assertSee('Database 3 SPARQL Allegrograph - Tempat Wisata')
            ->assertDontSee('Isi Database 2 (transaksi)')
            ->assertDontSee('pencarian tercatat');
    }

    public function test_place_update_and_delete_use_the_configured_sparql_endpoint(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit']);
        Http::fake(['sparql.test/*' => Http::response('', 204)]);
        $data = [
            'nama' => 'Tempat Baru',
            'ringkas' => 'Deskripsi diperbarui.',
            'kategori' => 'museum',
            'lat' => '3.58',
            'lng' => '98.67',
            'alamat' => 'Jalan Baru',
            'jam' => 'Setiap hari, 08.00–17.00',
            'sumber' => 'https://example.test/sumber',
            'foto' => '',
            'kecamatan' => '',
        ];

        Database3CatalogWriter::update('tempat-baru', $data, []);
        Database3CatalogWriter::delete('tempat-baru');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) =>
            str_contains(urldecode($request->body()), 'INSERT')
            && str_contains(urldecode($request->body()), 'DELETE')
        );
        Http::assertSent(fn (Request $request) =>
            str_contains(urldecode($request->body()), 'rdf:Statement')
            && str_contains(urldecode($request->body()), 'res:tempat-baru')
        );
    }

    public function test_place_creation_is_sent_as_a_sparql_update(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/repositories/medanvisit']);
        Http::fake(['sparql.test/*' => Http::response('', 204)]);

        Database3CatalogWriter::create('tempat-baru', [
            'nama' => 'Tempat Baru',
            'ringkas' => 'Tempat wisata baru.',
            'kategori' => 'museum',
            'lat' => '3.58',
            'lng' => '98.67',
            'alamat' => 'Jalan Baru',
            'jam' => 'Setiap hari, 08.00–17.00',
            'sumber' => 'https://example.test/sumber',
            'foto' => '',
            'kecamatan' => '',
        ], []);

        Http::assertSent(fn (Request $request) =>
            str_contains(urldecode($request->body()), 'INSERT DATA')
            && str_contains(urldecode($request->body()), 'res:tempat-baru')
            && str_contains(urldecode($request->body()), 'mv:Museum')
        );
    }
}
