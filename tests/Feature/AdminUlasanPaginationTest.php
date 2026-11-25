<?php

namespace Tests\Feature;

use App\Support\Database3Catalog;
use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminUlasanPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Database3Catalog::forgetCache();
        Katalog::forgetCache();
        Cache::put('database3.catalog.triples.v8', [
            ['res:istana-maimun', 'rdf:type', 'mv:SitusSejarah'],
            ['res:istana-maimun', 'schema:name', 'Istana Maimun'],
        ]);
    }

    protected function tearDown(): void
    {
        Database3Catalog::forgetCache();
        Katalog::forgetCache();
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();

        parent::tearDown();
    }

    public function test_superadmin_can_choose_page_size_and_navigate_review_pages(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/rest/v1/ulasan?')
            ? Http::response([$this->sampleReview()], 200, ['Content-Range' => '10-10/25'])
            : Http::response([]));

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/ulasan?per_halaman=10&page=2')
            ->assertOk()
            ->assertSee('Menampilkan 11', false)
            ->assertSee('dari 25 ulasan')
            ->assertSee('value="10" selected', false)
            ->assertSee('page=3', false);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_contains($request->url(), '/rest/v1/ulasan?')
                && ($query['limit'] ?? null) === '10'
                && ($query['offset'] ?? null) === '10'
                && $request->hasHeader('Prefer', 'count=exact');
        });
    }

    public function test_admin_review_pages_are_filtered_to_managed_places(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/rest/v1/ulasan?')) {
                return Http::response([$this->sampleReview()], 200, ['Content-Range' => '0-0/1']);
            }
            if (str_contains($request->url(), '/rest/v1/admin_tempat?')) {
                return Http::response([
                    ['tempat_slug' => 'istana-maimun'],
                    ['tempat_slug' => 'taman-cadika'],
                ]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
            'tempat_slugs' => ['istana-maimun', 'taman-cadika'],
        ]])->get('/admin/ulasan?per_halaman=25')->assertOk();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/rest/v1/ulasan?')
                && ($query['tempat_slug'] ?? null) === 'in.(istana-maimun,taman-cadika)'
                && ($query['limit'] ?? null) === '25';
        });
    }

    private function sampleReview(): array
    {
        return [
            'id_ulasan' => 42,
            'id_pengguna' => 17,
            'tempat_slug' => 'istana-maimun',
            'nama_pengguna' => 'Rina',
            'email_pengguna' => 'rina@example.test',
            'rating_tempat' => ['bintang' => 5],
            'teks' => 'Tempat bersejarah yang menarik.',
            'status' => 'tayang',
            'created_at' => '2026-11-15T10:00:00Z',
        ];
    }
}
