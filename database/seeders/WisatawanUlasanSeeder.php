<?php

namespace Database\Seeders;

use App\Support\Katalog;
use App\Support\SupabaseApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds clearly labelled demo reviews, likes, and paid ticket orders.
 *
 * Run after applying database migrations:
 * php artisan db:seed --class=WisatawanUlasanSeeder
 */
class WisatawanUlasanSeeder extends Seeder
{
    private const TARGET_METRIK = [
        'Taman Cadika Pramuka' => [6032, 44],
        'Graha Maria Annai Velangkanni' => [4955, 48],
        'Masjid Agung Medan' => [4519, 48],
        'Taman Ahmad Yani' => [4481, 44],
        'Tjong A Fie Mansion' => [3656, 46],
        'Rahmat International Wildlife Museum & Gallery' => [3017, 46],
        'Museum Negeri Sumatera Utara' => [2941, 45],
        'Masjid Raya Al-Mashun' => [2221, 48],
        'Kantor Pos Besar Medan (Pos Bloc)' => [1718, 46],
        'Kuil Shri Mariamman' => [1004, 46],
    ];

    private const TEKS = [
        'sejarah' => [
            'Cerita dan suasana tempat ini menarik untuk dijelajahi.',
            'Kunjungan simulasi yang menyenangkan untuk mengenal sejarah setempat.',
            'Bangunan dan kisah tempat ini memberi pengalaman yang berkesan.',
        ],
        'museum' => [
            'Kunjungan simulasi yang menarik untuk mengenal koleksi museum.',
            'Tempat ini memberi pengalaman belajar yang menyenangkan.',
            'Koleksi dan kisah yang ditampilkan menarik untuk disimak.',
        ],
        'religi' => [
            'Arsitektur tempat ini menarik; pengunjung perlu menjaga ketenangan.',
            'Kunjungan simulasi yang berkesan dengan suasana yang tenang.',
            'Tempat ini memiliki detail arsitektur yang menarik untuk dilihat.',
        ],
        'kuliner' => [
            'Catatan simulasi: tempat ini menarik untuk dikunjungi saat wisata kuliner.',
            'Kunjungan simulasi yang menyenangkan untuk mencoba kuliner setempat.',
            'Tempat ini cocok disinggahi saat menjelajahi pilihan kuliner Medan.',
        ],
        'ruang-publik' => [
            'Kunjungan simulasi yang menyenangkan untuk menikmati ruang terbuka.',
            'Tempat ini menarik untuk disinggahi saat berwisata di Medan.',
            'Suasana ruang publik ini memberi pilihan untuk bersantai.',
        ],
        'belanja' => [
            'Kunjungan simulasi yang menyenangkan untuk melihat pilihan tenant.',
            'Tempat ini cocok disinggahi saat berbelanja di Medan.',
            'Lokasinya menjadi pilihan menarik untuk berbelanja dan bersantai.',
        ],
        'rekreasi-air' => [
            'Kunjungan simulasi yang menyenangkan untuk rekreasi bersama keluarga.',
            'Tempat ini memberi pilihan aktivitas rekreasi yang menarik.',
            'Pengalaman simulasi yang seru untuk mengisi waktu liburan.',
        ],
    ];

