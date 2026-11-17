<?php

namespace Tests\Feature;

use App\Mail\BalasanPesanAdmin;
use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HubungiAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
        Cache::store('array')->flush();
        Katalog::forgetCache();
    }

    protected function tearDown(): void
    {
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_guest_must_log_in_before_opening_or_sending_a_contact_message(): void
    {
        $this->get('/hubungi-admin')->assertRedirect(route('masuk', ['kembali' => '/hubungi-admin']));
        $this->post('/hubungi-admin')->assertRedirect(route('masuk', ['kembali' => '/hubungi-admin']));
    }

    public function test_only_travelers_can_open_or_send_contact_messages(): void
    {
        Http::fake(fn () => Http::response([]));

        foreach (['admin', 'superadmin'] as $peran) {
            $this->withSession(['pengguna' => [
                'id' => 1,
                'nama' => ucfirst($peran),
                'email' => $peran.'@example.test',
                'peran' => $peran,
            ]]);

            $this->get('/hubungi-admin')->assertStatus(403);
            $this->post('/hubungi-admin', [
                'kategori' => 'akun',
                'pesan' => 'Mohon bantu saya dengan akun ini.',
            ])->assertStatus(403);
        }
    }

    public function test_admin_cannot_send_contact_messages(): void
    {
        Http::fake(fn () => Http::response([]));
        $this->withSession(['pengguna' => [
            'id' => 73,
            'nama' => 'Admin Tempat',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/hubungi-admin')->assertForbidden();

        $this->post('/hubungi-admin', [
            'kategori' => 'akun',
            'pesan' => 'Mohon bantuan untuk kebutuhan administrasi.',
        ])->assertForbidden();

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/pesan_pengguna'));
    }

    public function test_contact_form_routes_place_message_only_to_its_managing_admin(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response($this->sparqlPlaceResponse());
            }
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['id_admin' => 73]]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/pesan_pengguna')) {
                return Http::response([['id' => 51]], 201);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 19,
            'nama' => 'Rina',
            'email' => 'rina@example.test',
            'peran' => 'wisatawan',
        ]])->get('/hubungi-admin')
            ->assertOk()
            ->assertSee('Hubungi Admin')
            ->assertSee('Pilih kategori pesan');

        $this->post('/hubungi-admin', [
            'kategori' => 'tempat',
            'tempat_slug' => 'kolam-renang-primbana',
            'pesan' => 'Apakah tempat ini buka pada hari Senin?',
        ])->assertRedirect(route('hubungi.admin'))
            ->assertSessionHas('pesan');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/pesan_pengguna')
            && (int) $request['id_pengguna'] === 19
            && $request['kategori'] === 'tempat'
            && $request['tempat_slug'] === 'kolam-renang-primbana'
            && (int) $request['id_admin_tujuan'] === 73);
    }

    public function test_inboxes_are_scoped_to_the_current_admin_or_superadmin_categories(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'kolam-renang-primbana']]);
            }
            if (str_contains($request->url(), '/pesan_pengguna?')) {
                return Http::response([]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/balasan_pesan?')) {
                return Http::response([]);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 73,
            'nama' => 'Admin Tempat',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/pesan')->assertOk()->assertSee('Pesan pengguna');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pesan_pengguna?')
            && str_contains($request->url(), 'kategori=eq.tempat')
            && str_contains($request->url(), 'id_admin_tujuan=eq.73'));

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/pesan_pengguna?')) {
                return Http::response([]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/balasan_pesan?')) {
                return Http::response([]);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Superadmin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/pesan')->assertOk()->assertSee('Pesan pengguna');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pesan_pengguna?')
            && str_contains($request->url(), 'kategori=in.%28akun%2Clainnya%29'));
    }

    public function test_account_and_other_messages_are_routed_only_to_the_superadmin_inbox(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/pesan_pengguna')) {
                return Http::response([['id' => 52]], 201);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 19,
            'nama' => 'Rina',
            'email' => 'rina@example.test',
            'peran' => 'wisatawan',
        ]]);

        foreach (['akun', 'lainnya'] as $kategori) {
            $this->post('/hubungi-admin', [
                'kategori' => $kategori,
                'pesan' => 'Saya membutuhkan bantuan terkait akun saya.',
            ])->assertRedirect(route('hubungi.admin'));
        }

        foreach (['akun', 'lainnya'] as $kategori) {
            Http::assertSent(fn (Request $request) => $request->method() === 'POST'
                && str_ends_with($request->url(), '/pesan_pengguna')
                && $request['kategori'] === $kategori
                && $request['id_admin_tujuan'] === null);
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/admin_tempat'));
    }

    public function test_admin_cannot_open_a_message_assigned_to_another_admin(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'kolam-renang-primbana']]);
            }
            if (str_contains($request->url(), '/pesan_pengguna?')) {
                return Http::response([[
                    'id' => 51,
                    'kategori' => 'tempat',
                    'id_admin_tujuan' => 74,
                ]]);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 73,
            'nama' => 'Admin Tempat',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/pesan/51')->assertNotFound();
    }

    public function test_authorized_admin_reply_is_persisted_and_sent_to_the_user_by_email(): void
    {
        Mail::fake();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'kolam-renang-primbana']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/pesan_pengguna?')) {
                return Http::response([[
                    'id' => 51,
                    'id_pengguna' => 19,
                    'nama_pengguna' => 'Rina',
                    'email_pengguna' => 'rina@example.test',
                    'kategori' => 'tempat',
                    'tempat_slug' => 'kolam-renang-primbana',
                    'nama_tempat' => 'Kolam Renang Primbana',
                    'id_admin_tujuan' => 73,
                    'pesan' => 'Apakah tempat ini buka pada hari Senin?',
                    'status' => 'baru',
                    'created_at' => '2026-11-16T10:00:00+07:00',
                    'updated_at' => '2026-11-16T10:00:00+07:00',
                ]]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/balasan_pesan?')) {
                return Http::response([]);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                return Http::response([]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/balasan_pesan')) {
                return Http::response([['id' => 81]], 201);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/balasan_pesan?')) {
                return Http::response([['id' => 81]]);
            }

            return Http::response([['id' => 51]]);
        });

        $this->withSession(['pengguna' => [
            'id' => 73,
            'nama' => 'Admin Tempat',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin/pesan/51')->assertOk()->assertSee('Pesan awal')->assertSee('Rina');

        $this->post('/admin/pesan/51/balas', [
            'balasan' => 'Ya, tempat buka pada hari Senin.',
        ])->assertRedirect(route('admin.pesan.detail', 51))
            ->assertSessionHas('pesan');

        Mail::assertSent(BalasanPesanAdmin::class, fn (BalasanPesanAdmin $mail) => $mail->hasTo('rina@example.test'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/balasan_pesan')
            && (int) $request['pesan_id'] === 51
            && $request['isi'] === 'Ya, tempat buka pada hari Senin.');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/pesan_pengguna?')
            && $request['status'] === 'dibalas');
    }

    private function sparqlPlaceResponse(): array
    {
        $binding = static fn (string $value, string $type = 'literal'): array => ['type' => $type, 'value' => $value];
        $triple = static fn (string $subject, string $predicate, string $object, string $type = 'literal'): array => [
            's' => $binding($subject, 'uri'),
            'p' => $binding($predicate, 'uri'),
            'o' => $binding($object, $type),
        ];
        $base = 'https://medanvisit.neoverse.my.id/id/kolam-renang-primbana';

        return [
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => [
                $triple($base, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'https://medanvisit.neoverse.my.id/ontologi#RekreasiAir', 'uri'),
                $triple($base, 'https://schema.org/name', 'Kolam Renang Primbana'),
            ]],
        ];
    }
}
