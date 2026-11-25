<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Penyimpanan transaksi MedanVisit.
 *
 * Pesanan tiket, ulasan, log pencarian, dan rencana disimpan di DB 2 (Supabase, project transaksi)
 * dan diakses lewat Supabase REST API (PostgREST), bukan koneksi PostgreSQL langsung: hosting
 * tidak membuka port 5432. Aturan bisnisnya tetap di database — sp_buat_pesanan, sp_bayar_pesanan,
 * fn_simpan_rencana, dan trigger total pesanan. Rating disimpan di rating_tempat.
 *
 * Pengguna dirujuk lewat id_pengguna (users.id di DB 1), tempat lewat slug resource Database 3.
 *
 * Check-in, voucher, dan penukaran poin masih di cache berkas: fiturnya tidak punya route lagi.
 */
class Penyimpanan
{
    private const ZONA = 'Asia/Jakarta';

    private static function api(): SupabaseApi
    {
        return new SupabaseApi('transaksi');
    }

    /** Untuk bacaan yang tampil di banyak halaman: kalau DB 2 tidak terjangkau, halaman tetap terbuka. */
    private static function aman(callable $baca, mixed $cadangan): mixed
    {
        try {
            return $baca();
        } catch (Throwable $e) {
            Log::warning('DB 2 (transaksi) tidak bisa dibaca: '.$e->getMessage());

            return $cadangan;
        }
    }

    private static function waktu(?string $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->setTimezone(self::ZONA)->toIso8601String();
    }

    // ---------- pesanan tiket ----------

    /** Semua pesanan (terbaru dulu), atau milik satu pengguna. */
    public static function pesanan(?int $idPengguna = null, bool $aman = false): array
    {
        $baca = fn () => self::bentukPesanan(self::api()->pesanan($idPengguna));

        return $aman ? self::aman($baca, []) : $baca();
    }

    public static function cariPesanan(string $kode): ?array
    {
        return self::bentukPesanan(self::api()->pesanan(kode: $kode))[0] ?? null;
    }

    public static function cariPesananQr(string $kodeQr): ?array
    {
        return self::bentukPesanan(self::api()->pesananDenganKodeQr($kodeQr))[0] ?? null;
    }

    /**
     * Membuat pesanan lewat function sp_buat_pesanan (pesanan + item dalam satu transaksi,
     * total dihitung trigger, batas bayar 15 menit).
     */
    public static function buatPesanan(array $data): array
    {
        $item = array_map(fn ($i) => ['jenis' => $i['jenis'], 'harga' => (int) $i['harga'], 'jumlah' => (int) $i['jumlah']], $data['item']);
        $kode = self::api()->buatPesanan([
            'p_id_pengguna' => $data['id_pengguna'],
            'p_nama' => $data['pemesan']['nama'],
            'p_email' => $data['pemesan']['email'],
            'p_hp' => $data['pemesan']['hp'],
            'p_tempat_slug' => $data['tempat'],
            'p_tanggal' => $data['tanggal'],
            'p_metode' => $data['metode'],
            'p_item' => $item,
        ]);

        return self::cariPesanan($kode);
    }

    /**
     * Mengubah pesanan:
     * - ['status' => 'lunas']        lewat sp_bayar_pesanan (catat pembayaran, terbitkan e-tiket;
     *                                 kalau sudah lewat batas bayar, function menandainya kedaluwarsa)
     * - ['status' => 'dibatalkan'|'kedaluwarsa']  hanya untuk pesanan yang masih menunggu
     * - ['dipakai' => waktu]         e-tiket dipindai di lokasi
     * - ['metode' => qris|va|ewallet|kartu]  cara bayar yang dipilih di Midtrans (hanya pesanan menunggu)
     */
    public static function ubahPesanan(string $kode, array $ubah): ?array
    {
        $api = self::api();
        if (isset($ubah['metode'])) {
            $api->ubahMetodePesanan($kode, $ubah['metode']);
        }
        if (($ubah['status'] ?? null) === 'lunas') {
            $api->bayarPesanan($kode, $ubah['referensi'] ?? 'SIMULASI');
        } elseif (in_array($ubah['status'] ?? null, ['dibatalkan', 'kedaluwarsa'], true)) {
            $api->batalkanPesanan($kode, $ubah['status']);
        }
        if (isset($ubah['dipakai'])) {
            $id = $api->pesanan(kode: $kode)[0]['id_pesanan'] ?? null;
            $id && $api->pakaiTiket((int) $id, Carbon::parse($ubah['dipakai'])->toIso8601String());
        }

        return self::cariPesanan($kode);
    }

