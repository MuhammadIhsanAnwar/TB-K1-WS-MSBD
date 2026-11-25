<?php

namespace App\Support;

final class RekomendasiTempat
{
    public static function urutkan(array $tempat): array
    {
        $sukaMaksimum = max(array_column($tempat, 'jumlahSuka') ?: [0]);
        // Penskalaan logaritmik mencegah jumlah like mendominasi skala rating 0-5.
        $logSukaMaksimum = log1p($sukaMaksimum);

        usort($tempat, static function (array $a, array $b) use ($logSukaMaksimum): int {
            $skor = static fn (array $item): float => min(5, max(0, (float) ($item['rating'] ?? 0))) / 5
                + ($logSukaMaksimum > 0
                    ? log1p(max(0, (int) ($item['jumlahSuka'] ?? 0))) / $logSukaMaksimum
                    : 0);

            return ($skor($b) <=> $skor($a))
                ?: ((float) ($b['rating'] ?? 0) <=> (float) ($a['rating'] ?? 0))
                ?: ((int) ($b['jumlahSuka'] ?? 0) <=> (int) ($a['jumlahSuka'] ?? 0))
                ?: strcmp($a['nama'] ?? '', $b['nama'] ?? '');
        });

        return $tempat;
    }
}
