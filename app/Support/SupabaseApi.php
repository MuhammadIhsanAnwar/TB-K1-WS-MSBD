<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien Supabase REST (PostgREST) untuk DB 1 (katalog) dan DB 2 (transaksi).
 *
 * Hosting tidak membuka port PostgreSQL (5432/6543), jadi semua akses data lewat HTTPS
 * ke /rest/v1 dan /rest/v1/rpc. Tidak ada string koneksi PDO di jalur runtime.
 */
class SupabaseApi
{
    public function __construct(private readonly string $database = 'katalog') {}

    // ---------- akun (DB 1) ----------

    public function findUserByEmail(string $email): ?array
    {
        return $this->getRows('users', ['email' => 'eq.'.strtolower($email)])[0] ?? null;
    }

    public function users(): array
    {
        return $this->semua('users', 'id,name,email,peran,tempat_slug,tanggal_lahir,no_hp,email_verified_at,created_at,ditangguhkan_pada,alasan_tangguh');
    }

    public function createUser(array $user): array
    {
        return $this->insert('users', $user);
    }

    public function updateUser(int|string $id, array $attributes): array
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->patch($this->url('users').'?'.http_build_query(['id' => 'eq.'.$id]), $attributes)
            ->throw()
            ->json();

        return $rows[0] ?? throw new RuntimeException('Supabase tidak menemukan akun untuk diperbarui.');
    }

    public function deleteUser(int|string $id): void
    {
        $response = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->delete($this->url('users').'?'.http_build_query(['id' => 'eq.'.$id]))
            ->throw();
        if (! $response->json()) {
            throw new RuntimeException(
                'Supabase tidak menghapus akun id '.$id.'. Kemungkinan policy RLS DELETE belum aktif pada tabel users, '
                .'atau akun masih dirujuk tabel lain (mis. pesan_pengguna.id_admin_tujuan).'
            );
        }
    }

    public function adminTempat(): array
    {
        return $this->getRows('admin_tempat', ['select' => 'id_admin,tempat_slug', 'order' => 'id_admin.asc,tempat_slug.asc']);
    }

    public function tempatAdmin(int|string $id): array
    {
        return array_column($this->getRows('admin_tempat', [
            'select' => 'tempat_slug',
            'id_admin' => 'eq.'.$id,
            'order' => 'tempat_slug.asc',
        ]), 'tempat_slug');
    }

    public function assignTempatAdmin(int|string $id, string $slug): void
    {
        $waktu = now('Asia/Jakarta')->toDateTimeString();
        $this->insert('admin_tempat', [
            'id_admin' => $id,
            'tempat_slug' => $slug,
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);
    }

    public function hapusTempatAdmin(int|string $id, string $slug): void
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->delete($this->url('admin_tempat').'?'.http_build_query([
                'id_admin' => 'eq.'.$id,
                'tempat_slug' => 'eq.'.$slug,
            ]))
            ->throw()
            ->json();
        if (! $rows) {
            throw new RuntimeException('Supabase tidak menemukan hubungan admin-tempat untuk dihapus.');
        }
    }

    public function adminUntukTempat(string $slug): ?int
    {
        $rows = $this->getRows('admin_tempat', [
            'select' => 'id_admin',
            'tempat_slug' => 'eq.'.$slug,
            'limit' => 1,
        ]);

        return isset($rows[0]['id_admin']) ? (int) $rows[0]['id_admin'] : null;
    }

    public function pesanPenggunaInbox(string $peran, int $idPengguna): array
    {
        $filters = ['order' => 'created_at.desc,id.desc', 'limit' => 500];
        if ($peran === 'admin') {
            $filters += ['kategori' => 'eq.tempat', 'id_admin_tujuan' => 'eq.'.$idPengguna];
        } elseif ($peran === 'superadmin') {
            $filters['kategori'] = 'in.(akun,lainnya)';
        } else {
            return [];
        }

        return $this->getRows('pesan_pengguna', $filters);
    }

    public function pesanPengguna(int $id): ?array
    {
        return $this->getRows('pesan_pengguna', ['id' => 'eq.'.$id])[0] ?? null;
    }

    public function buatPesanPengguna(array $data): array
    {
        return $this->insert('pesan_pengguna', $data);
    }

    public function balasanPesanPengguna(array $idPesan): array
    {
        if ($idPesan === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $idPesan));

        return $this->getRows('balasan_pesan', [
            'pesan_id' => 'in.('.$ids.')',
            'order' => 'created_at.asc,id.asc',
        ]);
    }

    public function buatBalasanPesan(array $data): array
    {
        return $this->insert('balasan_pesan', $data);
    }

    public function ubahStatusBalasanEmail(int $id, string $status, ?string $waktuTerkirim = null): void
    {
        $this->ubahBaris('balasan_pesan', $id, array_filter([
            'status_email' => $status,
            'email_terkirim_pada' => $waktuTerkirim,
            'updated_at' => now('Asia/Jakarta')->toDateTimeString(),
        ], fn ($nilai) => $nilai !== null));
    }

    public function tandaiPesanDibalas(int $id): void
    {
        $this->ubahBaris('pesan_pengguna', $id, [
            'status' => 'dibalas',
            'updated_at' => now('Asia/Jakarta')->toDateTimeString(),
        ]);
    }

    public function createVerificationToken(array $token): array
    {
        return $this->insert('email_verification_tokens', $token);
    }

    public function findVerificationToken(string $hash): ?array
    {
        return $this->getRows('email_verification_tokens', ['token_hash' => 'eq.'.$hash])[0] ?? null;
    }

    public function deleteVerificationToken(int|string $id): void
    {
        $this->request()
            ->delete($this->url('email_verification_tokens').'?'.http_build_query(['id' => 'eq.'.$id]))
            ->throw();
    }

    public function createPasswordResetToken(array $token): array
    {
        $this->deletePasswordResetToken($token['email']);

        return $this->insert('password_reset_tokens', $token);
    }

    public function findPasswordResetToken(string $hash): ?array
    {
        return $this->getRows('password_reset_tokens', ['token' => 'eq.'.$hash])[0] ?? null;
    }

    public function deletePasswordResetToken(string $email): void
    {
        $this->request()
            ->delete($this->url('password_reset_tokens').'?'.http_build_query(['email' => 'eq.'.strtolower($email)]))
            ->throw();
    }

    // ---------- transaksi (DB 2) ----------

    /**
     * Baris pesanan + pembayaran + e-tiket (setara join lama), opsional per pengguna / per kode.
     * Urut terbaru dulu.
     */
    public function pesanan(?int $idPengguna = null, ?string $kode = null): array
    {
        $filters = ['select' => '*,pembayaran(dibayar_pada,referensi),e_tiket(kode_qr,dipakai_pada)', 'order' => 'created_at.desc,id_pesanan.desc'];
        if ($idPengguna !== null) {
            $filters['id_pengguna'] = 'eq.'.$idPengguna;
        }
        if ($kode !== null) {
            $filters['kode'] = 'eq.'.$kode;
            $filters['limit'] = 1;
        }

        return $this->getRows('pesanan', $filters);
    }

    public function pesananItem(array $idPesanan): array
    {
        if (! $idPesanan) {
            return [];
        }

        return $this->getRows('pesanan_item', [
            'select' => '*',
            'id_pesanan' => 'in.('.implode(',', array_map('intval', $idPesanan)).')',
            'order' => 'id_item.asc',
        ]);
    }

    /** Membuat pesanan lewat function sp_buat_pesanan (total & item dihitung database). */
    public function buatPesanan(array $arg): string
    {
        return (string) $this->rpc('sp_buat_pesanan', $arg);
    }

    /** Membayar pesanan lewat function sp_bayar_pesanan (pembayaran + e-tiket dibuat database). */
    public function bayarPesanan(string $kode, ?string $referensi): ?string
    {
        return $this->rpc('sp_bayar_pesanan', ['p_kode' => $kode, 'p_referensi' => $referensi]);
    }

    public function batalkanPesanan(string $kode, string $status): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'return=minimal'])
            ->patch($this->url('pesanan').'?'.http_build_query(['kode' => 'eq.'.$kode, 'status' => 'eq.menunggu']), [
                'status' => $status,
                'updated_at' => now()->toIso8601String(),
            ])
            ->throw();
    }

    public function pakaiTiket(int $idPesanan, string $waktu): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'return=minimal'])
            ->patch($this->url('e_tiket').'?'.http_build_query(['id_pesanan' => 'eq.'.$idPesanan, 'dipakai_pada' => 'is.null']), [
                'dipakai_pada' => $waktu,
            ])
            ->throw();
    }

    public function ulasan(?string $tempat = null, ?string $status = null, ?int $idPengguna = null): array
    {
        $filters = ['order' => 'created_at.desc,id_ulasan.desc'];
        $tempat === null || $filters['tempat_slug'] = 'eq.'.$tempat;
        $status === null || $filters['status'] = 'eq.'.$status;
        $idPengguna === null || $filters['id_pengguna'] = 'eq.'.$idPengguna;

        return $this->getRows('ulasan', $filters);
    }

    public function ringkasanUlasan(): array
    {
        return $this->getRows('ringkasan_ulasan', ['select' => 'tempat_slug,rata_rata_bintang,jumlah_ulasan']);
    }

    public function tulisUlasan(array $data): array
    {
        return $this->insert('ulasan', $data);
    }

    public function ubahUlasan(int $id, array $data): array
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->patch($this->url('ulasan').'?'.http_build_query(['id_ulasan' => 'eq.'.$id]), $data)
            ->throw()
            ->json();

        return $rows[0] ?? throw new RuntimeException('Supabase tidak menemukan ulasan untuk diperbarui.');
    }

    public function hapusUlasan(int $id): void
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->delete($this->url('ulasan').'?'.http_build_query(['id_ulasan' => 'eq.'.$id]))
            ->throw()
            ->json();
        if (! $rows) {
            throw new RuntimeException('Supabase tidak menemukan ulasan untuk dihapus.');
        }
    }

    public function catatCari(string $kataKunci, int $jumlahHasil): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'return=minimal'])
            ->post($this->url('log_pencarian'), ['kata_kunci' => $kataKunci, 'jumlah_hasil' => $jumlahHasil])
            ->throw();
    }

    /** View v_celah_konten: kata kunci tanpa hasil. */
    public function celahKonten(): array
    {
        return $this->getRows('v_celah_konten', ['order' => 'jumlah_dicari.desc']);
    }

    public function jumlahCari(): int
    {
        $response = $this->request()
            ->withHeaders(['Prefer' => 'count=exact', 'Range' => '0-0'])
            ->get($this->url('log_pencarian'), ['select' => 'id_log'])
            ->throw();

        return (int) ($response->header('Content-Range') ? explode('/', $response->header('Content-Range'))[1] : 0);
    }

    public function rencana(?int $idPengguna = null, ?string $kode = null): array
    {
        $filters = ['order' => 'created_at.desc,id_rencana.desc'];
        $idPengguna === null || $filters['id_pengguna'] = 'eq.'.$idPengguna;
        if ($kode !== null) {
            $filters['kode'] = 'eq.'.$kode;
            $filters['limit'] = 1;
        }

        return $this->getRows('rencana', $filters);
    }

    public function rencanaMinat(array $idRencana): array
    {
        if (! $idRencana) {
            return [];
        }

        return $this->getRows('rencana_minat', [
            'select' => '*',
            'id_rencana' => 'in.('.implode(',', array_map('intval', $idRencana)).')',
        ]);
    }

    /** Rencana + minatnya dibuat dalam satu function database (transaksi). */
    public function simpanRencana(array $arg): array
    {
        return (array) $this->rpc('fn_simpan_rencana', $arg);
    }

    // ---------- utilitas ----------

    // ---------- pengaturan situs (DB 1) ----------

    /** Nilai JSON satu kunci pengaturan, atau null kalau belum pernah diatur. */
    public function pengaturanSitus(string $kunci): mixed
    {
        return $this->getRows('pengaturan_situs', ['kunci' => 'eq.'.$kunci, 'limit' => 1])[0]['nilai'] ?? null;
    }

    /** Simpan (buat atau timpa) satu kunci pengaturan. */
    public function simpanPengaturanSitus(string $kunci, mixed $nilai, ?int $idPengguna): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'resolution=merge-duplicates,return=minimal'])

    public function ping(string $table): void
    {
        $this->request()
            ->get($this->url($table), ['select' => '*', 'limit' => 1])
            ->throw();
    }

    private function getRows(string $table, array $filters = []): array
    {
        return $this->request()
            ->get($this->url($table), array_merge(['select' => '*'], $filters))
            ->throw()
            ->json();
    }

    private function semua(string $table, string $select, int $limit = 500): array
    {
        $rows = [];
        $offset = 0;
        do {
            $batch = $this->request()
                ->get($this->url($table), ['select' => $select, 'order' => 'id.desc', 'limit' => $limit, 'offset' => $offset])
                ->throw()
                ->json();
            array_push($rows, ...$batch);
            $offset += count($batch);
        } while (count($batch) === $limit);

        return $rows;
    }

    private function insert(string $table, array $attributes): array
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post($this->url($table), $attributes)
            ->throw()
            ->json();

        return $rows[0] ?? throw new RuntimeException('Supabase tidak mengembalikan data yang dibuat.');
    }

    private function ubahBaris(string $table, int $id, array $attributes): void
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->patch($this->url($table).'?'.http_build_query(['id' => 'eq.'.$id]), $attributes)
            ->throw()
            ->json();

        if (! $rows) {
            throw new RuntimeException('Supabase tidak menemukan baris '.$table.' untuk diperbarui.');
        }
    }

    private function rpc(string $fungsi, array $arg): mixed
    {
        $response = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->post($this->url('rpc/'.$fungsi), $arg)
            ->throw();

        return $response->json();
    }

    private function request(): PendingRequest
    {
        $config = config('services.supabase.'.$this->database);
        abort_unless($config['url'] && $config['secret_key'], 503, 'Konfigurasi Supabase belum lengkap.');

        return Http::acceptJson()
            ->withHeaders([
                'apikey' => $config['secret_key'],
                'Authorization' => 'Bearer '.$config['secret_key'],
            ])
            ->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.supabase.'.$this->database.'.url'), '/').'/rest/v1/'.$path;
    }
}
