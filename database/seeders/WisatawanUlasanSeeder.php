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
