<?php

namespace Tests\Feature;

use App\Support\Katalog;
use App\Support\SupabaseApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminTempatRestTest extends TestCase
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
    }

    public function test_admin_place_assignments_are_read_and_written_through_postgrest(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([['id_admin' => 7, 'tempat_slug' => 'istana-maimun']]);
            }

            return Http::response([['id_admin' => 7, 'tempat_slug' => 'masjid-raya']], 201);
        });

        $api = new SupabaseApi;
        $api->assignTempatAdmin(7, 'masjid-raya');
        $this->assertSame(['istana-maimun'], $api->tempatAdmin(7));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/admin_tempat')
            && (int) $request['id_admin'] === 7
            && $request['tempat_slug'] === 'masjid-raya');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/admin_tempat?')
            && str_contains($request->url(), 'id_admin=eq.7'));
    }

    public function test_admin_without_a_managed_place_cannot_open_admin_pages(): void
    {
        Http::fake([
            'https://katalog.test/rest/v1/admin_tempat*' => Http::response([]),
            'https://katalog.test/rest/v1/users*' => Http::response([[
                'id' => 7,
                'name' => 'Admin',
                'email' => 'admin@example.test',
                'peran' => 'admin',
            ]]),
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);
        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin');
    }

    public function test_superadmin_can_view_all_published_reviews_without_legacy_tabs(): void
    {
        $this->fakeReviewPage();

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/ulasan?tab=ditolak')
            ->assertOk()
            ->assertSee('Ulasan Istana')
            ->assertSee('Ulasan Masjid')
            ->assertSee('Tayang')
            ->assertDontSee('Belum tayang')
            ->assertDontSee('Ditolak');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'status=eq.tayang')
            && ! str_contains($request->url(), 'tempat_slug='));
    }

    public function test_admin_can_only_view_published_reviews_for_managed_places(): void
    {
        $this->fakeReviewPage();

        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/ulasan')
            ->assertOk()
            ->assertSee('Ulasan Istana')
            ->assertDontSee('Ulasan Masjid')
            ->assertDontSee('Belum tayang')
            ->assertDontSee('Ditolak');
    }

    public function test_admin_can_delete_reviews_for_managed_places(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'istana-maimun']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([[
                    'id_ulasan' => 12,
                    'id_pengguna' => 50,
                    'tempat_slug' => 'istana-maimun',
                    'nama_pengguna' => 'Wisatawan',
                    'email_pengguna' => 'wisatawan@example.test',
                    'rating_tempat' => ['bintang' => 5],
                    'teks' => 'Bagus',
                    'status' => 'tayang',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }

            return Http::response([['id_ulasan' => 12]], 200);
        });

        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->delete('/admin/ulasan/12')->assertRedirect();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'id_ulasan=eq.12'));
    }

    public function test_admin_cannot_delete_reviews_for_unmanaged_places(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'istana-maimun']]);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([[
                    'id_ulasan' => 12,
                    'id_pengguna' => 50,
                    'tempat_slug' => 'masjid-raya',
                    'nama_pengguna' => 'Wisatawan',
                    'email_pengguna' => 'wisatawan@example.test',
                    'rating_tempat' => ['bintang' => 5],
                    'teks' => 'Bagus',
                    'status' => 'tayang',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }

            return Http::response([], 200);
        });

        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->delete('/admin/ulasan/12')->assertForbidden();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ulasan?'));
    }

    private function fakeReviewPage(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Katalog::forgetCache();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'istana-maimun']]);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                $baris = [
                    $this->ulasanBaris(12, 'istana-maimun', 'Ulasan Istana'),
                    $this->ulasanBaris(13, 'masjid-raya', 'Ulasan Masjid'),
                ];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if (isset($query['tempat_slug'])) {
                    $slugs = explode(',', trim(substr($query['tempat_slug'], 3), '()'));
                    $baris = array_values(array_filter($baris, fn (array $ulasan) => in_array($ulasan['tempat_slug'], $slugs, true)));
                }
                $jumlah = count($baris);
                $rentang = $jumlah === 0 ? '*/0' : '0-'.($jumlah - 1).'/'.$jumlah;

                return Http::response($baris, 200, ['Content-Range' => $rentang]);
            }
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response($this->sparqlTempatResponse());
            }

            return Http::response([]);
        });
    }

    private function ulasanBaris(int $id, string $slug, string $teks): array
    {
        return [
            'id_ulasan' => $id,
            'id_pengguna' => 50,
            'tempat_slug' => $slug,
            'nama_pengguna' => 'Wisatawan',
            'email_pengguna' => 'wisatawan@example.test',
            'bintang' => 5,
            'teks' => $teks,
            'status' => 'tayang',
            'created_at' => '2026-11-15T10:00:00Z',
        ];
    }

    private function sparqlTempatResponse(): array
    {
        $binding = static fn (string $value, string $type = 'literal'): array => ['type' => $type, 'value' => $value];
        $triple = static fn (string $subject, string $predicate, string $object, string $type = 'literal'): array => [
            's' => $binding($subject, 'uri'),
            'p' => $binding($predicate, 'uri'),
            'o' => $binding($object, $type),
        ];
        $base = 'https://medanvisit.neoverse.my.id/id/';

        return [
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => [
                $triple($base.'istana-maimun', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'https://medanvisit.neoverse.my.id/ontologi#SitusSejarah', 'uri'),
                $triple($base.'istana-maimun', 'https://schema.org/name', 'Istana Maimun'),
                $triple($base.'masjid-raya', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'https://medanvisit.neoverse.my.id/ontologi#RumahIbadah', 'uri'),
                $triple($base.'masjid-raya', 'https://schema.org/name', 'Masjid Raya'),
            ]],
        ];
    }
}
