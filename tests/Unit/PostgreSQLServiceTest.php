<?php

namespace Tests\Unit;

use App\Services\PostgreSQLService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostgreSQLServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.secret_key' => 'test-secret',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.secret_key' => 'test-secret',
        ]);
    }

    public function test_popularity_summary_filters_by_slug_using_database_2_view(): void
    {
        $summary = [
            'tempat_slug' => 'istana-maimun',
            'jumlah_ulasan' => 9,
            'rata_rata_ulasan' => '4.7',
            'jumlah_rating' => 12,
            'rata_rata_bintang' => '4.6',
            'jumlah_suka' => 5,
        ];
        Http::fake([
            'https://transaksi.test/rest/v1/ringkasan_popularitas_tempat*' => Http::response([$summary]),
        ]);

        $this->assertSame($summary, app(PostgreSQLService::class)->ringkasanPopularitasTempat('istana-maimun'));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/ringkasan_popularitas_tempat?')
            && $this->queryParameters($request)['tempat_slug'] === 'eq.istana-maimun'
            && $this->queryParameters($request)['limit'] === '1');
    }

    public function test_review_ranking_uses_window_rank_and_caps_the_limit(): void
    {
        $rows = [[
            'tempat_slug' => 'istana-maimun',
            'jumlah_ulasan' => 17,
            'rata_rata_ulasan' => '4.8',
            'jumlah_rating' => 20,
            'rata_rata_bintang' => '4.7',
            'peringkat_ulasan' => 1,
        ]];
        Http::fake([
            'https://transaksi.test/rest/v1/ringkasan_popularitas_tempat*' => Http::response($rows),
        ]);

        $this->assertSame($rows, app(PostgreSQLService::class)->peringkatUlasan(500));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($this->queryParameters($request)['select'], 'peringkat_ulasan')
            && $this->queryParameters($request)['jumlah_ulasan'] === 'gt.0'
            && $this->queryParameters($request)['order'] === 'peringkat_ulasan.asc,tempat_slug.asc'
            && $this->queryParameters($request)['limit'] === '100');
    }

    public function test_like_ranking_is_sorted_in_database_and_bounded(): void
    {
        $rows = [['tempat_slug' => 'taman-cadika', 'jumlah_suka' => 13, 'peringkat_suka' => 1]];
        Http::fake([
            'https://transaksi.test/rest/v1/ringkasan_popularitas_tempat*' => Http::response($rows),
        ]);

        $this->assertSame($rows, app(PostgreSQLService::class)->peringkatSuka(5000));
        Http::assertSent(fn (Request $request) => $this->queryParameters($request)['order'] === 'peringkat_suka.asc,tempat_slug.asc'
            && $this->queryParameters($request)['jumlah_suka'] === 'gt.0'
            && $this->queryParameters($request)['limit'] === '1000');
    }

    public function test_order_count_reads_exact_count_for_the_bound_user(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/pesanan*' => Http::response([], 200, ['Content-Range' => '0-0/12']),
        ]);

        $this->assertSame(12, app(PostgreSQLService::class)->jumlahPesanan(42));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $this->queryParameters($request)['id_pengguna'] === 'eq.42'
            && $request->hasHeader('Prefer', 'count=exact')
            && $request->hasHeader('Range', '0-0'));
    }

    public function test_profile_lookup_uses_the_database_1_account_endpoint(): void
    {
        $profile = ['id' => 42, 'name' => 'Pengguna', 'email' => 'user@example.test', 'peran' => 'wisatawan'];
        Http::fake([
            'https://katalog.test/rest/v1/users*' => Http::response([$profile]),
        ]);

        $this->assertSame($profile, app(PostgreSQLService::class)->profilPengguna(42));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/users?')
            && $this->queryParameters($request)['id'] === 'eq.42'
            && $this->queryParameters($request)['select'] === 'id,name,email,peran');
    }

    public function test_order_history_uses_bound_user_filter_and_capped_limit(): void
    {
        $orders = [[
            'kode' => 'MV-DEMO',
            'tempat_slug' => 'istana-maimun',
            'tanggal_kunjungan' => '2026-11-25',
            'total' => 10000,
            'status' => 'lunas',
            'metode' => 'qris',
            'created_at' => '2026-11-25T10:00:00+07:00',
        ]];
        Http::fake([
            'https://transaksi.test/rest/v1/pesanan*' => Http::response($orders),
        ]);

        $this->assertSame($orders, app(PostgreSQLService::class)->riwayatPesanan(42, 500));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pesanan?')
            && $this->queryParameters($request)['id_pengguna'] === 'eq.42'
            && $this->queryParameters($request)['limit'] === '20'
            && $this->queryParameters($request)['order'] === 'created_at.desc,id_pesanan.desc');
    }

    private function queryParameters(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
