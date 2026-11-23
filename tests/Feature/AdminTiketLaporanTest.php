<?php

namespace Tests\Feature;

use App\Support\Katalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTiketLaporanTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();

        parent::tearDown();
    }

    public function test_superadmin_can_view_paid_ticket_sales_and_revenue_for_a_selected_place(): void
    {
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
        Katalog::forgetCache();
        config([
            'services.sparql.endpoint' => 'https://sparql.test/query',
            'services.supabase.transaksi.url' => 'https://transaksi.test',
            'services.supabase.transaksi.publishable_key' => 'pub-key',
            'services.supabase.transaksi.secret_key' => 'secret-key',
            'services.supabase.katalog.url' => 'https://katalog.test',
            'services.supabase.katalog.publishable_key' => 'pub-key',
            'services.supabase.katalog.secret_key' => 'secret-key',
        ]);

        $base = 'https://medanvisit.neoverse.my.id/';
        $ontology = $base.'ontologi#';
        $triple = static fn (string $subject, string $predicate, string $object, string $type = 'literal'): array => [
            's' => ['type' => 'uri', 'value' => $subject],
            'p' => ['type' => 'uri', 'value' => $predicate],
            'o' => ['type' => $type, 'value' => $object],
        ];
        $bindings = [];
        foreach ([
            'istana-maimun' => 'Istana Maimun',
            'museum-negeri' => 'Museum Negeri',
        ] as $slug => $name) {
            $place = $base.'id/'.$slug;
            $bindings[] = $triple($place, 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $ontology.'SitusSejarah', 'uri');
            $bindings[] = $triple($place, 'https://schema.org/name', $name);
            $bindings[] = $triple($place, 'https://schema.org/description', 'Tempat wisata.');
        }
        $offer = $base.'id/tiket-istana-maimun';
        $bindings[] = $triple($base.'id/istana-maimun', 'https://schema.org/offers', $offer, 'uri');
        $bindings[] = $triple($offer, 'https://schema.org/name', 'Dewasa');
        $bindings[] = $triple($offer, 'https://schema.org/price', '10000');

        $orders = [
            $this->order(1, 'MV-ISTANA1', 'istana-maimun', 25000, 'lunas'),
            $this->order(2, 'MV-MUSEUM1', 'museum-negeri', 40000, 'lunas'),
            $this->order(3, 'MV-UNPAID1', 'istana-maimun', 60000, 'menunggu'),
            $this->order(4, 'MV-ISTANA2', 'istana-maimun', 10000, 'lunas'),
        ];
        $items = [
            ['id_pesanan' => 1, 'jenis_pengunjung' => 'Dewasa', 'harga' => 10000, 'jumlah' => 2, 'subtotal' => 20000],
            ['id_pesanan' => 1, 'jenis_pengunjung' => 'Anak', 'harga' => 5000, 'jumlah' => 1, 'subtotal' => 5000],
            ['id_pesanan' => 2, 'jenis_pengunjung' => 'Dewasa', 'harga' => 10000, 'jumlah' => 4, 'subtotal' => 40000],
            ['id_pesanan' => 3, 'jenis_pengunjung' => 'Dewasa', 'harga' => 10000, 'jumlah' => 6, 'subtotal' => 60000],
            ['id_pesanan' => 4, 'jenis_pengunjung' => 'Dewasa', 'harga' => 10000, 'jumlah' => 1, 'subtotal' => 10000],
        ];
        Http::fake(function (Request $request) use ($bindings, $orders, $items) {
            if (str_contains($request->url(), 'sparql.test')) {
                return Http::response([
                    'head' => ['vars' => ['s', 'p', 'o']],
                    'results' => ['bindings' => $bindings],
                ]);
            }
            if (str_contains($request->url(), '/rest/v1/pesanan_item')) {
                return Http::response($items);
            }
            if (str_contains($request->url(), '/rest/v1/pesanan?')) {
                return Http::response($orders);
            }

            return Http::response([]);
        });

        $this->withSession(['pengguna' => [
            'id' => 1,
            'nama' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'peran' => 'superadmin',
        ]])->get('/admin/tiket?tempat=istana-maimun')
            ->assertOk()
            ->assertSee('Istana Maimun')
            ->assertDontSee('Museum Negeri')
            ->assertSee('2 transaksi')
            ->assertSee('<div class="nilai">4</div>', false)
            ->assertSee('<b>3</b>', false)
            ->assertSee('Rp35.000')
            ->assertSee('Dewasa')
            ->assertSee('Anak')
            ->assertSee('MV-ISTANA1')
            ->assertSee('MV-ISTANA2')
            ->assertDontSee('MV-MUSEUM1')
            ->assertDontSee('MV-UNPAID1')
            ->assertDontSee('Pembeli Rahasia')
            ->assertDontSee('Validasi di loket')
            ->assertDontSee('Harga tiket');
    }

    private function order(int $id, string $code, string $place, int $total, string $status): array
    {
        return [
            'id_pesanan' => $id,
            'kode' => $code,
            'id_pengguna' => 7,
            'nama_pemesan' => 'Pembeli Rahasia',
            'email_pemesan' => 'pembeli@example.test',
            'hp_pemesan' => '08123456789',
            'tempat_slug' => $place,
            'tanggal_kunjungan' => '2026-11-25',
            'total' => $total,
            'metode' => 'qris',
            'status' => $status,
            'batas_bayar' => '2026-11-24T10:15:00+00:00',
            'created_at' => '2026-11-24T10:00:00+00:00',
            'pembayaran' => $status === 'lunas' ? [['dibayar_pada' => '2026-11-24T10:05:00+00:00', 'referensi' => 'REF-'.$id]] : [],
            'e_tiket' => $status === 'lunas' ? [['kode_qr' => 'QR-'.$id, 'dipakai_pada' => null]] : [],
        ];
    }
}
