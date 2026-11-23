<?php

namespace Database\Seeders;

use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Membuat 500 akun wisatawan contoh di database katalog.
 *
 * Atur SEED_WISATAWAN_PASSWORD di environment, lalu jalankan:
 * php artisan db:seed --class=Wisatawan500Seeder
 *
 * Seeder ini sengaja tidak dipanggil dari DatabaseSeeder agar akun contoh tidak
 * otomatis dibuat pada setiap proses deployment. Data deterministik dan aman
 * dijalankan ulang; akun email yang sudah ada tidak ditimpa.
 */
class Wisatawan500Seeder extends Seeder
{
    private const JUMLAH_AKUN = 500;
    private const DOMAIN = 'medanvisit.neoverse.my.id';
    private const NOMOR_TELEPON = '081234567890';
    private const RANDOM_SEED = 20261124;

    private const NAMA_DEPAN = [
        'Aditya', 'Agung', 'Ahmad', 'Aldi', 'Anisa', 'Arif', 'Ayu', 'Bagas',
        'Bima', 'Citra', 'Dimas', 'Dwi', 'Eka', 'Fajar', 'Farhan', 'Fitri',
        'Hana', 'Indra', 'Intan', 'Joko', 'Maya', 'Nadia', 'Nanda', 'Putri',
        'Rani', 'Rendra', 'Rizky', 'Sari', 'Satria', 'Wulan',
    ];

    private const NAMA_BELAKANG = [
        'Abimanyu', 'Adinata', 'Akbar', 'Anggraini', 'Ananda', 'Aulia',
        'Baskoro', 'Cahyadi', 'Damayanti', 'Dewi', 'Firmansyah', 'Gunawan',
        'Hapsari', 'Hartono', 'Iskandar', 'Kurniawan', 'Lestari', 'Mahendra',
        'Maulana', 'Nugraha', 'Permata', 'Pratama', 'Ramadhan', 'Saputra',
        'Santoso', 'Setiawan', 'Siregar', 'Wibowo', 'Wijaya', 'Yudhistira',
    ];

    public function run(): void
    {
        $password = env('SEED_WISATAWAN_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SEED_WISATAWAN_PASSWORD harus diatur sebelum menjalankan seeder ini.');
        }

        $akun = $this->buatAkun($password);
        $koneksi = DB::connection('katalog');

        $jumlahBaru = $koneksi->transaction(function () use ($akun, $password, $koneksi): int {
            $existentes = $koneksi->table('users')
                ->whereIn('email', array_column($akun, 'email'))
                ->get(['email', 'name', 'peran', 'no_hp', 'tanggal_lahir', 'password'])
                ->keyBy('email');

            $baru = [];
            foreach ($akun as $baris) {
                $sudahAda = $existentes->get($baris['email']);
                if ($sudahAda === null) {
                    $baru[] = $baris;
                    continue;
                }

                $tanggalLahir = substr((string) $sudahAda->tanggal_lahir, 0, 10);
                if (
                    $sudahAda->name !== $baris['name']
                    || $sudahAda->peran !== 'wisatawan'
                    || $sudahAda->no_hp !== self::NOMOR_TELEPON
                    || $tanggalLahir !== $baris['tanggal_lahir']
                    || ! Hash::check($password, $sudahAda->password)
                ) {
                    throw new RuntimeException(
                        "Email {$baris['email']} sudah digunakan oleh akun yang berbeda; tidak ada akun yang ditimpa."
                    );
                }
            }

            foreach (array_chunk($baru, 100) as $kelompok) {
                $koneksi->table('users')->insert($kelompok);
            }

            return count($baru);
        });

        $sudahAda = self::JUMLAH_AKUN - $jumlahBaru;
        $this->command?->info("{$jumlahBaru} akun baru dibuat; {$sudahAda} akun yang sudah sesuai dipertahankan.");
    }

    /**
     * @return list<array<string, string>>
     */
    private function buatAkun(string $password): array
    {
        $random = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $nama = [];
        foreach (self::NAMA_DEPAN as $depan) {
            foreach (self::NAMA_BELAKANG as $belakang) {
                $nama[] = $depan.' '.$belakang;
            }
        }

        for ($i = count($nama) - 1; $i > 0; $i--) {
            $j = $random->getInt(0, $i);
            [$nama[$i], $nama[$j]] = [$nama[$j], $nama[$i]];
        }

        if (count($nama) < self::JUMLAH_AKUN) {
            throw new RuntimeException('Kombinasi nama belum cukup untuk membuat 500 akun unik.');
        }

        $tanggalAwal = new DateTimeImmutable('1955-01-01');
        $tanggalAkhir = new DateTimeImmutable('2008-01-01');
        $rentangHari = (int) $tanggalAwal->diff($tanggalAkhir)->format('%a');
        $waktu = now()->toDateTimeString();
        $akun = [];

        foreach (array_slice($nama, 0, self::JUMLAH_AKUN) as $namaLengkap) {
            $slugNama = Str::slug($namaLengkap, '.');
            $tanggalLahir = $tanggalAwal
                ->modify('+'.$random->getInt(0, $rentangHari - 1).' days')
                ->format('Y-m-d');

            $akun[] = [
                'name' => $namaLengkap,
                'email' => $slugNama.'@'.self::DOMAIN,
                'no_hp' => self::NOMOR_TELEPON,
                'tanggal_lahir' => $tanggalLahir,
                'password' => Hash::make($password),
                'peran' => 'wisatawan',
                'email_verified_at' => $waktu,
                'created_at' => $waktu,
                'updated_at' => $waktu,
            ];
        }

        return $akun;
    }
}
