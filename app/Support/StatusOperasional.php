<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StatusOperasional
{
    private const AWAL_KUNCI = 'status_operasional_';

    private const CACHE = 'medanvisit.status_operasional';

    public static function untuk(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        return self::semua()[$slug] ?? null;
    }

    public static function semua(): array
    {
        $status = Cache::get(self::CACHE);
        if (is_array($status)) {
            return $status;
        }

        try {
            $status = [];
            foreach ((new SupabaseApi)->pengaturanSitusAwalan(self::AWAL_KUNCI) as $pengaturan) {
                $nilai = $pengaturan['nilai'] ?? null;
                if (! is_array($nilai)
                    || ! is_string($nilai['slug'] ?? null)
                    || ! in_array($nilai['status'] ?? null, ['buka', 'tutup'], true)) {
                    continue;
                }
                $status[$nilai['slug']] = $nilai['status'];
            }
            Cache::put(self::CACHE, $status, now()->addMinute());
        } catch (Throwable $e) {
            Log::warning('Status operasional tempat tidak bisa dibaca dari DB 1: '.$e->getMessage());
            $status = [];
            Cache::put(self::CACHE, $status, now()->addMinute());
        }

        return $status;
    }

    public static function simpan(string $slug, string $status, ?int $idPengguna): void
    {
        (new SupabaseApi)->simpanPengaturanSitus(
            self::AWAL_KUNCI.substr(hash('sha256', $slug), 0, 40),
            ['slug' => $slug, 'status' => $status],
            $idPengguna,
        );
        Cache::forget(self::CACHE);
    }

    public static function lupakanCache(): void
    {
        Cache::forget(self::CACHE);
    }
}