    public static function tandaiTiketDipakai(int $idPesanan, string $waktu): bool
    {
        return self::api()->pakaiTiket($idPesanan, $waktu);
    }

    /** Baris database menjadi bentuk yang dipakai controller dan view. */
    private static function bentukPesanan(array $baris): array
    {
        if (! $baris) {
            return [];
        }
        $item = collect(self::api()->pesananItem(array_column($baris, 'id_pesanan')))->groupBy('id_pesanan');

        return array_map(fn ($p) => [
            'id' => (string) $p['id_pesanan'],
            'kode' => $p['kode'],
            'id_pengguna' => $p['id_pengguna'] === null ? null : (int) $p['id_pengguna'],
            'pemesan' => ['nama' => $p['nama_pemesan'], 'email' => $p['email_pemesan'], 'hp' => $p['hp_pemesan']],
            'tempat' => $p['tempat_slug'],
            'tanggal' => Carbon::parse($p['tanggal_kunjungan'])->toDateString(),
            'item' => ($item[$p['id_pesanan']] ?? collect())->map(fn ($i) => [
                'jenis' => $i['jenis_pengunjung'], 'harga' => (int) $i['harga'],
                'jumlah' => (int) $i['jumlah'], 'subtotal' => (int) $i['subtotal'],
            ])->values()->all(),
            'total' => (int) $p['total'],
            'metode' => $p['metode'],
            'status' => $p['status'],
            'batas_bayar' => self::waktu($p['batas_bayar']),
            'waktu' => self::waktu($p['created_at']),
            'dibayar' => self::waktu($p['pembayaran'][0]['dibayar_pada'] ?? null),
            'kode_qr' => $p['e_tiket'][0]['kode_qr'] ?? null,
            'dipakai' => self::waktu($p['e_tiket'][0]['dipakai_pada'] ?? null),
        ], $baris);
    }

    // ---------- ulasan ----------

    /** Ulasan (terbaru dulu), bisa disaring per tempat dan status. */
    public static function ulasan(?string $tempat = null, ?string $status = null, ?int $idPengguna = null, bool $aman = false): array
    {
        $baca = fn () => self::bentukUlasan(self::api()->ulasan($tempat, $status, $idPengguna));

        return $aman ? self::aman($baca, []) : $baca();
    }

    public static function ulasanHalaman(?array $tempatSlugs, string $status, int $batas, int $offset): array
    {
        if ($tempatSlugs === []) {
            return ['ulasan' => [], 'jumlah' => 0];
        }

        $hasil = self::api()->ulasanHalaman($tempatSlugs, $status, $batas, $offset);

        return [
            'ulasan' => self::bentukUlasan($hasil['baris']),
            'jumlah' => (int) $hasil['jumlah'],
        ];
    }

    private static function bentukUlasan(array $baris): array
    {
        return array_map(function ($u): array {
            $rating = $u['rating_tempat'] ?? [];
            if (isset($rating[0])) {
                $rating = $rating[0];
            }

            return [
                'id' => (string) $u['id_ulasan'],
                'id_pengguna' => (int) $u['id_pengguna'],
                'tempat' => $u['tempat_slug'],
                'nama' => $u['nama_pengguna'],
                'email' => $u['email_pengguna'],
                'bintang' => (int) ($rating['bintang'] ?? 0),
                'teks' => $u['teks'],
                'status' => $u['status'],
                'waktu' => self::waktu($u['created_at']),
            ];
        }, $baris);
    }

    /** Ringkasan ulasan dihitung dari ulasan dan rating yang terkait. */
    public static function ringkasanUlasan(): array
    {
        return self::aman(fn () => collect(self::api()->ringkasanUlasan())
            ->mapWithKeys(fn ($r) => [$r['tempat_slug'] => [
                'rating' => $r['rata_rata_bintang'] === null ? 0 : (float) $r['rata_rata_bintang'],
                'jumlahUlasan' => (int) $r['jumlah_ulasan'],
            ]])->all(), []);
    }

    /** Rating dan jumlah penilai dari tabel rating_tempat di DB 2. */
    public static function ringkasanRatingTempat(): array
    {
        return self::aman(fn () => collect(self::api()->ringkasanRatingTempat())
            ->mapWithKeys(fn ($r) => [$r['tempat_slug'] => [
                'rating' => $r['rata_rata_bintang'] === null ? 0 : (float) $r['rata_rata_bintang'],
                'jumlahRating' => (int) $r['jumlah_rating'],
            ]])->all(), []);
    }

