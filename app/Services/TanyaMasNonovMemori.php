<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TanyaMasNonovMemori
{
    private const TABLE = 'pesan_tanya_mas_nonov';

    private const JUMLAH_MEMORI = 4;

    public function simpan(int $idPengguna, string $pesan): int
    {
        $waktu = now();

        return (int) DB::connection('katalog')->table(self::TABLE)->insertGetId([
            'id_pengguna' => $idPengguna,
            'pesan_terenkripsi' => Crypt::encryptString($pesan),
            'pesan_hash' => hash_hmac('sha256', $pesan, (string) config('app.key')),
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
    }

    public function pertanyaanSebelumnya(int $idPengguna, int $idPesanSaatIni): array
    {
        $pesan = DB::connection('katalog')->table(self::TABLE)
            ->where('id_pengguna', $idPengguna)
            ->where('id', '<>', $idPesanSaatIni)
            ->orderByDesc('id')
            ->limit(self::JUMLAH_MEMORI)
            ->get(['pesan_terenkripsi'])
            ->reverse();

        return $pesan->map(fn (object $baris) => [
            'pertanyaan' => Crypt::decryptString($baris->pesan_terenkripsi),
        ])->values()->all();
    }
}
