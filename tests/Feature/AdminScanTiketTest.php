<?php

namespace Tests\Feature;

use App\Support\Kunjungan;
use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminScanTiketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'Asia/Jakarta'));
        config([
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_qr_scan_that_loses_the_atomic_claim_is_reported_as_already_used(): void
    {
        $reads = 0;
        Http::fake(function (Request $request) use (&$reads) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'medan-zoo']]);
            }

            if ($request->method() === 'PATCH' && str_contains($request->url(), '/e_tiket?')) {
                return Http::response([]);
            }

            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response([[
                    'id_pesanan' => 7,
                    'jenis_pengunjung' => 'Dewasa',
                    'harga' => 15000,
                    'jumlah' => 1,
                    'subtotal' => 15000,
                ]]);
            }

            if (str_contains($request->url(), '/pesanan?')) {
                $reads++;

                return Http::response([$this->pesanan('2026-11-23', $reads > 1 ? '2026-11-23T03:10:00+00:00' : null)]);
            }

            return Http::response([], 404);
        });

        $this->withSession([
            'pengguna' => [
                'id' => 3,
                'peran' => 'admin',
                'tempat_slugs' => ['medan-zoo'],
            ],
            '_token' => 'test-csrf',
        ])->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->from('/admin/tiket')
            ->post('/admin/tiket/validasi', ['kode' => 'QR-IDENTITAS'])
            ->assertRedirect('/admin/tiket')
            ->assertSessionHas('hasil.status', 'dipakai')
            ->assertSessionHas('hasil.judul', 'Sudah check-in');

        $this->assertSame([], Kunjungan::ringkasanCheckinTerbaru());
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/e_tiket?')
            && str_contains($request->url(), 'dipakai_pada=is.null'));
    }

    public function test_expired_ticket_qr_is_rejected_without_claiming_it(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'medan-zoo']]);
            }

            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response([[
                    'id_pesanan' => 7,
                    'jenis_pengunjung' => 'Dewasa',
                    'harga' => 15000,
                    'jumlah' => 1,
                    'subtotal' => 15000,
                ]]);
            }

            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response([$this->pesanan('2026-11-22')]);
            }

            return Http::response([], 404);
        });

        $this->withSession([
            'pengguna' => [
                'id' => 3,
                'peran' => 'admin',
                'tempat_slugs' => ['medan-zoo'],
            ],
            '_token' => 'test-csrf',
        ])->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->from('/admin/tiket')
            ->post('/admin/tiket/validasi', ['kode' => 'QR-IDENTITAS'])
            ->assertRedirect('/admin/tiket')
            ->assertSessionHas('hasil.status', 'kedaluwarsa')
            ->assertSessionHas('hasil.judul', 'Tiket kedaluwarsa');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/e_tiket?'));
    }

    public function test_order_code_scan_repairs_missing_qr_and_counts_all_included_visitors_once(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'medan-zoo']]);
            }

            if (str_contains($request->url(), '/e_tiket?')) {
                if ($request->method() === 'POST') {
                    return Http::response([[
                        'id_etiket' => 9,
                        'id_pesanan' => 7,
                        'kode_qr' => 'QR-NEWIDENTITY',
                    ]], 201);
                }

                if ($request->method() === 'PATCH') {
                    return Http::response([['id_pesanan' => 7, 'dipakai_pada' => '2026-11-23T03:00:00+00:00']]);
                }

                return Http::response([]);
            }

            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response([
                    ['id_pesanan' => 7, 'jenis_pengunjung' => 'Dewasa', 'harga' => 15000, 'jumlah' => 2, 'subtotal' => 30000],
                    ['id_pesanan' => 7, 'jenis_pengunjung' => 'Anak', 'harga' => 8000, 'jumlah' => 1, 'subtotal' => 8000],
                ]);
            }

            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response([$this->pesanan('2026-11-23', kodeQr: null)]);
            }

            return Http::response([], 404);
        });

        $this->withSession([
            'pengguna' => [
                'id' => 3,
                'peran' => 'admin',
                'tempat_slugs' => ['medan-zoo'],
            ],
            '_token' => 'test-csrf',
        ])->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->from('/admin/tiket')
            ->post('/admin/tiket/validasi', ['kode' => 'MV-AAAA1111'])
            ->assertRedirect('/admin/tiket')
            ->assertSessionHas('hasil.status', 'valid')
            ->assertSessionHas('hasil.judul', 'Valid dan sudah check-in')
            ->assertSessionHas('hasil.teks', fn (string $teks) => str_contains($teks, '3 pengunjung'));

        $this->assertSame(3, Kunjungan::ringkasanCheckinTerbaru()['medan-zoo']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/e_tiket?'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/e_tiket?')
            && str_contains($request->url(), 'dipakai_pada=is.null'));
    }

    public function test_admin_ticket_list_shows_scanned_ticket_as_checked_in(): void
    {
        Katalog::forgetCache();
        config(['services.sparql.endpoint' => 'https://sparql.test/query']);
        $place = 'https://medanvisit.neoverse.my.id/id/medan-zoo';
        $bindings = [
            [
                's' => ['type' => 'uri', 'value' => $place],
                'p' => ['type' => 'uri', 'value' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'],
                'o' => ['type' => 'uri', 'value' => 'https://medanvisit.neoverse.my.id/ontologi#RuangPublik'],
            ],
            [
                's' => ['type' => 'uri', 'value' => $place],
                'p' => ['type' => 'uri', 'value' => 'https://schema.org/name'],
                'o' => ['type' => 'literal', 'value' => 'Medan Zoo'],
            ],
        ];

        Http::fake(function (Request $request) use ($bindings) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/admin_tempat?')) {
                return Http::response([['tempat_slug' => 'medan-zoo']]);
            }
            if (str_contains($request->url(), '/users?')) {
                return Http::response([[
                    'id' => 3,
                    'name' => 'Admin',
                    'email' => 'admin@example.test',
                    'peran' => 'admin',
                    'no_hp' => null,
                    'ditangguhkan_pada' => null,
                ]]);
            }
            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response([[
                    'id_pesanan' => 7,
                    'jenis_pengunjung' => 'Dewasa',
                    'harga' => 15000,
                    'jumlah' => 1,
                    'subtotal' => 15000,
                ]]);
            }
            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response([$this->pesanan('2026-11-23', '2026-11-23T03:10:00+00:00')]);
            }

            return Http::response([], 404);
        });

        $this->withSession([
            'pengguna' => [
                'id' => 3,
                'nama' => 'Admin',
                'email' => 'admin@example.test',
                'peran' => 'admin',
                'tempat_slugs' => ['medan-zoo'],
            ],
        ])
            ->get('/admin/tiket')
            ->assertOk()
            ->assertSee('MV-AAAA1111')
            ->assertSee('Sudah check-in');
    }

    private function pesanan(string $tanggal, ?string $dipakai = null, ?string $kodeQr = 'QR-IDENTITAS'): array
    {
        return [
            'id_pesanan' => 7,
            'kode' => 'MV-AAAA1111',
            'id_pengguna' => 12,
            'nama_pemesan' => 'Rina',
            'email_pemesan' => 'rina@example.test',
            'hp_pemesan' => '081234567890',
            'tempat_slug' => 'medan-zoo',
            'tanggal_kunjungan' => $tanggal,
            'total' => 15000,
            'metode' => 'qris',
            'status' => 'lunas',
            'batas_bayar' => '2026-11-23T03:15:00+00:00',
            'created_at' => '2026-11-23T03:00:00+00:00',
            'pembayaran' => ['dibayar_pada' => '2026-11-23T03:05:00+00:00'],
            'e_tiket' => $kodeQr === null ? [] : [
                'kode_qr' => $kodeQr,
                'dipakai_pada' => $dipakai,
            ],
        ];
    }
}
