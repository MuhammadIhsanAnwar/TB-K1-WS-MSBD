<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pembayaran tiket lewat Midtrans Snap. order_id Midtrans = kode pesanan di DB 2.
 *
 * Pesanan ditandai lunas hanya setelah status transaksi ditanyakan langsung ke API Midtrans,
 * baik dari notifikasi (yang tanda tangannya sudah diperiksa), callback jendela pembayaran,
 * maupun tombol "cek status". Pelunasan tetap lewat sp_bayar_pesanan (pembayaran + e-tiket).
 */
class Midtrans
{
    /** Metode pilihan di checkout menjadi kanal yang ditampilkan di jendela Snap. */
    private const KANAL = [
        'qris' => ['other_qris'],
        'va' => ['bca_va', 'bni_va', 'bri_va', 'permata_va', 'cimb_va', 'echannel', 'other_va'],
        'ewallet' => ['gopay', 'shopeepay'],
        'kartu' => ['credit_card'],
    ];

    /** Jendela pembayaran ditutup sebelum batas bayar, supaya pelunasan tidak tercatat setelah pesanan kedaluwarsa. */
    private const JEDA_MENIT = 1;

    public static function aktif(): bool
    {
        return filled(config('services.midtrans.server_key')) && filled(config('services.midtrans.client_key'));
    }

