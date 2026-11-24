<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminScanTiketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
        ])
            ->from('/admin/tiket')
            ->post('/admin/tiket/validasi', ['kode' => 'QR-IDENTITAS'])
            ->assertRedirect('/admin/tiket')
            ->assertSessionHas('hasil.status', 'dipakai');

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
        ])
            ->from('/admin/tiket')
            ->post('/admin/tiket/validasi', ['kode' => 'QR-IDENTITAS'])
            ->assertRedirect('/admin/tiket')
            ->assertSessionHas('hasil.status', 'kedaluwarsa');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/e_tiket?'));
    }

    private function pesanan(string $tanggal, ?string $dipakai = null): array
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
            'pembayaran' => [['dibayar_pada' => '2026-11-23T03:05:00+00:00']],
            'e_tiket' => [[
                'kode_qr' => 'QR-IDENTITAS',
                'dipakai_pada' => $dipakai,
            ]],
        ];
    }
}
