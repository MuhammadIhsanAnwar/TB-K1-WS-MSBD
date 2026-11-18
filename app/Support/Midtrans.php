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

    public static function produksi(): bool
    {
        return (bool) config('services.midtrans.is_production');
    }

    public static function urlSnapJs(): string
    {
        return self::produksi() ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    public static function clientKey(): ?string
    {
        return config('services.midtrans.client_key');
    }

    /**
     * Token Snap untuk pesanan yang masih menunggu. Disimpan di cache sampai batas bayar, jadi
     * membuka jendela lagi melanjutkan transaksi yang sama, bukan membuat transaksi baru.
     *
     * @return array{token: string, redirect_url: string}
     */
    public static function snap(array $p, string $namaTempat): array
    {
        $kunci = 'midtrans.snap.'.$p['kode'];
        if (is_array($simpan = Cache::get($kunci))) {
            return $simpan;
        }

        $batas = Carbon::parse($p['batas_bayar']);
        $menit = (int) floor(now()->diffInSeconds($batas, false) / 60) - self::JEDA_MENIT;
        if ($menit < 1) {
            throw new RuntimeException('Waktu pembayaran pesanan ini hampir habis. Buat pesanan baru.');
        }

        $isi = [
            'transaction_details' => ['order_id' => $p['kode'], 'gross_amount' => (int) $p['total']],
            'item_details' => array_map(fn ($i) => [
                'id' => Str::limit(Str::slug($i['jenis']), 50, ''),
                'price' => (int) $i['harga'],
                'quantity' => (int) $i['jumlah'],
                'name' => Str::limit('Tiket '.$i['jenis'].', '.$namaTempat, 50, ''),
            ], $p['item']),
            'customer_details' => [
                'first_name' => Str::limit($p['pemesan']['nama'], 50, ''),
                'email' => $p['pemesan']['email'],
                'phone' => $p['pemesan']['hp'],
            ],
            'expiry' => ['start_time' => now('Asia/Jakarta')->format('Y-m-d H:i:s O'), 'unit' => 'minute', 'duration' => $menit],
            'callbacks' => ['finish' => route('pesanan', $p['kode'])],
        ];
        if (isset(self::KANAL[$p['metode']])) {
            $isi['enabled_payments'] = self::KANAL[$p['metode']];
        }

        $jawab = self::http()->post(self::urlSnap(), $isi);
        if ($jawab->status() === 400 && isset($isi['enabled_payments']) && str_contains(strtolower($jawab->body()), 'payment')) {
            // kanal pilihan belum aktif di akun Midtrans: tampilkan semua kanal yang aktif
            unset($isi['enabled_payments']);
            $jawab = self::http()->post(self::urlSnap(), $isi);
        }
        $jawab->throw();

        $hasil = ['token' => $jawab->json('token'), 'redirect_url' => $jawab->json('redirect_url')];
        if (! $hasil['token']) {
            throw new RuntimeException('Midtrans tidak mengembalikan token pembayaran.');
        }
        Cache::put($kunci, $hasil, $batas->copy()->addMinutes(5));

        return $hasil;
    }

    /** Status transaksi dari API Midtrans, atau null kalau pembeli belum memilih cara bayar. */
    public static function status(string $kode): ?array
    {
        $jawab = self::http()->get(self::urlApi()."/v2/{$kode}/status");
        $data = $jawab->json() ?? [];
        if (($data['status_code'] ?? null) === '404') {
            return null;
        }
        $jawab->throw();

        return $data;
    }

    /**
     * Samakan status pesanan di DB 2 dengan Midtrans. Aman dipanggil berulang (notifikasi Midtrans
     * bisa datang lebih dari sekali dan bersamaan dengan tombol cek status).
     */
    public static function sinkron(string $kode): ?array
    {
        $p = Penyimpanan::cariPesanan($kode);
        if (! $p || ! ($s = self::status($kode))) {
            return $p;
        }

        $transaksi = $s['transaction_status'] ?? '';
        $lunas = $transaksi === 'settlement' || ($transaksi === 'capture' && ($s['fraud_status'] ?? 'accept') === 'accept');

        if ($lunas) {
            if ((int) round((float) ($s['gross_amount'] ?? 0)) !== (int) $p['total']) {
                Log::error("Midtrans: nominal {$kode} tidak sama dengan total pesanan", ['midtrans' => $s['gross_amount'] ?? null, 'pesanan' => $p['total']]);

                return $p;
            }
            if ($p['status'] === 'menunggu') {
                try {
                    $p = Penyimpanan::ubahPesanan($kode, ['status' => 'lunas', 'referensi' => Str::limit((string) ($s['transaction_id'] ?? 'MIDTRANS'), 40, '')]);
                } catch (Throwable $e) {
                    // notifikasi dan tombol cek bisa melunasi bersamaan; yang kedua ditolak sp_bayar_pesanan
                    $p = Penyimpanan::cariPesanan($kode);
                    if (($p['status'] ?? null) !== 'lunas') {
                        throw $e;
                    }
                }
            }
            if ($p['status'] !== 'lunas') {
                Log::warning("Midtrans: {$kode} dibayar tetapi pesanan berstatus {$p['status']}. Periksa dan kembalikan dana dari dashboard Midtrans.");
            }
        } elseif ($p['status'] === 'menunggu' && in_array($transaksi, ['expire', 'cancel'], true)) {
            $p = Penyimpanan::ubahPesanan($kode, ['status' => $transaksi === 'expire' ? 'kedaluwarsa' : 'dibatalkan']);
        }

        return $p;
    }

    /** Hentikan transaksi yang belum dibayar (pesanan dibatalkan pembeli). */
    public static function hentikan(string $kode): void
    {
        try {
            self::http()->post(self::urlApi()."/v2/{$kode}/expire");
        } catch (Throwable $e) {
            Log::info("Midtrans: transaksi {$kode} tidak bisa dihentikan: ".$e->getMessage());
        }
        Cache::forget('midtrans.snap.'.$kode);
    }

    /** Tanda tangan notifikasi: SHA512(order_id + status_code + gross_amount + Signature Secret Key). */
    public static function tandaTanganSah(array $n): bool
    {
        if (! isset($n['order_id'], $n['status_code'], $n['gross_amount'], $n['signature_key'])) {
            return false;
        }
        $teks = $n['order_id'].$n['status_code'].$n['gross_amount'];
        // Signature Secret Key untuk akun baru; Server Key untuk akun lama yang belum memisahkannya
        foreach (array_filter([config('services.midtrans.signature_key'), config('services.midtrans.server_key')]) as $rahasia) {
            if (hash_equals(hash('sha512', $teks.$rahasia), (string) $n['signature_key'])) {
                return true;
            }
        }

        return false;
    }

    private static function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)
            ->withBasicAuth((string) config('services.midtrans.server_key'), '');
    }

    private static function urlSnap(): string
    {
        return self::produksi() ? 'https://app.midtrans.com/snap/v1/transactions' : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    private static function urlApi(): string
    {
        return self::produksi() ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
