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
    public const KUNCI = 'sampul_beranda';

    public const SLOT = 4;

    /** Folder unggahan di dalam public/, sama seperti foto profil di public/storage/profil_picture. */
    public const FOLDER = 'storage/sampul_beranda';

    private const CACHE = 'medanvisit.sampul_beranda';

    private const CADANGAN = 'peta-medan-1930-kota-lama.jpg';

    /** Slot tersimpan: [['slug' => ..., 'foto' => 'storage/sampul_beranda/...' atau null], ...]. */
    public static function pengaturan(): array
    {
        $tersimpan = Cache::get(self::CACHE);
        if (is_array($tersimpan)) {
            return $tersimpan;
        }

        try {
            $nilai = (new SupabaseApi)->pengaturanSitus(self::KUNCI);
            $slot = collect(is_array($nilai) ? $nilai : [])
                ->filter(fn ($s) => is_array($s) && is_string($s['slug'] ?? null) && $s['slug'] !== '')
                ->map(fn ($s) => ['slug' => $s['slug'], 'foto' => is_string($s['foto'] ?? null) ? $s['foto'] : null])
                ->take(self::SLOT)->values()->all();
            Cache::put(self::CACHE, $slot, now()->addMinutes(10));
        } catch (Throwable $e) {
            // DB 1 tidak terjangkau: beranda tetap terbuka dengan sampul otomatis, dicoba lagi semenit kemudian
            Log::warning('Sampul beranda tidak bisa dibaca dari DB 1: '.$e->getMessage());
            $slot = [];
            Cache::put(self::CACHE, $slot, now()->addMinute());
        }

        return $slot;
    }

    public static function simpan(array $slot, ?int $idPengguna): void
    {
        (new SupabaseApi)->simpanPengaturanSitus(self::KUNCI, array_values($slot), $idPengguna);
        Cache::forget(self::CACHE);
    }

    public static function lupakanCache(): void
    {
