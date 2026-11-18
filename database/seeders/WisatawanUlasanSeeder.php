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
