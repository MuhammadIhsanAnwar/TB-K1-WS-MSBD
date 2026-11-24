<?php

namespace App\Services;

use App\Support\SupabaseApi;

/** Query PostgreSQL melalui Supabase REST agar jalur web tidak memerlukan port SQL langsung. */
class PostgreSQLService
{
    public function ringkasanPopularitasTempat(string $slug): ?array
    {
        return (new SupabaseApi('transaksi'))->ringkasanPopularitasTempat($slug);
    }

    public function ringkasanUlasanTempat(string $slug): ?array
    {
        return $this->ringkasanPopularitasTempat($slug);
    }

    public function peringkatUlasan(int $batas = 5): array
    {
        return (new SupabaseApi('transaksi'))->peringkatUlasan($batas);
    }

    public function peringkatSuka(int $batas = 5): array
    {
        return (new SupabaseApi('transaksi'))->peringkatSuka($batas);
    }

    public function jumlahPesanan(int $idPengguna): int
    {
        return (new SupabaseApi('transaksi'))->jumlahPesanan($idPengguna);
    }

    public function profilPengguna(int $idPengguna): ?array
    {
        return (new SupabaseApi('katalog'))->profilPengguna($idPengguna);
    }

    public function riwayatPesanan(int $idPengguna, int $batas = 10): array
    {
        return (new SupabaseApi('transaksi'))->riwayatPesanan($idPengguna, $batas);
    }
}
