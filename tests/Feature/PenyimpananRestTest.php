<?php

namespace Tests\Feature;

use App\Support\Penyimpanan;
use App\Support\SupabaseApi;
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

    public function test_reseeding_reviews_merges_rows_with_the_same_user_and_place_key(): void
    {
        Http::fake(['https://transaksi.test/*' => Http::response([])]);

        $api = new SupabaseApi('transaksi');
        $barisLama = [
            'id_pengguna' => 17,
            'tempat_slug' => 'taman-cadika',
            'bintang' => 3,
            'teks' => 'Teks ulasan lama.',
            'status' => 'tayang',
        ];
        $barisBaru = $barisLama;
        $barisBaru['bintang'] = 5;
        $barisBaru['teks'] = 'Teks ulasan terbaru.';

        $api->upsertUlasan([$barisLama]);
        $api->upsertUlasan([$barisBaru]);

        $requests = Http::recorded()->map(static fn (array $recorded) => $recorded[0])->values();
        $this->assertCount(4, $requests);
        $ratingRequests = $requests->filter(fn (Request $request) => str_contains($request->url(), '/rating_tempat?'))->values();
        $ulasanRequests = $requests->filter(fn (Request $request) => str_contains($request->url(), '/ulasan?'))->values();
        $this->assertCount(2, $ratingRequests);
        $this->assertCount(2, $ulasanRequests);
        foreach ($ulasanRequests as $request) {
            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString(
                '/ulasan?on_conflict=id_pengguna%2Ctempat_slug',
                $request->url(),
            );
            $this->assertStringContainsString('resolution=merge-duplicates', $request->header('Prefer')[0]);
        }

        $this->assertSame('Teks ulasan lama.', $ulasanRequests[0][0]['teks']);
        $this->assertSame('Teks ulasan terbaru.', $ulasanRequests[1][0]['teks']);
        $this->assertArrayNotHasKey('bintang', $ulasanRequests[1][0]);
        $this->assertSame(5, $ratingRequests[1][0]['bintang']);
    }

    public function test_idempotent_upserts_retry_connection_failures(): void
    {
        Http::fake([
            'https://transaksi.test/*' => Http::sequence()
                ->pushFailedConnection('Connection reset')
                ->push([]),
        ]);

        (new SupabaseApi('transaksi'))->upsertSukaTempat([[
            'id_pengguna' => 17,
            'tempat_slug' => 'taman-cadika',
            'created_at' => '2026-11-24T10:00:00Z',
        ]]);

        $this->assertCount(2, Http::recorded());
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

    public function test_all_orders_and_items_are_read_across_postgrest_page_limits(): void
    {
        $buatPesanan = static fn (int $id): array => [
            'id_pesanan' => $id,
            'kode' => 'MV-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            'id_pengguna' => 3,
            'nama_pemesan' => 'Rina',
            'email_pemesan' => 'rina@example.test',
            'hp_pemesan' => '0812',
            'tempat_slug' => 'istana-maimun',
            'tanggal_kunjungan' => '2026-11-20',
            'total' => 10000,
            'metode' => 'qris',
            'status' => 'lunas',
            'batas_bayar' => '2026-11-14T10:15:00+00:00',
            'created_at' => '2026-11-14T10:00:00+00:00',
            'pembayaran' => [['dibayar_pada' => '2026-11-14T10:05:00+00:00', 'referensi' => 'REF-'.$id]],
            'e_tiket' => [['kode_qr' => 'QR-'.$id, 'dipakai_pada' => null]],
        ];
        $buatItem = static fn (int $id): array => [
            'id_pesanan' => $id,
            'jenis_pengunjung' => 'Dewasa',
            'harga' => 10000,
            'jumlah' => 1,
            'subtotal' => 10000,
        ];
        Http::fake(function (Request $request) use ($buatPesanan, $buatItem) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);
            if (str_contains($request->url(), '/pesanan_item?')) {
                return Http::response(array_map($buatItem, range($offset + 1, min($offset + 500, 501))));
            }
            if (str_contains($request->url(), '/pesanan?')) {
                return Http::response(array_map($buatPesanan, range($offset + 1, min($offset + 500, 501))));
            }

            return Http::response([], 404);
        });

        $pesanan = Penyimpanan::pesanan();

        $this->assertCount(501, $pesanan);
        $this->assertSame(501, array_sum(array_map(
            static fn (array $baris): int => array_sum(array_column($baris['item'], 'jumlah')),
            $pesanan,
        )));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pesanan?')
            && str_contains($request->url(), 'offset=500'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/pesanan_item?')
            && str_contains($request->url(), 'offset=500'));
    }

    public function test_qr_lookup_filters_orders_through_an_inner_ticket_relation(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/pesanan?*' => Http::response([[
                'id_pesanan' => 7,
                'kode' => 'MV-AAAA1111',
                'e_tiket' => [['kode_qr' => 'QR-1', 'dipakai_pada' => null]],
            ]]),
        ]);

        $rows = (new SupabaseApi('transaksi'))->pesananDenganKodeQr('QR-1');

        $this->assertCount(1, $rows);
        Http::assertSent(function (Request $request): bool {
            $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

            return $request->method() === 'GET'
                && str_contains($request->url(), '/rest/v1/pesanan?')
                && str_contains($query, 'e_tiket!inner(kode_qr,dipakai_pada)')
                && str_contains($query, 'e_tiket.kode_qr=eq.QR-1');
        });
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

    public function test_demo_transaction_rpc_retries_with_a_stable_reference(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/rpc/sp_buat_transaksi_demo' => Http::sequence()
                ->pushFailedConnection('Connection reset')
                ->push('"MV-DEMO1234"'),
        ]);

        $referensi = 'SEED-DEMO-abc123-1';
        $kode = (new SupabaseApi('transaksi'))->buatTransaksiDemo([
            'p_referensi' => $referensi,
            'p_id_pengguna' => 17,
            'p_nama' => 'Rina',
            'p_email' => 'rina@example.test',
            'p_hp' => '0812',
            'p_tempat_slug' => 'taman-cadika',
            'p_tanggal' => '2026-11-24',
            'p_metode' => 'qris',
            'p_item' => [['jenis' => 'Dewasa', 'harga' => 10000, 'jumlah' => 1]],
        ]);

        $this->assertSame('MV-DEMO1234', $kode);
        $requests = Http::recorded()->map(static fn (array $recorded) => $recorded[0])->values();
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSame($referensi, $request['p_referensi']);
            $this->assertSame('POST', $request->method());
        }
    }

    public function test_ticket_usage_is_claimed_only_when_the_database_row_is_still_unused(): void
    {
        $claims = 0;
        Http::fake(function (Request $request) use (&$claims) {
            if (str_contains($request->url(), '/rpc/sp_pakai_etiket')) {
                $claims++;

                return Http::response($claims === 1 ? 'true' : 'false');
            }

            return Http::response([], 404);
        });

        $waktu = '2026-11-23T10:00:00+07:00';

        $this->assertTrue(Penyimpanan::tandaiTiketDipakai(7, $waktu));
        $this->assertFalse(Penyimpanan::tandaiTiketDipakai(7, $waktu));

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rpc/sp_pakai_etiket')
            && $request['p_id_pesanan'] === 7
            && $request['p_dipakai_pada'] === $waktu);
    }

    public function test_place_likes_are_toggled_per_user_through_database_2(): void
    {
        $reads = 0;
        Http::fake(function (Request $request) use (&$reads) {
            if ($request->method() === 'GET') {
                $reads++;

                return Http::response($reads === 1 ? [] : [['tempat_slug' => 'taman-cadika']]);
            }

            return Http::response([]);
        });

        $this->assertTrue(Penyimpanan::ubahSukaTempat(17, 'taman-cadika'));
        $this->assertFalse(Penyimpanan::ubahSukaTempat(17, 'taman-cadika'));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/suka_tempat?')
            && str_contains($request->url(), 'id_pengguna=eq.17')
            && str_contains($request->url(), 'tempat_slug=eq.taman-cadika'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/suka_tempat?on_conflict=id_pengguna%2Ctempat_slug')
            && $request[0]['id_pengguna'] === 17
            && $request[0]['tempat_slug'] === 'taman-cadika');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/suka_tempat?')
            && str_contains($request->url(), 'id_pengguna=eq.17')
            && str_contains($request->url(), 'tempat_slug=eq.taman-cadika'));
    }

    public function test_place_like_counts_are_read_from_the_database_2_summary_view(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/ringkasan_suka_tempat*' => Http::response([
                ['tempat_slug' => 'taman-cadika', 'jumlah_suka' => 12],
            ]),
        ]);

        $this->assertSame(['taman-cadika' => 12], Penyimpanan::ringkasanSukaTempat());

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/ringkasan_suka_tempat?')
            && $request->hasHeader('apikey', 'secret-key'));
    }

    public function test_place_ratings_are_read_from_the_database_2_summary_view(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/ringkasan_rating_tempat*' => Http::response([
                ['tempat_slug' => 'taman-cadika', 'rata_rata_bintang' => '4.4', 'jumlah_rating' => 6032],
            ]),
        ]);

        $this->assertSame([
            'taman-cadika' => ['rating' => 4.4, 'jumlahRating' => 6032],
        ], Penyimpanan::ringkasanRatingTempat());

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/ringkasan_rating_tempat?')
            && $request->hasHeader('apikey', 'secret-key'));
    }

    public function test_only_logged_in_tourists_can_like_a_place(): void
    {
        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf')
            ->post('/tempat/taman-cadika/sukai')
            ->assertRedirect(route('masuk', ['kembali' => '/tempat/taman-cadika/sukai']));
    }

    public function test_unreachable_database_does_not_break_pages_that_opt_into_the_safe_read(): void
    {
        Http::fake(['https://transaksi.test/*' => Http::response(['message' => 'paused'], 503)]);

        $this->assertSame([], Penyimpanan::pesanan(aman: true));
        $this->assertSame([], Penyimpanan::ringkasanUlasan());
        $this->assertSame([], Penyimpanan::ringkasanRatingTempat());
        $this->assertSame([], Penyimpanan::ringkasanSukaTempat());
    }

    public function test_reviews_are_published_immediately_and_can_be_deleted(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response([['id_ulasan' => 42]], 201);
            }
            if (str_contains($request->url(), '/ulasan?')) {
                return $request->method() === 'DELETE'
                    ? Http::response([['id_ulasan' => 42]])
                    : Http::response([]);
            }

            return Http::response([]);
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
            && $request['status'] === 'tayang'
            && ! array_key_exists('bintang', $request->data()));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rating_tempat?')
            && $request[0]['id_pengguna'] === 7
            && $request[0]['tempat_slug'] === 'istana-maimun'
            && $request[0]['bintang'] === 5);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ulasan?')
            && str_contains($request->url(), 'id_ulasan=eq.42'));
    }

    public function test_reviews_read_ratings_without_relying_on_postgrest_embedded_relations(): void
    {
        Http::fake([
            'https://transaksi.test/rest/v1/ulasan?*' => Http::response([[
                'id_ulasan' => 12,
                'id_pengguna' => 7,
                'tempat_slug' => 'istana-maimun',
                'nama_pengguna' => 'Rina',
                'email_pengguna' => 'rina@example.test',
                'teks' => 'Tempatnya menarik dan informasinya berguna.',
                'status' => 'tayang',
                'created_at' => '2026-11-15T10:00:00Z',
            ]]),
            'https://transaksi.test/rest/v1/rating_tempat?*' => Http::response([[
                'id_pengguna' => 7,
                'tempat_slug' => 'istana-maimun',
                'bintang' => 4,
            ]]),
        ]);

        $ulasan = Penyimpanan::ulasan('istana-maimun', 'tayang');

        $this->assertSame(4, $ulasan[0]['bintang']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/ulasan?')
            && ! str_contains($request->url(), 'rating_tempat'));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/rating_tempat?')
            && str_contains($request->url(), 'id_pengguna=in.%287%29')
            && str_contains($request->url(), 'tempat_slug=in.%28istana-maimun%29'));
    }

    public function test_saved_plans_are_loaded_for_the_current_user_with_normalized_relations(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/rencana?')) {
                return Http::response([[
                    'id_rencana' => 9,
                    'kode' => 'mdn-abcd',
                    'id_pengguna' => 7,
                    'judul' => 'Rute sejarah',
                    'durasi_jam' => 4,
                    'tempat_mulai_slug' => 'istana-maimun',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }
            if (str_contains($request->url(), '/rencana_minat?')) {
                return Http::response([['id_rencana' => 9, 'kode_kategori' => 'sejarah']]);
            }
            if (str_contains($request->url(), '/rencana_moda?')) {
                return Http::response([['id_rencana' => 9, 'moda' => 'jalan']]);
            }
            if (str_contains($request->url(), '/rencana_tujuan?')) {
                return Http::response([['id_rencana' => 9, 'urutan' => 1, 'tempat_slug' => 'museum-sumut']]);
            }

            return Http::response([]);
        });

        $rencana = Penyimpanan::rencana(7);

        $this->assertSame('mdn-abcd', $rencana[0]['kode']);
        $this->assertSame(['sejarah'], $rencana[0]['minat']);
        $this->assertSame(['jalan'], $rencana[0]['moda']);
        $this->assertSame(['museum-sumut'], $rencana[0]['tujuan']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/rencana?')
            && str_contains($request->url(), 'id_pengguna=eq.7'));
    }

    public function test_saved_plans_send_and_read_ordered_destinations_and_travel_modes(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/rpc/fn_simpan_rencana')) {
                return Http::response([[
                    'id_rencana' => 9,
                    'kode' => 'mdn-abcd',
                    'id_pengguna' => 7,
                    'judul' => 'Rute sejarah',
                    'durasi_jam' => 4,
                    'tempat_mulai_slug' => 'istana-maimun',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }
            if (str_contains($request->url(), '/rencana_minat?')) {
                return Http::response([['id_rencana' => 9, 'kode_kategori' => 'sejarah']]);
            }
            if (str_contains($request->url(), '/rencana_moda?')) {
                return Http::response([
                    ['id_rencana' => 9, 'moda' => 'jalan'],
                    ['id_rencana' => 9, 'moda' => 'motor'],
                    ['id_rencana' => 9, 'moda' => 'mobil'],
                ]);
            }
            if (str_contains($request->url(), '/rencana_tujuan?')) {
                return Http::response([
                    ['id_rencana' => 9, 'urutan' => 1, 'tempat_slug' => 'museum-sumut'],
                    ['id_rencana' => 9, 'urutan' => 2, 'tempat_slug' => 'taman-cadika'],
                ]);
            }

            return Http::response([]);
        });

        $rencana = Penyimpanan::simpanRencana([
            'id_pengguna' => 7,
            'judul' => 'Rute sejarah',
            'durasi' => 4,
            'moda' => ['jalan', 'motor', 'mobil'],
            'mulai' => 'istana-maimun',
            'minat' => ['sejarah'],
            'tujuan' => ['museum-sumut', 'taman-cadika'],
        ]);

        $this->assertSame(['jalan', 'motor', 'mobil'], $rencana['moda']);
        $this->assertSame(['museum-sumut', 'taman-cadika'], $rencana['tujuan']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rpc/fn_simpan_rencana')
            && $request['p_moda'] === ['jalan', 'motor', 'mobil']
            && $request['p_tujuan'] === ['museum-sumut', 'taman-cadika']);
    }

    public function test_updating_a_saved_plan_sends_owner_and_replacement_preferences(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/rpc/fn_perbarui_rencana')) {
                return Http::response([[
                    'id_rencana' => 9,
                    'kode' => 'mdn-abcd',
                    'id_pengguna' => 7,
                    'judul' => 'Rute museum',
                    'durasi_jam' => 6,
                    'tempat_mulai_slug' => 'istana-maimun',
                    'created_at' => '2026-11-15T10:00:00Z',
                ]]);
            }
            if (str_contains($request->url(), '/rencana_minat?')) {
                return Http::response([['id_rencana' => 9, 'kode_kategori' => 'museum']]);
            }
            if (str_contains($request->url(), '/rencana_moda?')) {
                return Http::response([['id_rencana' => 9, 'moda' => 'mobil']]);
            }
            if (str_contains($request->url(), '/rencana_tujuan?')) {
                return Http::response([['id_rencana' => 9, 'urutan' => 1, 'tempat_slug' => 'museum-sumut']]);
            }

            return Http::response([]);
        });

        $rencana = Penyimpanan::perbaruiRencana([
            'id_rencana' => 9,
            'id_pengguna' => 7,
            'judul' => 'Rute museum',
            'durasi' => 6,
            'moda' => ['mobil'],
            'mulai' => 'istana-maimun',
            'minat' => ['museum'],
            'tujuan' => ['museum-sumut'],
        ]);

        $this->assertSame(['museum-sumut'], $rencana['tujuan']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rpc/fn_perbarui_rencana')
            && $request['p_id_rencana'] === 9
            && $request['p_id_pengguna'] === 7
            && $request['p_tujuan'] === ['museum-sumut']);
    }

    public function test_deleting_a_saved_plan_uses_owner_scoped_rpc(): void
    {
        Http::fake(['*' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);

        $this->assertTrue(Penyimpanan::hapusRencana(9, 7));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rpc/fn_hapus_rencana')
            && $request['p_id_rencana'] === 9
            && $request['p_id_pengguna'] === 7);
    }
}
