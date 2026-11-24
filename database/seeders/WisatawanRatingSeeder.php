<?php

namespace Database\Seeders;

use App\Support\Katalog;
use App\Support\SupabaseApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds one simulated rating per user and place into DB 2.
 *
 * Run after applying database migrations:
 * php artisan db:seed --class=WisatawanRatingSeeder
 */
class WisatawanRatingSeeder extends Seeder
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

    public function run(): void
    {
        $this->call(Wisatawan500Seeder::class);

        $emails = Wisatawan500Seeder::emailAkun();
        $akun = DB::connection('katalog')
            ->table('users')
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
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

        $namaTersedia = array_column($tempat, 'nama');
        foreach (array_keys(self::TARGET_METRIK) as $nama) {
            if (! in_array($nama, $namaTersedia, true)) {
                throw new RuntimeException("Tempat target rating tidak ditemukan di Database 3: {$nama}");
            }
        }

        $jumlahRating = self::seedRatingTempat(new SupabaseApi('transaksi'), $tempat, $wisatawan);
        $this->command?->info(count($tempat).' tempat diproses; '.$jumlahRating.' rating simulasi disimpan di DB 2.');
    }

    /** @return array{int, int} jumlah penilai, target rating dalam skala 10 */
    public static function metrikTempat(string $nama, string $slug): array
    {
        return self::TARGET_METRIK[$nama] ?? [
            12 + self::angkaStabil($slug.'-ulasan') % 69,
            38 + self::angkaStabil($slug.'-rating') % 12,
        ];
    }

    public static function tempatTarget(string $nama): bool
    {
        return isset(self::TARGET_METRIK[$nama]);
    }

    /** @return array<string, array{int, int}> */
    public static function metrikTarget(): array
    {
        return self::TARGET_METRIK;
    }

    public static function bintang(int $ratingPerSepuluh, int $indeks, int $offset): int
    {
        $bintangDasar = intdiv($ratingPerSepuluh, 10);
        $sisa = $ratingPerSepuluh % 10;

        return (($indeks * 7 + $offset) % 10) < $sisa
            ? $bintangDasar + 1
            : $bintangDasar;
    }

    /**
     * @param  array<string, array<string, mixed>>  $tempat
     * @param  list<array<string, mixed>>  $wisatawan
     */
    public static function seedRatingTempat(SupabaseApi $api, array $tempat, array $wisatawan): int
    {
        if ($wisatawan === []) {
            throw new RuntimeException('Tidak ada akun wisatawan untuk mengisi rating tempat.');
        }

        $jumlahRatingTotal = 0;
        $batch = [];
        $waktu = now()->toIso8601String();

        foreach ($tempat as $slug => $data) {
            [$jumlahRating, $ratingPerSepuluh] = self::metrikTempat($data['nama'], $slug);
            $offset = self::angkaStabil($slug) % count($wisatawan);

            for ($i = 0; $i < $jumlahRating; $i++) {
                $pengguna = $wisatawan[($offset + $i) % count($wisatawan)];
                $batch[] = [
                    'id_pengguna' => (int) $pengguna['id'],
                    'tempat_slug' => $slug,
                    'bintang' => self::bintang($ratingPerSepuluh, $i, $offset),
                    'created_at' => $waktu,
                    'updated_at' => $waktu,
                ];
                $jumlahRatingTotal++;

                if (count($batch) >= 500) {
                    $api->upsertRatingTempat($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $api->upsertRatingTempat($batch);
        }

        return $jumlahRatingTotal;
    }

    private static function angkaStabil(string $nilai): int
    {
        return (int) hexdec(substr(hash('sha256', $nilai), 0, 8));
    }
}
