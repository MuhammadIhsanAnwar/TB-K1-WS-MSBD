<?php

namespace Database\Seeders;

use App\Support\Katalog;
use App\Support\SupabaseApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds demo reviews, likes, and paid ticket orders.
 *
 * Run after applying database migrations:
 * php artisan db:seed --class=WisatawanUlasanSeeder
 */
class WisatawanUlasanSeeder extends Seeder
{
    private const TEKS = [
        'sejarah' => [
            'Cerita dan suasana tempat ini menarik untuk dijelajahi.',
            'Tempat ini menarik untuk mengenal sejarah setempat.',
            'Bangunan dan kisah tempat ini memberi pengalaman yang berkesan.',
        ],
        'museum' => [
            'Koleksi museum ini menarik untuk disimak.',
            'Tempat ini memberi pengalaman belajar yang menyenangkan.',
            'Informasi yang disajikan menambah wawasan.',
        ],
        'religi' => [
            'Arsitektur tempat ini menarik; pengunjung perlu menjaga ketenangan.',
            'Tempat ini nyaman dikunjungi dengan tetap menjaga ketenangan.',
            'Detail arsitekturnya menarik untuk dilihat.',
        ],
        'kuliner' => [
            'Pilihan kulinernya menarik untuk dicoba.',
            'Tempat ini cocok disinggahi saat menjelajahi pilihan kuliner Medan.',
            'Suasananya nyaman untuk menikmati hidangan.',
        ],
        'ruang-publik' => [
            'Ruang terbuka ini cocok untuk bersantai.',
            'Tempat ini menarik untuk disinggahi saat berwisata di Medan.',
            'Suasananya nyaman untuk menikmati waktu luang.',
        ],
        'belanja' => [
            'Pilihan tenant di tempat ini menarik untuk dijelajahi.',
            'Tempat ini cocok disinggahi saat berbelanja di Medan.',
            'Lokasinya menjadi pilihan menarik untuk berbelanja dan bersantai.',
        ],
        'rekreasi-air' => [
            'Tempat ini menawarkan pilihan aktivitas rekreasi yang menarik.',
            'Cocok untuk mengisi waktu liburan bersama keluarga.',
            'Suasananya menyenangkan untuk menikmati waktu bersama keluarga.',
        ],
    ];

    public function run(): void
    {
        $this->call(Wisatawan500Seeder::class);

        $emails = Wisatawan500Seeder::emailAkun();
        $akun = DB::connection('katalog')
            ->table('users')
            ->whereIn('email', $emails)
            ->where('peran', 'wisatawan')
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
        foreach (array_keys(WisatawanRatingSeeder::metrikTarget()) as $nama) {
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
            [$jumlahUlasanTempat, $ratingPerSepuluh] = WisatawanRatingSeeder::metrikTempat($data['nama'], $slug);
            $offset = self::angkaStabil($slug) % count($wisatawan);
            for ($i = 0; $i < $jumlahUlasanTempat; $i++) {
                $pengguna = $wisatawan[($offset + $i) % count($wisatawan)];
                $bintang = WisatawanRatingSeeder::bintang($ratingPerSepuluh, $i, $offset);
                $waktu = now()->subDays(($i * 13 + $offset) % 1200)->toIso8601String();

                $batchUlasan[] = [
                    'id_pengguna' => (int) $pengguna['id'],
                    'tempat_slug' => $slug,
                    'nama_pengguna' => $pengguna['name'],
                    'email_pengguna' => $pengguna['email'],
                    'bintang' => $bintang,
                    'teks' => self::teksUlasan($data['kategori'], $i + $offset),
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

            $jumlahSuka = WisatawanRatingSeeder::tempatTarget($data['nama'])
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
        $this->command?->info(
            count($tempat).' tempat diproses; '.$totalUlasan.' ulasan simulasi, '
            .$totalSuka.' suka simulasi oleh akun berperan wisatawan.'
        );
    }

    private static function angkaStabil(string $nilai): int
    {
        return (int) hexdec(substr(hash('sha256', $nilai), 0, 8));
    }

    private static function teksUlasan(string $kategori, int $indeks): string
    {
        $pilihan = self::TEKS[$kategori] ?? self::TEKS['ruang-publik'];

        return $pilihan[$indeks % count($pilihan)];
    }

}
