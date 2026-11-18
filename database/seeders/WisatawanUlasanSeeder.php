<?php

namespace Database\Seeders;

use App\Support\Katalog;
use App\Support\Penyimpanan;
use App\Support\SupabaseApi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Akun wisatawan contoh (DB 1) beserta ulasan dan rating untuk semua tempat wisata (DB 2).
 *
 * Akun diberi nama "Wisatawan Contoh NN" dengan email @example.com supaya jelas data contoh, bukan
 * pengunjung sungguhan. Ulasan ditulis lewat Penyimpanan::tulisUlasan (Supabase REST), jadi berlaku
 * aturan satu akun satu ulasan per tempat dan trigger ringkasan_ulasan menghitung rating. Aman
 * dijalankan berulang: akun yang sudah ada dipakai ulang dan ulasannya hanya diperbarui.
 *
 * Jalankan: php artisan db:seed --class=WisatawanUlasanSeeder   (perlu SEED_WISATAWAN_PASSWORD di .env)
 */
class WisatawanUlasanSeeder extends Seeder
{
    private const JUMLAH_AKUN = 12;

    private const TEKS = [
        'sejarah' => [
            'Bangunannya terawat dan banyak sudut menarik untuk difoto. Datang pagi supaya belum terlalu ramai.',
            'Cocok untuk mengenal sejarah Medan. Papan keterangannya membantu, walaupun sebagian masih bisa dilengkapi.',
            'Suasananya tenang dan arsitekturnya unik. Sempatkan membaca cerita tempatnya di MedanVisit sebelum datang.',
        ],
        'museum' => [
            'Koleksinya beragam dan cukup lengkap untuk kunjungan satu sampai dua jam.',
            'Bagus untuk belajar bersama anak. Beberapa ruang bisa diberi penerangan yang lebih baik.',
            'Penataan koleksinya rapi dan keterangannya mudah dipahami.',
        ],
        'religi' => [
            'Tempatnya indah dan terawat. Datang di luar jam ibadah dan berpakaian sopan.',
            'Suasananya damai, pengurusnya ramah menjelaskan sejarah bangunan.',
            'Detail bangunannya menarik. Hormati jamaah yang sedang beribadah saat berkunjung.',
        ],
        'kuliner' => [
            'Rasanya enak dan porsinya pas. Pada jam makan siang biasanya ramai, jadi datang lebih awal.',
            'Pilihan menunya banyak dan pelayanannya cepat. Layak dicoba saat berkunjung ke Medan.',
            'Tempatnya sederhana, tetapi rasanya sesuai harapan. Parkir agak terbatas.',
        ],
        'ruang-publik' => [
            'Tempat yang nyaman untuk bersantai sore bersama keluarga.',
            'Area terbukanya luas dan cukup bersih. Lebih enak dikunjungi pagi atau sore hari.',
            'Cocok untuk jalan santai dan berfoto. Bawa air minum sendiri.',
        ],
        'belanja' => [
            'Tenant-nya lengkap dan mudah dijangkau. Akhir pekan cukup ramai.',
            'Nyaman untuk belanja sambil makan. Parkirnya luas.',
            'Pilihan tokonya banyak dan tempatnya bersih.',
        ],
        'rekreasi-air' => [
            'Kolamnya bersih dan anak-anak senang. Datang pagi supaya tidak terlalu ramai.',
            'Seru untuk liburan keluarga. Ruang ganti bisa diperbanyak.',
            'Airnya jernih dan petugasnya sigap mengawasi.',
        ],
    ];

    public function run(): void
    {
        $password = env('SEED_WISATAWAN_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SEED_WISATAWAN_PASSWORD harus diisi di .env sebelum menjalankan seeder ini.');
        }

        $api = new SupabaseApi;
        $akun = [];
        for ($i = 1; $i <= self::JUMLAH_AKUN; $i++) {
            $nomor = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $email = "wisatawan.contoh{$nomor}@example.com";
            $user = $api->findUserByEmail($email) ?? $api->createUser([
                'name' => "Wisatawan Contoh {$nomor}",
                'email' => $email,
                'password' => Hash::make($password),
