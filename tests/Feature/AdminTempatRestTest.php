<?php

namespace Tests\Feature;

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
        Http::fake(['https://katalog.test/rest/v1/admin_tempat*' => Http::response([])]);

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);
        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin');
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
                    'bintang' => 5,
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
                    'bintang' => 5,
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
}
