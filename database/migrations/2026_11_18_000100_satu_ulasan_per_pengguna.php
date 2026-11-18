<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * DB 2 (koneksi "transaksi"): satu akun hanya punya satu ulasan untuk setiap tempat.
 * Kalau wisatawan mengirim ulasan lagi, ulasan lamanya diperbarui, sehingga rating di
 * ringkasan_ulasan tidak bisa digeser dengan mengirim ulasan berkali-kali.
 */
return new class extends Migration
{
    protected $connection = 'transaksi';
