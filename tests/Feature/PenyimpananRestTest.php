<?php

namespace Tests\Feature;

use App\Support\Penyimpanan;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DB 2 harus dibaca/tulis lewat Supabase REST API (HTTPS), bukan koneksi PostgreSQL langsung.
 */
class PenyimpananRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
        ]);
    }

    public function test_orders_are_read_through_postgrest_with_embedded_payment_and_ticket(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/pesanan_item*' => Http::response([[
                'id_pesanan' => 7, 'jenis_pengunjung' => 'Dewasa', 'harga' => 10000, 'jumlah' => 2, 'subtotal' => 20000,
            ]]),
            'https://transaksi.test/rest/v1/pesanan?*' => Http::response([[
                'id_pesanan' => 7,
                'kode' => 'MV-AAAA1111',
                'id_pengguna' => 3,
                'nama_pemesan' => 'Rina',
                'email_pemesan' => 'rina@contoh.id',
                'hp_pemesan' => '0812',
                'tempat_slug' => 'istana-maimun',
                'tanggal_kunjungan' => '2026-11-20',
                'total' => 20000,
                'metode' => 'qris',
                'status' => 'lunas',
                'batas_bayar' => '2026-11-14T10:15:00+00:00',
                'created_at' => '2026-11-14T10:00:00+00:00',
                'pembayaran' => [['dibayar_pada' => '2026-11-14T10:05:00+00:00', 'referensi' => 'REF-1']],
                'e_tiket' => [['kode_qr' => 'QR-1', 'dipakai_pada' => null]],
            ]]),
        ]);

        $pesanan = Penyimpanan::pesanan();

        $this->assertCount(1, $pesanan);
        $this->assertSame('MV-AAAA1111', $pesanan[0]['kode']);
        $this->assertSame('istana-maimun', $pesanan[0]['tempat']);
        $this->assertSame('QR-1', $pesanan[0]['kode_qr']);
        $this->assertNotNull($pesanan[0]['dibayar']);
        $this->assertSame(20000, $pesanan[0]['item'][0]['subtotal']);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://transaksi.test/rest/v1/pesanan')
            && $r->hasHeader('apikey', 'secret-key')
            && $r->hasHeader('Authorization', 'Bearer secret-key'));
    }

    public function test_creating_an_order_calls_the_database_function_through_rpc(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/rpc/sp_buat_pesanan' => Http::response('MV-BBBB2222', 200),
            'https://transaksi.test/rest/v1/pesanan_item*' => Http::response([]),
            'https://transaksi.test/rest/v1/pesanan?*' => Http::response([[
                'id_pesanan' => 8,
                'kode' => 'MV-BBBB2222',
                'id_pengguna' => 3,
                'nama_pemesan' => 'Rina',
                'email_pemesan' => 'rina@contoh.id',
                'hp_pemesan' => '0812',
                'tempat_slug' => 'istana-maimun',
                'tanggal_kunjungan' => '2026-11-20',
                'total' => 20000,
                'metode' => 'qris',
                'status' => 'menunggu',
                'batas_bayar' => '2026-11-14T10:15:00+00:00',
                'created_at' => '2026-11-14T10:00:00+00:00',
                'pembayaran' => [],
                'e_tiket' => [],
            ]]),
        ]);

        $pesanan = Penyimpanan::buatPesanan([
            'id_pengguna' => 3,
            'pemesan' => ['nama' => 'Rina', 'email' => 'rina@contoh.id', 'hp' => '0812'],
            'tempat' => 'istana-maimun',
            'tanggal' => '2026-11-20',
            'metode' => 'qris',
            'item' => [['jenis' => 'Dewasa', 'harga' => 10000, 'jumlah' => 2]],
        ]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://transaksi.test/rest/v1/rpc/sp_buat_pesanan'
            && $r['p_tempat_slug'] === 'istana-maimun'
            && $r['p_item'][0]['jumlah'] === 2);
    }

    public function test_ticket_usage_is_claimed_only_when_the_database_row_is_still_unused(): void
    {
        $patches = 0;
        Http::fake(function (Request $request) use (&$patches) {
            if ($request->method() === 'PATCH') {
                $patches++;

                return Http::response($patches === 1 ? [['id_pesanan' => 7]] : []);
            }

            return Http::response([], 404);
        });

        $waktu = '2026-11-23T10:00:00+07:00';

        $this->assertTrue(Penyimpanan::tandaiTiketDipakai(7, $waktu));
        $this->assertFalse(Penyimpanan::tandaiTiketDipakai(7, $waktu));

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/e_tiket?')
            && str_contains($request->url(), 'dipakai_pada=is.null')
            && $request['dipakai_pada'] === $waktu);
    }

    public function test_unreachable_database_does_not_break_pages_that_opt_into_the_safe_read(): void
    {
        Http::fake(['https://transaksi.test/*' => Http::response(['message' => 'paused'], 503)]);

        $this->assertSame([], Penyimpanan::pesanan(aman: true));
        $this->assertSame([], Penyimpanan::ringkasanUlasan());
    }

    public function test_reviews_are_published_immediately_and_can_be_deleted(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response([['id_ulasan' => 42]], 201);
            }

            return Http::response([['id_ulasan' => 42]], 200);
        });

        $ulasan = Penyimpanan::tulisUlasan([
            'id_pengguna' => 7,
            'tempat' => 'istana-maimun',
            'nama' => 'Rina',
            'email' => 'rina@example.test',
            'bintang' => 5,
            'teks' => 'Tempatnya menarik dan informasinya berguna.',
        ]);
        Penyimpanan::hapusUlasan($ulasan['id']);

        $this->assertSame('42', $ulasan['id']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ulasan')
            && $request['status'] === 'tayang');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'id_ulasan=eq.42'));
    }
}
