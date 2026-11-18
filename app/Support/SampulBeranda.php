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
        Cache::forget(self::CACHE);
    }

    /** true kalau slide beranda berasal dari pilihan super admin, bukan otomatis. */
    public static function diatur(array $semuaTempat): bool
    {
        return collect(self::pengaturan())->contains(fn ($s) => isset($semuaTempat[$s['slug']]));
    }

    /** Slide hero beranda: slug, berkas foto (untuk komponen x-foto), nama, keterangan, dan alt. */
    public static function slide(array $semuaTempat): array
    {
        // tempat yang sudah dihapus dari DB 3 dilewati, bukan membuat beranda error
        $pilihan = collect(self::pengaturan())
            ->filter(fn ($s) => isset($semuaTempat[$s['slug']]))
            ->map(fn ($s) => ['tempat' => $semuaTempat[$s['slug']], 'foto' => self::fotoAda($s['foto'])])
            ->values();

        if ($pilihan->isEmpty()) {
            $berfoto = collect($semuaTempat)->filter(fn ($t) => ! empty($t['foto']));
            $pilihan = ($berfoto->isEmpty() ? collect($semuaTempat) : $berfoto)
                ->take(self::SLOT)
                ->map(fn ($t) => ['tempat' => $t, 'foto' => null])
                ->values();
        }

        return $pilihan->map(fn ($s) => [
            'slug' => $s['tempat']['slug'],
            'berkas' => $s['foto'] ?? ($s['tempat']['foto'] ?: self::CADANGAN),
            'nama' => $s['tempat']['nama'],
            'sub' => trim(($s['tempat']['kecamatan'] ?? '').(! empty($s['tempat']['ringkas']) ? ' · '.$s['tempat']['ringkas'] : '')),
            'alt' => $s['tempat']['nama'],
        ])->all();
    }

    /** Jalur foto unggahan kalau berkasnya memang ada di folder sampul, selain itu null. */
    public static function fotoAda(?string $jalur): ?string
    {
        if (! $jalur || ! str_starts_with($jalur, self::FOLDER.'/') || str_contains($jalur, '..')) {
            return null;
        }