    public function run(): void
    {
        $this->call(Wisatawan500Seeder::class);

        $emails = Wisatawan500Seeder::emailAkun();
        $akun = DB::connection('katalog')
            ->table('users')
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'no_hp'])
            ->keyBy('email');

        if ($akun->count() !== count($emails)) {
            throw new RuntimeException('Tidak semua akun seeder ditemukan di DB 1; pastikan Wisatawan500Seeder berhasil.');
        }

        $wisatawan = array_values(array_map(
            static fn ($email) => (array) $akun->get($email),
            $emails
        ));
        unset($akun, $emails);

        $tempat = Katalog::tempat();
        if ($tempat === []) {
            throw new RuntimeException('Katalog Database 3 tidak berisi tempat wisata.');
        }

        $tempatPerNama = [];
        foreach ($tempat as $slug => $data) {
            $tempatPerNama[$data['nama']] = [$slug, $data];
        }
        foreach (array_keys(self::TARGET_METRIK) as $nama) {
            if (! isset($tempatPerNama[$nama])) {
                throw new RuntimeException("Tempat target metrik tidak ditemukan di Database 3: {$nama}");
            }
        }

        $api = new SupabaseApi('transaksi');
        $batchUlasan = [];
        $batchSuka = [];
        $totalUlasan = 0;
        $totalSuka = 0;

        foreach ($tempat as $slug => $data) {
            [$jumlahUlasanTempat, $ratingPerSepuluh] = self::TARGET_METRIK[$data['nama']]
                ?? self::metrikUmum($slug);
            $offset = self::angkaStabil($slug) % count($wisatawan);
            $teks = self::TEKS[$data['kategori']] ?? self::TEKS['ruang-publik'];

            for ($i = 0; $i < $jumlahUlasanTempat; $i++) {
                $pengguna = $wisatawan[($offset + $i) % count($wisatawan)];
                $bintang = self::bintang($ratingPerSepuluh, $i, $offset);
                $waktu = now()->subDays(($i * 13 + $offset) % 1200)->toIso8601String();

                $batchUlasan[] = [
                    'id_pengguna' => (int) $pengguna['id'],
                    'tempat_slug' => $slug,
                    'nama_pengguna' => $pengguna['name'],
                    'email_pengguna' => $pengguna['email'],
                    'bintang' => $bintang,
                    'teks' => '[SIMULASI] '.$teks[($i + $offset) % count($teks)].' Catatan contoh #'.($i + 1).'.',
                    'status' => 'tayang',
                    'created_at' => $waktu,
                    'updated_at' => $waktu,
                ];
                $totalUlasan++;

                if (count($batchUlasan) >= 200) {
                    $api->upsertUlasan($batchUlasan);
                    $batchUlasan = [];
                }
            }

            $jumlahSuka = isset(self::TARGET_METRIK[$data['nama']])
                ? min(count($wisatawan), (int) ceil($jumlahUlasanTempat * 1.25))
                : 20 + self::angkaStabil($slug.'-suka') % 481;
            $offsetSuka = self::angkaStabil($slug.'-suka-offset') % count($wisatawan);

            for ($i = 0; $i < $jumlahSuka; $i++) {
                $pengguna = $wisatawan[($offsetSuka + $i) % count($wisatawan)];
                $batchSuka[] = [
                    'id_pengguna' => (int) $pengguna['id'],
                    'tempat_slug' => $slug,
                    'created_at' => now()->toIso8601String(),
                ];
                $totalSuka++;

                if (count($batchSuka) >= 500) {
                    $api->upsertSukaTempat($batchSuka);
                    $batchSuka = [];
                }
            }
        }

        if ($batchUlasan !== []) {
            $api->upsertUlasan($batchUlasan);
        }
        if ($batchSuka !== []) {
            $api->upsertSukaTempat($batchSuka);
        }

        unset($batchUlasan, $batchSuka);
        $jumlahTransaksi = $this->buatTransaksiDemo($api, $tempat, $wisatawan);
        $this->command?->info(
            count($tempat).' tempat diproses; '.$totalUlasan.' ulasan simulasi, '
            .$totalSuka.' suka simulasi, dan '.$jumlahTransaksi.' transaksi tiket simulasi.'
        );
    }

    /** @return array{int, int} jumlah ulasan, rating target dalam skala 10 */
    private static function metrikUmum(string $slug): array
    {
        return [
            12 + self::angkaStabil($slug.'-ulasan') % 69,
            38 + self::angkaStabil($slug.'-rating') % 12,
        ];
    }

    private static function bintang(int $ratingPerSepuluh, int $indeks, int $offset): int
    {
        $bintangDasar = intdiv($ratingPerSepuluh, 10);
        $sisa = $ratingPerSepuluh % 10;

        return (($indeks * 7 + $offset) % 10) < $sisa
            ? $bintangDasar + 1
            : $bintangDasar;
    }

    private static function angkaStabil(string $nilai): int
    {
        return (int) hexdec(substr(hash('sha256', $nilai), 0, 8));
    }

    /**
     * @param  array<string, array<string, mixed>>  $tempat
     * @param  list<array<string, mixed>>  $wisatawan
     */
    private function buatTransaksiDemo(SupabaseApi $api, array $tempat, array $wisatawan): int
    {
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

        foreach ($tempat as $slug => $data) {
            $harga = Katalog::hargaTermurah($data);
            if ($harga === null) {
                continue;
            }

            $jumlahUntukTempat = 1 + self::angkaStabil($slug.'-transaksi') % 3;
            for ($i = 0; $i < $jumlahUntukTempat; $i++) {
                $referensi = 'SEED-DEMO-'.substr(hash('sha256', $slug), 0, 16).'-'.($i + 1);
                if (isset($sudahAda[$referensi])) {
                    continue;
                }

                $pengguna = $wisatawan[($indeksTempat * 101 + $i) % count($wisatawan)];
                $kode = $api->buatPesanan([
                    'p_id_pengguna' => (int) $pengguna['id'],
                    'p_nama' => $pengguna['name'],
                    'p_email' => $pengguna['email'],
                    'p_hp' => $pengguna['no_hp'] ?? '081234567890',
                    'p_tempat_slug' => $slug,
                    'p_tanggal' => $tanggal,
                    'p_metode' => 'qris',
                    'p_item' => [[
                        'jenis' => 'Dewasa',
                        'harga' => $harga,
                        'jumlah' => 1,
                    ]],
                ]);
                $api->bayarPesanan($kode, $referensi);
                $sudahAda[$referensi] = true;
                $jumlahBaru++;
            }
            $indeksTempat++;
        }

        return $jumlahBaru;
    }
}
