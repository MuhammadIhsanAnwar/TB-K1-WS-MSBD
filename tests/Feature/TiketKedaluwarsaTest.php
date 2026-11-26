<?php

namespace Tests\Feature;

use App\Support\Katalog;
use App\Support\TiketKedaluwarsa;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class TiketKedaluwarsaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        config([
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-23 17:00:00', 'Asia/Jakarta'));
        Katalog::forgetCache();
        (new ReflectionProperty(Katalog::class, 'entities'))->setValue(null, [
            'medan-zoo' => [
                'slug' => 'medan-zoo',
                'jenis' => 'tempat',
                'nama' => 'Medan Zoo',
                'jam' => 'Setiap hari, 09.00-17.00',
                'jam_menit' => [540, 1020],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_paid_unused_ticket_expires_at_the_venue_closing_time(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket')
            ? Http::response('true')
            : Http::response([]));

        $pesanan = TiketKedaluwarsa::perbarui($this->pesanan());

        $this->assertSame('kedaluwarsa', $pesanan['status']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket')
            && $request['p_id_pesanan'] === 7);
    }

    public function test_ticket_remains_active_before_closing_and_a_checked_in_ticket_is_never_expired(): void
    {
        Http::fake(fn () => Http::response([]));

        Carbon::setTestNow(Carbon::parse('2026-11-23 16:59:59', 'Asia/Jakarta'));
        $aktif = TiketKedaluwarsa::perbarui($this->pesanan());
        $this->assertSame('lunas', $aktif['status']);

        Carbon::setTestNow(Carbon::parse('2026-11-23 17:00:00', 'Asia/Jakarta'));
        $dipakai = TiketKedaluwarsa::perbarui($this->pesanan([
            'dipakai' => '2026-11-23T16:30:00+07:00',
        ]));
        $this->assertSame('lunas', $dipakai['status']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket'));
    }

    public function test_manual_open_override_moves_expiry_to_the_end_of_the_next_day(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket')
            ? Http::response('true')
            : Http::response([]));
        Cache::put('medanvisit.status_operasional', ['medan-zoo' => 'buka'], now()->addDay());

        $pesanan = $this->pesanan();
        $this->assertSame(
            '2026-11-24 23:59:59',
            TiketKedaluwarsa::berlakuSampai($pesanan)->format('Y-m-d H:i:s'),
        );
        $this->assertSame('lunas', TiketKedaluwarsa::perbarui($pesanan)['status']);

        Carbon::setTestNow(Carbon::parse('2026-11-24 23:59:59', 'Asia/Jakarta'));
        $kedaluwarsa = TiketKedaluwarsa::perbarui($pesanan);

        $this->assertSame('kedaluwarsa', $kedaluwarsa['status']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket'));
    }

    public function test_scheduled_expiry_reads_only_paid_tickets_without_checkin(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/pesanan?')) {
                return Http::response([[
                    'id_pesanan' => 7,
                    'kode' => 'MV-AAAA1111',
                    'tempat_slug' => 'medan-zoo',
                    'tanggal_kunjungan' => '2026-11-23',
                    'e_tiket' => [['dipakai_pada' => null]],
                ]]);
            }
            if (str_contains($request->url(), '/rpc/sp_kedaluwarsakan_etiket')) {
                return Http::response('true');
            }

            return Http::response([]);
        });

        $this->assertSame(1, TiketKedaluwarsa::perbaruiTerjadwal());
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/pesanan?')
            && str_contains($request->url(), 'status=eq.lunas')
            && str_contains($request->url(), 'e_tiket.dipakai_pada=is.null'));
    }

    private function pesanan(array $ubah = []): array
    {
        return array_replace([
            'id' => '7',
            'kode' => 'MV-AAAA1111',
            'tempat' => 'medan-zoo',
            'tanggal' => '2026-11-23',
            'status' => 'lunas',
            'dipakai' => null,
        ], $ubah);
    }
}
