<?php

namespace App\Support;

class Foto
{
    /** Lebar dan tinggi asli foto, supaya setiap <img> punya ukuran. */
    public static function ukuran(string $berkas): array
    {
        static $cache = [];
        if (! isset($cache[$berkas])) {
            $path = public_path(self::jalur($berkas));
            $info = is_file($path) ? @getimagesize($path) : false;
            $cache[$berkas] = $info ? [$info[0], $info[1]] : [1600, 1000];
        }

        return $cache[$berkas];
    }

    public static function url(?string $berkas): ?string
    {
        return $berkas ? asset(self::jalur($berkas)) : null;
    }

    /**
     * Semua foto tempat ada di public/storage/foto_wisata.
     * Unggahan lain (misalnya sampul beranda) sudah ditulis lengkap dengan awalan storage/.
     */
    private static function jalur(string $berkas): string
    {
        if (str_starts_with($berkas, 'storage/')) {
            return $berkas;
        }

        return 'storage/foto_wisata/'.$berkas;
    }
}
