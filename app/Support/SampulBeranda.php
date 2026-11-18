<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Foto sampul (hero) beranda yang diatur super admin. Tersimpan di DB 1 tabel pengaturan_situs
 * dengan kunci sampul_beranda: daftar slot berisi slug tempat wisata (DB 3) dan, kalau ada,
 * foto unggahan pengganti foto tempat. Tanpa pengaturan, atau kalau DB 1 tidak terjangkau,
 * beranda memakai empat tempat pertama yang punya foto.
 */
class SampulBeranda
{
