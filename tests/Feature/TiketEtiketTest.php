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
                return Http::response([[
                    'id_pesanan' => 7,
                    'jenis_pengunjung' => 'Dewasa',
                    'harga' => 15000,
                    'jumlah' => 2,
                    'subtotal' => 30000,
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
            ->assertSee('2 dewasa')
            ->assertSee('Rina')
            ->assertDontSee('Belum ada di peta');
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
}
