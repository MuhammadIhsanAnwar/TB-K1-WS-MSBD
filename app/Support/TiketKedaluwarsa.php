<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class TiketKedaluwarsa
{
    public static function berlakuSampai(array $pesanan): Carbon
    {
        $tanggal = Carbon::parse($pesanan['tanggal'], 'Asia/Jakarta')->startOfDay();
        $tempat = Katalog::get($pesanan['tempat']);

        return $tempat
            ? Katalog::batasOperasional($tempat, $tanggal)
            : $tanggal->endOfDay();
    }

    public static function perbarui(array $pesanan, ?Carbon $sekarang = null): array
    {
        if ($pesanan['status'] !== 'lunas' || ! empty($pesanan['dipakai'])) {
            return $pesanan;
        }

        $sekarang = ($sekarang ?? now('Asia/Jakarta'))->copy()->setTimezone('Asia/Jakarta');
        if ($sekarang->lt(self::berlakuSampai($pesanan))) {
            return $pesanan;
        }

        if (Penyimpanan::kedaluwarsakanEtiket((int) $pesanan['id'])) {
            $pesanan['status'] = 'kedaluwarsa';

            return $pesanan;
        }

        return Penyimpanan::cariPesanan($pesanan['kode']) ?? $pesanan;
    }

    public static function perbaruiTerjadwal(): int
    {
        $sekarang = now('Asia/Jakarta');
        $kedaluwarsa = 0;
        foreach (Penyimpanan::pesananLunasBelumCheckin($sekarang->toDateString()) as $pesanan) {
            if ($sekarang->greaterThanOrEqualTo(self::berlakuSampai($pesanan))
                && Penyimpanan::kedaluwarsakanEtiket((int) $pesanan['id'])) {
                $kedaluwarsa++;
            }
        }

        return $kedaluwarsa;
    }
}
