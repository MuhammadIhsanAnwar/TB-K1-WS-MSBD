<?php

namespace Tests\Feature;

use App\Support\Database3CatalogWriter;
use App\Support\Katalog;
use App\Support\Kunjungan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
        Carbon::setTestNow();

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

    public function test_account_lists_have_independent_page_sizes_and_pagination(): void
    {
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://supabase.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
        ]);
        $users = [];
        foreach (range(1, 12) as $number) {
            $users[] = [
                'id' => $number,
                'name' => 'Admin '.$number,
                'email' => 'admin-'.$number.'@example.test',
                'peran' => 'admin',
            ];
            $users[] = [
                'id' => $number + 100,
                'name' => 'Wisatawan '.$number,
                'email' => 'wisatawan-'.$number.'@example.test',
                'peran' => 'wisatawan',
            ];
        }
        Http::fake(function (Request $request) use ($users) {
            if (str_contains($request->url(), '/rest/v1/users?')) {
                return Http::response($users, 200);
            }

            return Http::response([], 200);
        });
        $superadmin = ['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]];

        $this->withSession($superadmin)
            ->get('/admin/akun?peran=admin&per_halaman_admin=10')
            ->assertOk()
            ->assertSee('admin-1@example.test')
            ->assertDontSee('admin-11@example.test')
            ->assertSee('name="per_halaman_admin"', false)
            ->assertSee('halaman_admin=2');

        $this->withSession($superadmin)
            ->get('/admin/akun?peran=admin&q=admin-2%40example.test')
            ->assertOk()
            ->assertSee('admin-2@example.test')
            ->assertDontSee('admin-1@example.test')
            ->assertSee('class="num">1</span>', false);

        $this->withSession($superadmin)
            ->get('/admin/akun?peran=wisatawan&per_halaman_admin=25&per_halaman_wisatawan=10')
            ->assertOk()
            ->assertSee('wisatawan-1@example.test')
            ->assertDontSee('wisatawan-11@example.test')
            ->assertSee('name="per_halaman_wisatawan"', false)
            ->assertSee('value="25"', false)
            ->assertSee('halaman_wisatawan=2');
    }

    public function test_editor_relasi_navigation_is_hidden_from_admin_and_superadmin(): void
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
            ->assertSee('Tempat wisata saya')
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
            ->assertSee('Kelola tempat wisata')
            ->assertDontSee('<span>Entitas</span>', false)
            ->assertSee('Antrean kerja kurator')
            ->assertSee('Dicari tetapi tanpa hasil')
            ->assertSee('Relasi bersumber survei, perlu verifikasi')
            ->assertSee('Tempat yang perlu dilengkapi')
            ->assertSee('Usulan relasi baru');

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/akun')->assertOk()
            ->assertSee('Kelola akun')
            ->assertDontSee('Editor relasi');
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
        Cache::put('database3.catalog.triples.v9', [
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

    public function test_superadmin_place_list_uses_catalog_data_with_category_filter_and_pagination(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        $triples = [];
        for ($i = 1; $i <= 12; $i++) {
            $slug = 'tempat-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $jenis = $i <= 6 ? 'mv:Museum' : 'mv:RuangPublik';
            $triples[] = ['res:'.$slug, 'rdf:type', $jenis];
            $triples[] = ['res:'.$slug, 'schema:name', 'Tempat '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
        }
        Cache::put('database3.catalog.triples.v9', $triples);
        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        $session = ['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]];

        $this->withSession($session)
            ->get('/admin/tempat?per_halaman=10')
            ->assertOk()
            ->assertSee('Kelola tempat wisata')
            ->assertSee('Menampilkan 1&ndash;10 dari 12', false)
            ->assertSee('No.')
            ->assertSee('Tempat 01')
            ->assertSee('Tempat 10')
            ->assertDontSee('Tempat 11')
            ->assertSee('page=2');

        $this->withSession($session)
            ->get('/admin/tempat?per_halaman=10&page=2')
            ->assertOk()
            ->assertSee('Tempat 11')
            ->assertSee('Tempat 12');

        $this->withSession($session)
            ->get('/admin/tempat?kategori=museum&per_halaman=10')
            ->assertOk()
            ->assertSee('Menampilkan 1&ndash;6 dari 6', false)
            ->assertSee('Tempat 01')
            ->assertSee('Tempat 06')
            ->assertDontSee('Tempat 07');
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

    public function test_admin_and_superadmin_insights_use_stored_transaction_totals_with_correct_scope(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'Asia/Jakarta'));
        Katalog::forgetCache();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'test-key',
            'services.supabase.katalog.secret_key' => 'test-secret',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'test-key',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Cache::put('database3.catalog.triples.v9', [
            ['res:museum-test', 'rdf:type', 'mv:Museum'],
            ['res:museum-test', 'schema:name', 'Museum Test'],
        ]);
        Kunjungan::catat('tempat', 'museum-test');
        Kunjungan::catat('tempat', 'tempat-lain');

        $akun = [
            [
                'id' => 1,
                'name' => 'Super Admin',
                'email' => 'superadmin@example.test',
                'peran' => 'superadmin',
                'foto_profil' => null,
                'no_hp' => null,
                'ditangguhkan_pada' => null,
            ],
            [
                'id' => 2,
                'name' => 'Admin',
                'email' => 'admin@example.test',
                'peran' => 'admin',
                'foto_profil' => null,
                'no_hp' => null,
                'ditangguhkan_pada' => null,
            ],
        ];
        $pesanan = [
            [
                'id_pesanan' => 1,
                'kode' => 'MV-MUSEUM01',
                'id_pengguna' => 3,
                'nama_pemesan' => 'Rina',
                'email_pemesan' => 'rina@example.test',
                'hp_pemesan' => '0812',
                'tempat_slug' => 'museum-test',
                'tanggal_kunjungan' => '2026-11-25',
                'total' => 30000,
                'metode' => 'qris',
                'status' => 'lunas',
                'batas_bayar' => '2026-11-25T04:15:00+00:00',
                'created_at' => '2026-11-25T04:00:00+00:00',
                'pembayaran' => [['dibayar_pada' => '2026-11-25T04:05:00+00:00', 'referensi' => 'REF-1']],
                'e_tiket' => [['kode_qr' => 'QR-1', 'dipakai_pada' => null]],
            ],
            [
                'id_pesanan' => 2,
                'kode' => 'MV-OTHER001',
                'id_pengguna' => 4,
                'nama_pemesan' => 'Budi',
                'email_pemesan' => 'budi@example.test',
                'hp_pemesan' => '0813',
                'tempat_slug' => 'tempat-lain',
                'tanggal_kunjungan' => '2026-11-25',
                'total' => 5000,
                'metode' => 'qris',
                'status' => 'kedaluwarsa',
                'batas_bayar' => '2026-10-25T04:15:00+00:00',
                'created_at' => '2026-10-25T04:00:00+00:00',
                'pembayaran' => [['dibayar_pada' => '2026-11-24T04:05:00+00:00', 'referensi' => 'REF-2']],
                'e_tiket' => [['kode_qr' => 'QR-2', 'dipakai_pada' => null]],
            ],
            [
                'id_pesanan' => 3,
                'kode' => 'MV-PENDING01',
                'id_pengguna' => 5,
                'nama_pemesan' => 'Dina',
                'email_pemesan' => 'dina@example.test',
                'hp_pemesan' => '0814',
                'tempat_slug' => 'museum-test',
                'tanggal_kunjungan' => '2026-11-25',
                'total' => 9000,
                'metode' => 'qris',
                'status' => 'menunggu',
                'batas_bayar' => '2026-11-25T04:15:00+00:00',
                'created_at' => '2026-11-25T04:00:00+00:00',
                'pembayaran' => [],
                'e_tiket' => [],
            ],
        ];
        Http::fake(function (Request $request) use ($akun, $pesanan) {
            $url = $request->url();
            if (str_contains($url, 'sparql.test')) {
                return Http::response(['head' => ['vars' => ['s', 'p', 'o']], 'results' => ['bindings' => []]]);
            }
            if (str_contains($url, '/users?') && str_contains($url, 'email=eq.')) {
                $email = str_contains($url, 'superadmin') ? 'superadmin@example.test' : 'admin@example.test';

                return Http::response(array_values(array_filter($akun, fn (array $user) => $user['email'] === $email)));
            }
            if (str_contains($url, '/users?')) {
                return Http::response($akun);
            }
            if (str_contains($url, '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'museum-test']]);
            }
            if (str_contains($url, '/pesanan_item?')) {
                return Http::response([
                    ['id_pesanan' => 1, 'jenis_pengunjung' => 'Dewasa', 'harga' => 15000, 'jumlah' => 2, 'subtotal' => 30000],
                    ['id_pesanan' => 2, 'jenis_pengunjung' => 'Anak', 'harga' => 5000, 'jumlah' => 1, 'subtotal' => 5000],
                    ['id_pesanan' => 3, 'jenis_pengunjung' => 'Dewasa', 'harga' => 9000, 'jumlah' => 1, 'subtotal' => 9000],
                ]);
            }
            if (str_contains($url, '/pesanan?')) {
                return Http::response($pesanan);
            }

            return Http::response([], 200);
        });

        $superadminResponse = $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])
            ->get('/admin/insight?periode=7');
        $superadminResponse->assertOk()
            ->assertSee('Rp35.000')
            ->assertSee('Museum Test')
            ->assertSee('No.')
            ->assertSee('tempat-lain')
            ->assertSee('2 pesanan dibayar');
        $this->assertMatchesRegularExpression(
            '/<div class="label">Tiket terjual<\\/div><div class="nilai">3<\\/div>/',
            $superadminResponse->getContent(),
        );
        $superadminSearch = $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/insight?periode=7&q=Museum');
        $superadminSearch->assertOk()
            ->assertSee('Museum Test')
            ->assertDontSee('tempat-lain')
            ->assertSee('Rp35.000');
        $superadminRingkasan = $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin?periode=7');
        $superadminRingkasan->assertOk()->assertSee('Rp35.000 dari pembayaran tiket pada periode ini');
        $this->assertMatchesRegularExpression(
            '/<div class="label">Tiket terjual<\\/div>\\s*<div class="nilai">3<\\/div>/',
            $superadminRingkasan->getContent(),
        );

        $adminResponse = $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])
            ->get('/admin/insight?periode=7');
        $adminResponse->assertOk()
            ->assertSee('Rp30.000')
            ->assertSee('Museum Test')
            ->assertDontSee('tempat-lain')
            ->assertSee('1 pesanan dibayar');
        $this->assertMatchesRegularExpression(
            '/<div class="label">Tiket terjual<\\/div><div class="nilai">2<\\/div>/',
            $adminResponse->getContent(),
        );
        $adminRingkasan = $this->withSession(['pengguna' => [
            'id' => 2,
            'nama' => 'Admin',
            'email' => 'admin@example.test',
            'peran' => 'admin',
        ]])->get('/admin?periode=7');
        $adminRingkasan->assertOk()->assertSee('Rp30.000 dari pembayaran tiket pada periode ini');
        $this->assertMatchesRegularExpression(
            '/<div class="label">Tiket terjual<\\/div>\\s*<div class="nilai">2<\\/div>/',
            $adminRingkasan->getContent(),
        );
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
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/users?')) {
                return Http::response([[
                    'id' => 1,
                    'name' => 'Super Admin',
                    'email' => 'superadmin@example.test',
                    'peran' => 'superadmin',
                ]], 200);
            }

            return Http::response([], 200);
        });
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
            ->assertDontSee('pencarian tercatat')
            ->assertSee('href="'.route('admin.sinkronisasi').'"', false);
    }

    public function test_admin_cannot_access_the_sync_page(): void
    {
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        Http::fake(['*' => Http::response([
            'head' => ['vars' => ['s', 'p', 'o']],
            'results' => ['bindings' => []],
        ], 200)]);

        $this->withSession(['pengguna' => [
            'id' => 7,
            'nama' => 'Admin',
            'email' => '',
            'peran' => 'admin',
        ]])->get('/admin/sinkronisasi')->assertForbidden();
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
