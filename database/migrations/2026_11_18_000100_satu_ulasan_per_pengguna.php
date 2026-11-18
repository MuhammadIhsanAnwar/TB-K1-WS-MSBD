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

    public function up(): void
    {
        $db = DB::connection($this->connection);
        $ganda = $db->select('SELECT id_pengguna, tempat_slug FROM ulasan GROUP BY id_pengguna, tempat_slug HAVING count(*) > 1');
        if ($ganda) {
            throw new RuntimeException(count($ganda).' pasangan akun dan tempat punya lebih dari satu ulasan. Rapikan dulu sebelum menjalankan migration ini.');
