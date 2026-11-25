<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiketEtiketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00', 'Asia/Jakarta'));
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        Katalog::forgetCache();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_ticket_details_and_qr_remain_available_when_place_is_missing_from_map(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => []],
                ]);
            }

            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response([
                    [
                        'id_pesanan' => 7,
                        'jenis_pengunjung' => 'Dewasa',
                        'harga' => 15000,
                        'jumlah' => 2,
                        'subtotal' => 30000,
                    ],
                    [
                        'id_pesanan' => 7,
                        'jenis_pengunjung' => 'Hari kerja',
                        'harga' => 15000,
                        'jumlah' => 1,
                        'subtotal' => 15000,
                    ],
                ]);
            }

            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response([[
                    'id_pesanan' => 7,
                    'kode' => 'MV-AAAA1111',
                    'id_pengguna' => 12,
                    'nama_pemesan' => 'Rina',
                    'email_pemesan' => 'rina@example.test',
                    'hp_pemesan' => '081234567890',
                    'tempat_slug' => 'kolam-renang-selayang',
                    'tanggal_kunjungan' => '2026-11-25',
                    'total' => 30000,
                    'metode' => 'qris',
                    'status' => 'lunas',
                    'batas_bayar' => '2026-11-25T03:15:00+00:00',
                    'created_at' => '2026-11-25T03:00:00+00:00',
                    'pembayaran' => [['dibayar_pada' => '2026-11-25T03:05:00+00:00']],
                    'e_tiket' => [['kode_qr' => 'QR-IDENTITAS', 'dipakai_pada' => null]],
                ]]);
            }

            return Http::response([], 404);
        });

        $this->withSession(['pengguna' => [
            'id' => 12,
            'nama' => 'Rina',
            'peran' => 'wisatawan',
        ]])
            ->get('/tiket/MV-AAAA1111')
            ->assertOk()
            ->assertSee('Kolam Renang Selayang')
            ->assertSee('QR-IDENTITAS')
            ->assertSee('data-qr="QR-IDENTITAS"', false)
            ->assertSee('3 orang')
            ->assertSee('2 dewasa')
            ->assertSee('1 tiket umum')
            ->assertDontSee('hari kerja')
            ->assertSee('Kedaluwarsa setelah hari kunjungan berakhir')
            ->assertSee('Rina')
            ->assertDontSee('Belum ada di peta');

        $this->get('/tiket/MV-AAAA1111')
            ->assertOk()
            ->assertSee('Aktif, siap dipindai');

        Carbon::setTestNow(Carbon::parse('2026-11-26 00:01:00', 'Asia/Jakarta'));

        $this->get('/tiket/MV-AAAA1111')
            ->assertOk()
            ->assertSee('Kedaluwarsa')
            ->assertDontSee('Aktif, siap dipindai');
    }

    public function test_payment_page_keeps_the_midtrans_simulator_button_without_test_mode_copy(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => []],
                ]);
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
                return Http::response([[
                    'id_pesanan' => 7,
                    'kode' => 'MV-AAAA1111',
                    'id_pengguna' => 12,
                    'nama_pemesan' => 'Rina',
                    'email_pemesan' => 'rina@example.test',
                    'hp_pemesan' => '081234567890',
                    'tempat_slug' => 'kolam-renang-selayang',
                    'tanggal_kunjungan' => '2026-11-25',
                    'total' => 15000,
                    'metode' => 'qris',
                    'status' => 'menunggu',
                    'batas_bayar' => '2026-11-25T03:15:00+00:00',
                    'created_at' => '2026-11-25T03:00:00+00:00',
                    'pembayaran' => [],
                    'e_tiket' => [],
                ]]);
            }

            return Http::response([], 404);
        });

        $this->withSession(['pengguna' => [
            'id' => 12,
            'nama' => 'Rina',
            'peran' => 'wisatawan',
        ]])
            ->get('/pesanan/MV-AAAA1111')
            ->assertOk()
            ->assertSee('Buka simulator pembayaran')
            ->assertDontSee('Mode uji coba')
            ->assertDontSee('prototipe')
            ->assertDontSee('Simulasikan pembayaran berhasil');
    }

    public function test_paid_ticket_can_issue_qr_when_ticket_relation_was_not_embedded(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => []],
                ]);
            }

            if (str_contains($request->url(), '/e_tiket?')) {
                if ($request->method() === 'POST') {
                    return Http::response([[
                        'id_etiket' => 9,
                        'id_pesanan' => 7,
                        'kode_qr' => 'QR-' . strtoupper(substr(hash('sha256', 'medanvisit|MV-AAAA1111'), 0, 12)),
                    ]], 201);
                }

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
                return Http::response([[
                    'id_pesanan' => 7,
                    'kode' => 'MV-AAAA1111',
                    'id_pengguna' => 12,
                    'nama_pemesan' => 'Rina',
                    'email_pemesan' => 'rina@example.test',
                    'hp_pemesan' => '081234567890',
                    'tempat_slug' => 'kolam-renang-selayang',
                    'tanggal_kunjungan' => '2026-11-25',
                    'total' => 15000,
                    'metode' => 'qris',
                    'status' => 'lunas',
                    'batas_bayar' => '2026-11-25T03:15:00+00:00',
                    'created_at' => '2026-11-25T03:00:00+00:00',
                    'pembayaran' => [['dibayar_pada' => '2026-11-25T03:05:00+00:00']],
                    'e_tiket' => [],
                ]]);
            }

            return Http::response([], 404);
        });

        $kodeQr = 'QR-' . strtoupper(substr(hash('sha256', 'medanvisit|MV-AAAA1111'), 0, 12));

        $this->withSession(['pengguna' => [
            'id' => 12,
            'nama' => 'Rina',
            'peran' => 'wisatawan',
        ]])
            ->get('/tiket/MV-AAAA1111')
            ->assertOk()
            ->assertSee($kodeQr)
            ->assertSee('Rina');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/e_tiket?'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/e_tiket?'));
    }

    public function test_ticket_list_separates_checkins_from_expired_tickets(): void
    {
        $base = 'https://medanvisit.neoverse.my.id/';
        $place = $base.'id/kolam-renang-selayang';
        $ontology = $base.'ontologi#';
        $bindings = [
            [
                's' => ['type' => 'uri', 'value' => $place],
                'p' => ['type' => 'uri', 'value' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type'],
                'o' => ['type' => 'uri', 'value' => $ontology.'RekreasiAir'],
            ],
            [
                's' => ['type' => 'uri', 'value' => $place],
                'p' => ['type' => 'uri', 'value' => 'https://schema.org/name'],
                'o' => ['type' => 'literal', 'value' => 'Kolam Renang Selayang'],
            ],
        ];
        $buatPesanan = static function (int $id, string $kode, string $tanggal, string $status, ?string $dipakai = null): array {
            return [
                'id_pesanan' => $id,
                'kode' => $kode,
                'id_pengguna' => 12,
                'nama_pemesan' => 'Rina',
                'email_pemesan' => 'rina@example.test',
                'hp_pemesan' => '081234567890',
                'tempat_slug' => 'kolam-renang-selayang',
                'tanggal_kunjungan' => $tanggal,
                'total' => 15000,
                'metode' => 'qris',
                'status' => $status,
                'batas_bayar' => '2026-11-25T03:15:00+00:00',
                'created_at' => '2026-11-25T03:00:00+00:00',
                'pembayaran' => $status === 'lunas' ? [['dibayar_pada' => '2026-11-25T03:05:00+00:00']] : [],
                'e_tiket' => $status === 'lunas' ? [[
                    'kode_qr' => 'QR-'.$id,
                    'dipakai_pada' => $dipakai,
                ]] : [],
            ];
        };
        $orders = [
            $buatPesanan(7, 'MV-CHECKIN1', '2026-11-25', 'lunas', '2026-11-25T03:10:00+00:00'),
            $buatPesanan(8, 'MV-EXPIRED1', '2026-11-24', 'lunas'),
            $buatPesanan(9, 'MV-ACTIVE1', '2026-11-25', 'lunas'),
            $buatPesanan(10, 'MV-PAYEXPIRE', '2026-11-25', 'kedaluwarsa'),
            $buatPesanan(11, 'MV-CANCEL1', '2026-11-25', 'dibatalkan'),
        ];
        $items = array_map(fn (int $id) => [
            'id_pesanan' => $id,
            'jenis_pengunjung' => 'Dewasa',
            'harga' => 15000,
            'jumlah' => 1,
            'subtotal' => 15000,
        ], [7, 8, 9, 10, 11]);

        Http::fake(function (Request $request) use ($bindings, $orders, $items) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response($items);
            }
            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response($orders);
            }

            return Http::response([], 404);
        });

        $session = ['pengguna' => [
            'id' => 12,
            'nama' => 'Rina',
            'peran' => 'wisatawan',
        ]];

        $this->withSession($session)
            ->get('/tiket?tab=checkin')
            ->assertOk()
            ->assertSee('Sudah check-in')
            ->assertSee('MV-CHECKIN1')
            ->assertDontSee('MV-EXPIRED1')
            ->assertDontSee('MV-ACTIVE1');

        $this->withSession($session)
            ->get('/tiket?tab=kedaluwarsa')
            ->assertOk()
            ->assertSee('MV-EXPIRED1')
            ->assertSee('MV-PAYEXPIRE')
            ->assertDontSee('MV-CHECKIN1');

        $this->withSession($session)
            ->get('/tiket?tab=dibatalkan')
            ->assertOk()
            ->assertSee('MV-CANCEL1')
            ->assertDontSee('MV-PAYEXPIRE');
    }
}
