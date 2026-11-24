<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TanyaMasNonovMemori
{
    private const TABLE = 'pesan_tanya_mas_nonov';

    private const JUMLAH_MEMORI = 4;

    public function simpan(?int $idPengguna, ?string $pemilikTamu, string $pesan): int
    {
        if ($idPengguna === null && ($pemilikTamu === null || $pemilikTamu === '')) {
            throw new \InvalidArgumentException('Pemilik percakapan tamu tidak tersedia.');
        }

        $waktu = now();

        return (int) DB::connection('katalog')->table(self::TABLE)->insertGetId([
            'id_pengguna' => $idPengguna,
            'pengirim' => $idPengguna === null ? 'tamu' : 'pengguna',
            'pemilik_tamu' => $idPengguna === null ? hash('sha256', $pemilikTamu) : null,
            'pesan_terenkripsi' => Crypt::encryptString($pesan),
            'pesan_hash' => hash_hmac('sha256', $pesan, (string) config('app.key')),
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
    }

    public function pertanyaanSebelumnya(?int $idPengguna, ?string $pemilikTamu, int $idPesanSaatIni): array
    {
        $query = DB::connection('katalog')->table(self::TABLE)
            ->where('id', '<>', $idPesanSaatIni)
            ->orderByDesc('id')
            ->limit(self::JUMLAH_MEMORI);
        if ($idPengguna !== null) {
            $query->where('id_pengguna', $idPengguna);
        } else {
            $query->where('pengirim', 'tamu')
                ->where('pemilik_tamu', hash('sha256', (string) $pemilikTamu));
        }

        $pesan = $query->get(['pesan_terenkripsi', 'jawaban_terenkripsi'])
            ->reverse();

        return $pesan->map(function (object $baris): array {
            $giliran = ['pertanyaan' => Crypt::decryptString($baris->pesan_terenkripsi)];
            if ($baris->jawaban_terenkripsi !== null) {
                $giliran['jawaban'] = Crypt::decryptString($baris->jawaban_terenkripsi);
            }

            return $giliran;
        })->values()->all();
    }

    public function simpanJawaban(int $idPesan, string $jawaban): void
    {
        DB::connection('katalog')->table(self::TABLE)
            ->where('id', $idPesan)
            ->update([
                'jawaban_terenkripsi' => Crypt::encryptString($jawaban),
                'updated_at' => now(),
            ]);
    }
}
