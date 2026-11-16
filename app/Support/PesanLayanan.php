<?php

namespace App\Support;

use App\Mail\BalasanPesanAdmin;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class PesanLayanan
{
    public static function kirim(array $pengguna, array $data): array
    {
        if (! in_array($pengguna['peran'] ?? null, ['wisatawan', 'admin'], true)) {
            abort(403);
        }

        if (($pengguna['peran'] ?? null) === 'admin' && $data['kategori'] === 'tempat') {
            throw ValidationException::withMessages(['kategori' => 'Admin tidak dapat mengirim pesan tentang tempat.']);
        }

        $slugTempat = null;
        $namaTempat = null;
        $idAdminTujuan = null;

        if ($data['kategori'] === 'tempat') {
            $tempat = Katalog::get((string) ($data['tempat_slug'] ?? ''));
            if (! $tempat || $tempat['jenis'] !== 'tempat') {
                throw ValidationException::withMessages(['tempat_slug' => 'Pilih tempat wisata yang valid.']);
            }

            $idAdminTujuan = (new SupabaseApi)->adminUntukTempat($tempat['slug']);
            if ($idAdminTujuan === null) {
                throw ValidationException::withMessages(['tempat_slug' => 'Belum ada admin yang mengelola tempat wisata ini.']);
            }

            $slugTempat = $tempat['slug'];
            $namaTempat = $tempat['nama'];
        } elseif (! in_array($data['kategori'], ['akun', 'lainnya'], true)) {
            throw ValidationException::withMessages(['kategori' => 'Pilih kategori pesan yang valid.']);
        }

        if (empty($pengguna['id']) || empty($pengguna['nama']) || empty($pengguna['email'])) {
            abort(403, 'Data akun belum lengkap untuk mengirim pesan.');
        }

        $waktu = now('Asia/Jakarta')->toDateTimeString();

        return (new SupabaseApi)->buatPesanPengguna([
            'id_pengguna' => (int) $pengguna['id'],
            'nama_pengguna' => Str::limit($pengguna['nama'], 80),
            'email_pengguna' => Str::limit($pengguna['email'], 120),
            'kategori' => $data['kategori'],
            'tempat_slug' => $slugTempat,
            'nama_tempat' => $namaTempat,
            'id_admin_tujuan' => $idAdminTujuan,
            'pesan' => $data['pesan'],
            'status' => 'baru',
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
    }

    public static function daftar(array $pengguna): array
    {
        if (! in_array($pengguna['peran'] ?? null, ['admin', 'superadmin'], true) || empty($pengguna['id'])) {
            abort(403);
        }

        $api = new SupabaseApi;
        $pesan = $api->pesanPenggunaInbox($pengguna['peran'], (int) $pengguna['id']);
        $balasan = $api->balasanPesanPengguna(array_column($pesan, 'id'));
        $balasanPerPesan = collect($balasan)->groupBy('pesan_id');

        return array_map(
            fn (array $baris) => $baris + ['balasan' => ($balasanPerPesan->get($baris['id']) ?? collect())->all()],
            $pesan,
        );
    }

    public static function detail(int $id, array $pengguna): array
    {
        $baris = (new SupabaseApi)->pesanPengguna($id);
        abort_unless($baris && self::bolehDiakses($baris, $pengguna), 404);
        $baris['balasan'] = (new SupabaseApi)->balasanPesanPengguna([$id]);

        return $baris;
    }

    public static function balas(int $id, array $pengguna, string $isi): void
    {
        $pesan = self::detail($id, $pengguna);
        $api = new SupabaseApi;
        $waktu = now('Asia/Jakarta')->toDateTimeString();
        $balasan = $api->buatBalasanPesan([
            'pesan_id' => $id,
            'id_pengirim' => (int) $pengguna['id'],
            'peran_pengirim' => $pengguna['peran'],
            'isi' => $isi,
            'status_email' => 'menunggu',
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        try {
            Mail::to($pesan['email_pengguna'])->send(new BalasanPesanAdmin(
                idPesan: $id,
                namaPengguna: $pesan['nama_pengguna'],
                namaAdmin: $pengguna['nama'],
                kategori: self::labelKategori($pesan['kategori']),
                namaTempat: $pesan['nama_tempat'],
                pesanAwal: $pesan['pesan'],
                balasan: $isi,
            ));
        } catch (TransportExceptionInterface $exception) {
            $api->ubahStatusBalasanEmail((int) $balasan['id'], 'gagal');
            report($exception);
            throw $exception;
        }

        $api->ubahStatusBalasanEmail((int) $balasan['id'], 'terkirim', $waktu);
        $api->tandaiPesanDibalas($id);
    }

    public static function labelKategori(string $kategori): string
    {
        return match ($kategori) {
            'tempat' => 'Tempat',
            'akun' => 'Akun',
            'lainnya' => 'Lainnya',
            default => 'Tidak diketahui',
        };
    }

    private static function bolehDiakses(array $pesan, array $pengguna): bool
    {
        return match ($pengguna['peran'] ?? null) {
            'admin' => $pesan['kategori'] === 'tempat'
                && (int) $pesan['id_admin_tujuan'] === (int) ($pengguna['id'] ?? 0),
            'superadmin' => in_array($pesan['kategori'], ['akun', 'lainnya'], true),
            default => false,
        };
    }
}
