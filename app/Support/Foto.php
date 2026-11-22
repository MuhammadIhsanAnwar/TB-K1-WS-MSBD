<?php

namespace App\Support;

class Foto
{
    /** Lebar dan tinggi asli foto, supaya setiap <img> punya ukuran. */
    public static function ukuran(string $berkas): array
    {
        static $cache = [];
        if (! isset($cache[$berkas])) {
            $path = self::jalurFisik($berkas);
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
     * Foto katalog ada di storage/poto, thumbnail di storage/foto-kecil.
     * Unggahan lain sudah ditulis lengkap dengan awalan storage/.
     */
    private static function jalur(string $berkas): string
    {
        if (str_starts_with($berkas, 'storage/')) {
            return $berkas;
        }

        if (str_starts_with($berkas, 'foto-kecil/')) {
            return 'storage/'.$berkas;
        }

        return 'storage/poto/'.$berkas;
    }

    /** Berkas fisiknya di storage/app/public, bukan di public/. */
    private static function jalurFisik(string $berkas): string
    {
        if (str_starts_with($berkas, 'storage/')) {
            return storage_path('app/public/'.substr($berkas, strlen('storage/')));
        }

        if (str_starts_with($berkas, 'foto-kecil/')) {
            return storage_path('app/public/'.$berkas);
        }

        return storage_path('app/public/poto/'.$berkas);
    }
}
