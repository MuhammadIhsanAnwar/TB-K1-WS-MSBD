<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PostgreSQLService
{
    public function ringkasanUlasanTempat(string $slug): ?array
    {
        $baris = DB::connection('transaksi')->select(
            'SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
             FROM ringkasan_ulasan
             WHERE tempat_slug = ?
             LIMIT 1',
            [$slug],
        );

        return isset($baris[0]) ? (array) $baris[0] : null;
    }

    public function peringkatUlasan(int $batas = 5): array
    {
        $batas = max(1, min($batas, 100));

        return array_map(
            fn ($baris) => (array) $baris,
            DB::connection('transaksi')->select(
                'SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
                 FROM ringkasan_ulasan
                 WHERE jumlah_ulasan > 0
                 ORDER BY jumlah_ulasan DESC, rata_rata_bintang DESC NULLS LAST
                 LIMIT ?',
                [$batas],
            ),
        );
    }

    public function jumlahPesanan(int $idPengguna): int
    {
        $baris = DB::connection('transaksi')->select(
            'SELECT COUNT(*) AS jumlah FROM pesanan WHERE id_pengguna = ?',
            [$idPengguna],
        );

        return (int) ($baris[0]->jumlah ?? 0);
    }

    public function profilPengguna(int $idPengguna): ?array
    {
        $baris = DB::connection('katalog')->select(
            'SELECT id, name, email, peran FROM users WHERE id = ? LIMIT 1',
            [$idPengguna],
        );

        return isset($baris[0]) ? (array) $baris[0] : null;
    }

    public function riwayatPesanan(int $idPengguna, int $batas = 10): array
    {
        $batas = max(1, min($batas, 20));

        return array_map(
            fn ($baris) => (array) $baris,
            DB::connection('transaksi')->select(
                'SELECT kode, tempat_slug, tanggal_kunjungan, total, status, metode, created_at
                 FROM pesanan
                 WHERE id_pengguna = ?
                 ORDER BY created_at DESC, id_pesanan DESC
                 LIMIT ?',
                [$idPengguna, $batas],
            ),
        );
    }
}
