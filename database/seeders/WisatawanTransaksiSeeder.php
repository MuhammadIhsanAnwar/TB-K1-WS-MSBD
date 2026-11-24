<?php

namespace Database\Seeders;

use App\Support\Katalog;
use App\Support\SupabaseApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menambah transaksi tiket demo secara terpisah dari ulasan.
 * Jalankan hanya bila data transaksi demo memang diperlukan:
 * php artisan db:seed --class=WisatawanTransaksiSeeder
 */
class WisatawanTransaksiSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(Wisatawan500Seeder::class);

        $emails = Wisatawan500Seeder::emailAkun();
        $wisatawan = DB::connection('katalog')->table('users')
            ->whereIn('email', $emails)
            ->where('peran', 'wisatawan')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'no_hp'])
            ->map(fn (object $akun): array => (array) $akun)
            ->all();
        if (count($wisatawan) !== count($emails)) {
            throw new RuntimeException('Seeder transaksi hanya dapat memakai seluruh akun contoh berperan wisatawan.');
        }

        $api = new SupabaseApi('transaksi');
        $sudahAda = [];
        foreach ($api->pesananSeederDemo() as $baris) {
            foreach ($baris['pembayaran'] ?? [] as $pembayaran) {
                if (isset($pembayaran['referensi'])) {
                    $sudahAda[$pembayaran['referensi']] = true;
                }
            }
        }

        $tanggal = now('Asia/Jakarta')->toDateString();
        $jumlahBaru = 0;
        $indeksTempat = 0;
        foreach (Katalog::tempat() as $slug => $tempat) {
            $harga = Katalog::hargaTermurah($tempat);
            if ($harga === null) {
                continue;
            }

            $jumlahUntukTempat = 1 + $this->angkaStabil($slug.'-transaksi') % 3;
            for ($i = 0; $i < $jumlahUntukTempat; $i++) {
                $referensi = 'SEED-DEMO-'.substr(hash('sha256', $slug), 0, 16).'-'.($i + 1);
                if (isset($sudahAda[$referensi])) {
                    continue;
                }
                $pengguna = $wisatawan[($indeksTempat * 101 + $i) % count($wisatawan)];
                $api->buatTransaksiDemo([
                    'p_referensi' => $referensi,
                    'p_id_pengguna' => (int) $pengguna['id'],
                    'p_nama' => $pengguna['name'],
                    'p_email' => $pengguna['email'],
                    'p_hp' => $pengguna['no_hp'] ?? '081234567890',
                    'p_tempat_slug' => $slug,
                    'p_tanggal' => $tanggal,
                    'p_metode' => 'qris',
                    'p_item' => [['jenis' => 'Dewasa', 'harga' => $harga, 'jumlah' => 1]],
                ]);
                $sudahAda[$referensi] = true;
                $jumlahBaru++;
            }
            $indeksTempat++;
        }

        $this->command?->info($jumlahBaru.' transaksi tiket demo dibuat untuk akun berperan wisatawan.');
    }

    private function angkaStabil(string $nilai): int
    {
        return (int) hexdec(substr(hash('sha256', $nilai), 0, 8));
    }
}