    /** Jumlah suka per tempat dihitung dari DB 2, bukan cache aplikasi. */
    public static function ringkasanSukaTempat(): array
    {
        return self::aman(fn () => collect(self::api()->ringkasanSukaTempat())
            ->mapWithKeys(fn ($r) => [$r['tempat_slug'] => (int) $r['jumlah_suka']])
            ->all(), []);
    }

    /** Slug tempat yang disukai akun; kegagalan baca dicatat dan halaman tetap dapat ditampilkan. */
    public static function tempatDisukai(int $idPengguna): array
    {
        return self::aman(fn () => array_column(self::api()->sukaTempatPengguna($idPengguna), 'tempat_slug'), []);
    }

    /** Toggle suka untuk satu akun-tempat, dengan pasangan unik dijaga DB 2. */
    public static function ubahSukaTempat(int $idPengguna, string $slug): bool
    {
        $api = self::api();
        if ($api->sudahSukaTempat($idPengguna, $slug)) {
            $api->hapusSukaTempat($idPengguna, $slug);

            return false;
        }

        $api->tambahSukaTempat($idPengguna, $slug);

        return true;
    }

    /**
     * Satu akun satu ulasan per tempat: kalau sudah pernah mengulas, ulasan lamanya diperbarui
     * (tanggal tulis awal dipertahankan). Rating disimpan satu kali di rating_tempat.
     */
    public static function tulisUlasan(array $data): array
    {
        $lama = collect(self::api()->ulasan($data['tempat'], null, (int) $data['id_pengguna']))
            ->first(fn ($u) => (int) ($u['id_pengguna'] ?? 0) === (int) $data['id_pengguna'] && ($u['tempat_slug'] ?? null) === $data['tempat']);
        if ($lama) {
            $waktu = now()->toIso8601String();
            self::api()->upsertRatingTempat([[
                'id_pengguna' => (int) $data['id_pengguna'],
                'tempat_slug' => $data['tempat'],
                'bintang' => (int) $data['bintang'],
                'updated_at' => $waktu,
            ]]);
            $row = self::api()->ubahUlasan((int) $lama['id_ulasan'], [
                'nama_pengguna' => Str::limit($data['nama'] ?? 'Wisatawan', 77),
                'teks' => $data['teks'],
                'status' => 'tayang',
                'updated_at' => $waktu,
            ]);

            return ['id' => (string) $row['id_ulasan'], 'diperbarui' => true] + $data;
        }

        $waktu = now()->toIso8601String();
        self::api()->upsertRatingTempat([[
            'id_pengguna' => (int) $data['id_pengguna'],
            'tempat_slug' => $data['tempat'],
            'bintang' => (int) $data['bintang'],
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]]);
        $row = self::api()->tulisUlasan([
            'id_pengguna' => $data['id_pengguna'],
            'tempat_slug' => $data['tempat'],
            'nama_pengguna' => Str::limit($data['nama'] ?? 'Wisatawan', 77),
            'email_pengguna' => $data['email'] ?? null,
            'teks' => $data['teks'],
            'status' => 'tayang',
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
        return ['id' => (string) $row['id_ulasan']] + $data;
    }

    public static function hapusUlasan(string $id): void
    {
        self::api()->hapusUlasan((int) $id);
    }

    // ---------- log pencarian ----------

    public static function catatCari(string $q, int $jumlahHasil): void
    {
        if (trim($q) === '') {
            return;
        }
        self::aman(fn () => self::api()->catatCari(Str::limit(mb_strtolower(trim($q)), 117), min($jumlahHasil, 32767)), null);
    }

    /** Kata kunci yang dicari tetapi tanpa hasil, dengan jumlahnya (view v_celah_konten). */
    public static function celahKonten(): array
    {
        return self::aman(fn () => collect(self::api()->celahKonten())
            ->pluck('jumlah_dicari', 'kata_kunci')->map(fn ($n) => (int) $n)->all(), []);
    }

    public static function jumlahCari(): int
    {
        return self::aman(fn () => self::api()->jumlahCari(), 0);
    }

    // ---------- rencana tersimpan ----------

    public static function rencana(?int $idPengguna = null, bool $aman = false): array
    {
        $baca = fn () => self::bentukRencana(self::api()->rencana($idPengguna));

        return $aman ? self::aman($baca, []) : $baca();
    }

    public static function simpanRencana(array $data): array
    {
        $row = self::api()->simpanRencana([
            'p_kode' => 'mdn-'.strtolower(Str::random(4)),
            'p_id_pengguna' => $data['id_pengguna'],
            'p_judul' => $data['judul'],
            'p_durasi_jam' => (int) $data['durasi'],
            'p_moda' => array_values(array_unique($data['moda'])),
            'p_tempat_mulai_slug' => $data['mulai'],
            'p_minat' => array_values(array_unique($data['minat'])),
            'p_tujuan' => array_values(array_unique($data['tujuan'] ?? [])),
        ]);

        return self::bentukRencana([$row])[0];
    }

    public static function perbaruiRencana(array $data): ?array
    {
        $row = self::api()->perbaruiRencana([
            'p_id_rencana' => $data['id_rencana'],
            'p_id_pengguna' => $data['id_pengguna'],
            'p_judul' => $data['judul'],
            'p_durasi_jam' => (int) $data['durasi'],
            'p_moda' => array_values(array_unique($data['moda'])),
            'p_tempat_mulai_slug' => $data['mulai'],
            'p_minat' => array_values(array_unique($data['minat'])),
            'p_tujuan' => array_values(array_unique($data['tujuan'] ?? [])),
        ]);

        return $row === null ? null : self::bentukRencana([$row])[0];
    }

    public static function hapusRencana(int $idRencana, int $idPengguna): bool
    {
        return self::api()->hapusRencana($idRencana, $idPengguna);
    }

    public static function cariRencana(string $kode): ?array
    {
        return self::bentukRencana(self::api()->rencana(kode: $kode))[0] ?? null;
    }

    public static function cariRencanaPengguna(int $idPengguna, string $kode): ?array
    {
        return self::bentukRencana(self::api()->rencana($idPengguna, $kode))[0] ?? null;
    }

    private static function bentukRencana(array $baris): array
    {
        if (! $baris) {
            return [];
        }
        $minat = collect(self::api()->rencanaMinat(array_column($baris, 'id_rencana')))->groupBy('id_rencana');
        $moda = collect(self::api()->rencanaModa(array_column($baris, 'id_rencana')))->groupBy('id_rencana');
        $tujuan = collect(self::api()->rencanaTujuan(array_column($baris, 'id_rencana')))->groupBy('id_rencana');

        return array_map(fn ($r) => [
            'id' => (string) $r['id_rencana'],
            'kode' => $r['kode'],
            'id_pengguna' => (int) $r['id_pengguna'],
            'judul' => $r['judul'],
            'minat' => ($minat[$r['id_rencana']] ?? collect())->pluck('kode_kategori')->all(),
            'durasi' => (int) $r['durasi_jam'],
            'moda' => ($moda[$r['id_rencana']] ?? collect())->pluck('moda')->all(),
            'tujuan' => ($tujuan[$r['id_rencana']] ?? collect())->pluck('tempat_slug')->all(),
            'mulai' => $r['tempat_mulai_slug'],
            'waktu' => self::waktu($r['created_at']),
        ], $baris);
    }

    // ---------- fitur tanpa route: masih di cache berkas ----------

    private static function ambil(string $kunci): array
    {
        return Cache::get('medanvisit.'.$kunci, []);
    }

    private static function simpan(string $kunci, array $isi): void
    {
        Cache::forever('medanvisit.'.$kunci, $isi);
    }

    private static function tambah(string $kunci, array $baris): array
    {
        $isi = self::ambil($kunci);
        $baris += ['id' => (string) Str::ulid(), 'waktu' => now(self::ZONA)->toIso8601String()];
        $isi[$baris['id']] = $baris;
        self::simpan($kunci, $isi);

        return $baris;
    }

    public static function checkin(?string $email = null): array
    {
        return array_reverse(array_filter(self::ambil('checkin'), fn ($c) => $email === null || $c['email'] === $email));
    }

    public static function voucher(): array
    {
        return self::ambil('voucher');
    }

    public static function buatVoucher(array $data): array
    {
        return self::tambah('voucher', $data);
    }

    public static function penukaran(?string $email = null): array
    {
        return array_reverse(array_filter(self::ambil('penukaran'), fn ($p) => $email === null || $p['email'] === $email));
    }

    public static function pakaiPenukaran(string $kode): ?array
    {
        $semua = self::ambil('penukaran');
        foreach ($semua as $id => $p) {
            if ($p['kode'] === strtoupper(trim($kode))) {
                $sebelum = $p['dipakai'];
                $semua[$id]['dipakai'] = true;
                self::simpan('penukaran', $semua);

                return $p + ['sudah_dipakai' => $sebelum];
            }
        }

        return null;
    }
}
