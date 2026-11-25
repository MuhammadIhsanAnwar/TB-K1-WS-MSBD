<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class UlasanSayaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Katalog::forgetCache();
        (new ReflectionProperty(Katalog::class, 'entities'))->setValue(null, [
            'istana-maimun' => [
                'slug' => 'istana-maimun',
                'jenis' => 'tempat',
                'nama' => 'Istana Maimun',
                'kategori' => 'sejarah',
                'foto' => 'istana-maimun.jpg',
                'kecamatan' => 'Medan Maimun',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_account_review_list_offers_edit_and_delete_actions(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }
            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([[
                    'id_pengguna' => 7,
                    'tempat_slug' => 'istana-maimun',
                    'bintang' => 4,
                ]]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => $this->user()])
            ->get('/ulasan-saya')
            ->assertOk()
            ->assertSee('Istana Maimun')
            ->assertSee('Edit')
            ->assertSee('name="bintang"', false)
            ->assertSee('type="radio"', false)
            ->assertSee('value="4" checked', false)
            ->assertSee('name="teks"', false)
            ->assertSee(route('ulasan.perbarui', 42), false)
            ->assertSee(route('ulasan.hapus-saya', 42), false);
    }

    public function test_success_flash_is_rendered_once_on_the_reviews_page(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }
            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        foreach ([
            'Ulasan dan rating berhasil diperbarui.',
            'Ulasan dan rating berhasil dihapus.',
        ] as $message) {
            $response = $this->withSession([
                'pengguna' => $this->user(),
                'pesan' => $message,
            ])->get('/ulasan-saya')->assertOk();

            $this->assertSame(1, substr_count($response->getContent(), $message));
        }
    }

    public function test_user_can_update_their_review_and_rating(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }
            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([]);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => $this->user(), '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->put('/ulasan-saya/42', [
                'bintang' => 5,
                'teks' => 'Tempat bersejarah yang sangat menarik.',
            ])
            ->assertRedirect(route('ulasan.saya'))
            ->assertSessionHas('pesan', 'Ulasan dan rating berhasil diperbarui.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rating_tempat?')
            && $request[0]['id_pengguna'] === 7
            && $request[0]['bintang'] === 5);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'id_ulasan=eq.42')
            && $request['teks'] === 'Tempat bersejarah yang sangat menarik.'
            && $request['status'] === 'tayang');
    }

    public function test_update_from_a_place_page_returns_to_that_place(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }
            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([]);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => $this->user(), '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->put('/ulasan-saya/42', [
                'bintang' => 5,
                'teks' => 'Tempat bersejarah yang sangat menarik.',
                'kembali' => 'tempat',
            ])
            ->assertRedirect('/tempat/istana-maimun#ulasan');
    }

    public function test_user_can_delete_their_review_and_rating(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([$this->review()]);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), '/ulasan?')) {
                return Http::response([['id_ulasan' => 42]]);
            }
            if (str_contains($request->url(), '/rating_tempat?')) {
                return Http::response([[
                    'id_pengguna' => 7,
                    'tempat_slug' => 'istana-maimun',
                    'bintang' => 4,
                ]]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => $this->user(), '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->delete('/ulasan-saya/42')
            ->assertRedirect(route('ulasan.saya'))
            ->assertSessionHas('pesan', 'Ulasan dan rating berhasil dihapus.');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'id_ulasan=eq.42'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rating_tempat?'));
    }

    public function test_user_cannot_edit_or_delete_another_users_review(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/ulasan?')
            ? Http::response([])
            : Http::response([]));

        $this->withSession(['pengguna' => $this->user(), '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->put('/ulasan-saya/42', [
                'bintang' => 5,
                'teks' => 'Ulasan yang cukup panjang.',
            ])
            ->assertNotFound();

        $this->withSession(['pengguna' => $this->user(), '_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->delete('/ulasan-saya/42')
            ->assertNotFound();

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PATCH', 'DELETE'], true));
    }

    private function user(): array
    {
        return [
            'id' => 7,
            'nama' => 'Wisatawan',
            'email' => 'wisatawan@example.test',
            'peran' => 'wisatawan',
        ];
    }

    private function review(): array
    {
        return [
            'id_ulasan' => 42,
            'id_pengguna' => 7,
            'tempat_slug' => 'istana-maimun',
            'nama_pengguna' => 'Wisatawan',
            'email_pengguna' => 'wisatawan@example.test',
            'teks' => 'Tempat bersejarah yang menarik.',
            'status' => 'tayang',
            'created_at' => '2026-11-15T10:00:00Z',
            'rating_tempat' => ['bintang' => 4],
        ];
    }
}
