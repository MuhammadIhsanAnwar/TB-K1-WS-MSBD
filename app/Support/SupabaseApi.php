<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        return $this->semua('users', 'id,name,email,peran,tanggal_lahir,no_hp,email_verified_at,created_at,ditangguhkan_pada,alasan_tangguh');
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

    public function pesananDenganKodeQr(string $kodeQr): array
    {
        return $this->getRows('pesanan', [
            'select' => '*,pembayaran(dibayar_pada,referensi),e_tiket!inner(kode_qr,dipakai_pada)',
            'e_tiket.kode_qr' => 'eq.'.$kodeQr,
            'limit' => 1,
        ]);
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

    /** Membuat dan membayar transaksi demo secara atomik dan aman diulang lewat referensinya. */
    public function buatTransaksiDemo(array $arg): string
    {
        return (string) $this->rpc('sp_buat_transaksi_demo', $arg, true);
    }

    /** Membayar pesanan lewat function sp_bayar_pesanan (pembayaran + e-tiket dibuat database). */
    public function bayarPesanan(string $kode, ?string $referensi): ?string
    {
        return $this->rpc('sp_bayar_pesanan', ['p_kode' => $kode, 'p_referensi' => $referensi]);
    }

    /** Pesanan simulasi dari seeder, dipakai agar seeder aman dijalankan ulang. */
    public function pesananSeederDemo(): array
    {
        return $this->getRows('pesanan', [
            'select' => 'id_pesanan,tempat_slug,id_pengguna,pembayaran!inner(referensi)',
            'pembayaran.referensi' => 'like.SEED-DEMO-*',
            'limit' => 10000,
        ]);
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

    /** Cara bayar pesanan yang masih menunggu (dipilih pembeli di jendela Midtrans). */
    public function ubahMetodePesanan(string $kode, string $metode): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'return=minimal'])
            ->patch($this->url('pesanan').'?'.http_build_query(['kode' => 'eq.'.$kode, 'status' => 'eq.menunggu']), [
                'metode' => $metode,
                'updated_at' => now()->toIso8601String(),
            ])
            ->throw();
    }

    public function pakaiTiket(int $idPesanan, string $waktu): bool
    {
        $rows = $this->request()
            ->withHeaders(['Prefer' => 'return=representation'])
            ->patch($this->url('e_tiket').'?'.http_build_query(['id_pesanan' => 'eq.'.$idPesanan, 'dipakai_pada' => 'is.null']), [
                'dipakai_pada' => $waktu,
            ])
            ->throw()
            ->json();

        return is_array($rows) && $rows !== [];
    }

    public function ringkasanSukaTempat(): array
    {
        return $this->getRows('ringkasan_suka_tempat', [
            'select' => 'tempat_slug,jumlah_suka',
        ]);
    }

    public function ringkasanPopularitasTempat(string $slug): ?array
    {
        return $this->getRows('ringkasan_popularitas_tempat', [
            'select' => 'tempat_slug,jumlah_ulasan,rata_rata_ulasan,jumlah_rating,rata_rata_bintang,jumlah_suka',
            'tempat_slug' => 'eq.'.$slug,
            'limit' => 1,
        ])[0] ?? null;
    }

    public function peringkatUlasan(int $batas): array
    {
        $batas = max(1, min($batas, 100));

        return $this->getRows('ringkasan_popularitas_tempat', [
            'select' => 'tempat_slug,jumlah_ulasan,rata_rata_ulasan,jumlah_rating,rata_rata_bintang,peringkat_ulasan',
            'jumlah_ulasan' => 'gt.0',
            'order' => 'peringkat_ulasan.asc,tempat_slug.asc',
            'limit' => $batas,
        ]);
    }

    public function peringkatSuka(int $batas): array
    {
        $batas = max(1, min($batas, 1000));

        return $this->getRows('ringkasan_popularitas_tempat', [
            'select' => 'tempat_slug,jumlah_suka,peringkat_suka',
            'jumlah_suka' => 'gt.0',
            'order' => 'peringkat_suka.asc,tempat_slug.asc',
            'limit' => $batas,
        ]);
    }

    public function sukaTempatPengguna(int $idPengguna): array
    {
        return $this->getRows('suka_tempat', [
            'select' => 'tempat_slug',
            'id_pengguna' => 'eq.'.$idPengguna,
        ]);
    }

    public function sudahSukaTempat(int $idPengguna, string $slug): bool
    {
        return $this->getRows('suka_tempat', [
            'select' => 'tempat_slug',
            'id_pengguna' => 'eq.'.$idPengguna,
            'tempat_slug' => 'eq.'.$slug,
            'limit' => 1,
        ]) !== [];
    }

    public function tambahSukaTempat(int $idPengguna, string $slug): void
    {
        $this->upsertRows('suka_tempat', 'id_pengguna,tempat_slug', [[
            'id_pengguna' => $idPengguna,
            'tempat_slug' => $slug,
            'created_at' => now()->toIso8601String(),
        ]]);
    }

    public function hapusSukaTempat(int $idPengguna, string $slug): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'return=minimal'])
            ->delete($this->url('suka_tempat').'?'.http_build_query([
                'id_pengguna' => 'eq.'.$idPengguna,
                'tempat_slug' => 'eq.'.$slug,
            ]))
            ->throw();
    }

    public function ulasan(?string $tempat = null, ?string $status = null, ?int $idPengguna = null): array
    {
        $filters = ['select' => '*', 'order' => 'created_at.desc,id_ulasan.desc'];
        $tempat === null || $filters['tempat_slug'] = 'eq.'.$tempat;
        $status === null || $filters['status'] = 'eq.'.$status;
        $idPengguna === null || $filters['id_pengguna'] = 'eq.'.$idPengguna;

        return $this->sertakanRatingUlasan($this->getRows('ulasan', $filters));
    }

    public function ulasanHalaman(?array $tempatSlugs, string $status, int $batas, int $offset): array
    {
        $filters = [
            'select' => '*',
            'status' => 'eq.'.$status,
            'order' => 'created_at.desc,id_ulasan.desc',
            'limit' => max(1, min($batas, 100)),
            'offset' => max(0, $offset),
        ];

        if ($tempatSlugs !== null) {
            $tempatSlugs = array_values(array_unique(array_filter(
                $tempatSlugs,
                fn ($slug) => is_string($slug) && preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $slug),
            )));
            if ($tempatSlugs === []) {
                return ['baris' => [], 'jumlah' => 0];
            }
            $filters['tempat_slug'] = 'in.('.implode(',', $tempatSlugs).')';
        }

        $response = $this->request()
            ->withHeaders(['Prefer' => 'count=exact'])
            ->get($this->url('ulasan'), $filters)
            ->throw();

        return [
            'baris' => $this->sertakanRatingUlasan($response->json()),
            'jumlah' => $this->jumlahDariContentRange($response, 'ulasan'),
        ];
    }

    private function sertakanRatingUlasan(array $ulasan): array
    {
        if ($ulasan === []) {
            return [];
        }

        $idPengguna = array_values(array_unique(array_map(
            static fn (array $baris): int => (int) $baris['id_pengguna'],
            $ulasan,
        )));
        $tempat = array_values(array_unique(array_map(
            static fn (array $baris): string => (string) $baris['tempat_slug'],
            $ulasan,
        )));
        $rating = $this->getRows('rating_tempat', [
            'select' => 'id_pengguna,tempat_slug,bintang',
            'id_pengguna' => 'in.('.implode(',', $idPengguna).')',
            'tempat_slug' => 'in.('.implode(',', $tempat).')',
        ]);
        $ratingPerUlasan = [];
        foreach ($rating as $baris) {
            $ratingPerUlasan[$baris['id_pengguna'].'|'.$baris['tempat_slug']] = [
                'bintang' => (int) $baris['bintang'],
            ];
        }

        foreach ($ulasan as &$baris) {
            $baris['rating_tempat'] = $ratingPerUlasan[$baris['id_pengguna'].'|'.$baris['tempat_slug']] ?? [];
        }
        unset($baris);

        return $ulasan;
    }

    public function ringkasanUlasan(): array
    {
        return $this->getRows('ringkasan_ulasan', ['select' => 'tempat_slug,rata_rata_bintang,jumlah_ulasan']);
    }

    public function ringkasanRatingTempat(): array
    {
        return $this->getRows('ringkasan_rating_tempat', [
            'select' => 'tempat_slug,rata_rata_bintang,jumlah_rating',
        ]);
    }

    /** Upsert rating akun-tempat; konflik akun-tempat memperbarui rating yang sudah ada. */
    public function upsertRatingTempat(array $rows): void
    {
        $this->upsertRows('rating_tempat', 'id_pengguna,tempat_slug', $rows);
    }

    public function tulisUlasan(array $data): array
    {
        return $this->insert('ulasan', $data);
    }

    /** Upsert ulasan batch untuk seeder; konflik ditangani oleh indeks unik akun-tempat. */
    public function upsertUlasan(array $rows): void
    {
        $ratings = [];
        $ulasan = [];
        foreach ($rows as $row) {
            if (isset($row['bintang'])) {
                $ratings[] = [
                    'id_pengguna' => $row['id_pengguna'],
                    'tempat_slug' => $row['tempat_slug'],
                    'bintang' => $row['bintang'],
                    'created_at' => $row['created_at'] ?? now()->toIso8601String(),
                    'updated_at' => $row['updated_at'] ?? now()->toIso8601String(),
                ];
                unset($row['bintang']);
            }
            $ulasan[] = $row;
        }

        $this->upsertRatingTempat($ratings);
        $this->upsertRows('ulasan', 'id_pengguna,tempat_slug', $ulasan);
    }

    /** Upsert suka batch; memerlukan tabel suka_tempat pada DB 2. */
    public function upsertSukaTempat(array $rows): void
    {
        $this->upsertRows('suka_tempat', 'id_pengguna,tempat_slug', $rows);
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

        return $this->jumlahDariContentRange($response, 'log_pencarian');
    }

    public function jumlahPesanan(int $idPengguna): int
    {
        $response = $this->request()
            ->withHeaders(['Prefer' => 'count=exact', 'Range' => '0-0'])
            ->get($this->url('pesanan'), [
                'select' => 'id_pesanan',
                'id_pengguna' => 'eq.'.$idPengguna,
            ])
            ->throw();

        return $this->jumlahDariContentRange($response, 'pesanan');
    }

    public function profilPengguna(int $idPengguna): ?array
    {
        return $this->getRows('users', [
            'select' => 'id,name,email,peran',
            'id' => 'eq.'.$idPengguna,
            'limit' => 1,
        ])[0] ?? null;
    }

    public function riwayatPesanan(int $idPengguna, int $batas): array
    {
        $batas = max(1, min($batas, 20));

        return $this->getRows('pesanan', [
            'select' => 'kode,tempat_slug,tanggal_kunjungan,total,status,metode,created_at',
            'id_pengguna' => 'eq.'.$idPengguna,
            'order' => 'created_at.desc,id_pesanan.desc',
            'limit' => $batas,
        ]);
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

    public function rencanaModa(array $idRencana): array
    {
        if (! $idRencana) {
            return [];
        }

        return $this->getRows('rencana_moda', [
            'select' => 'id_rencana,moda',
            'id_rencana' => 'in.('.implode(',', array_map('intval', $idRencana)).')',
            'order' => 'id_rencana.asc,moda.asc',
        ]);
    }

    public function rencanaTujuan(array $idRencana): array
    {
        if (! $idRencana) {
            return [];
        }

        return $this->getRows('rencana_tujuan', [
            'select' => 'id_rencana,urutan,tempat_slug',
            'id_rencana' => 'in.('.implode(',', array_map('intval', $idRencana)).')',
            'order' => 'id_rencana.asc,urutan.asc',
        ]);
    }

    /** Rencana + minatnya dibuat dalam satu function database (transaksi). */
    public function simpanRencana(array $arg): array
    {
        $rows = $this->rpc('fn_simpan_rencana', $arg);

        return is_array($rows) && isset($rows[0]) && is_array($rows[0])
            ? $rows[0]
            : (array) $rows;
    }

    public function perbaruiRencana(array $arg): ?array
    {
        $rows = $this->rpc('fn_perbarui_rencana', $arg);

        return is_array($rows) && isset($rows[0]) && is_array($rows[0])
            ? $rows[0]
            : null;
    }

    public function hapusRencana(int $idRencana, int $idPengguna): bool
    {
        return $this->rpc('fn_hapus_rencana', [
            'p_id_rencana' => $idRencana,
            'p_id_pengguna' => $idPengguna,
        ]) === true;
    }

    // ---------- utilitas ----------

    // ---------- pengaturan situs (DB 1) ----------

    /** Nilai JSON satu kunci pengaturan, atau null kalau belum pernah diatur. */
    public function pengaturanSitus(string $kunci): mixed
    {
        return $this->getRows('pengaturan_situs', ['kunci' => 'eq.'.$kunci, 'limit' => 1])[0]['nilai'] ?? null;
    }

    /** Pengaturan dengan awalan kunci tertentu, misalnya override status operasional tempat. */
    public function pengaturanSitusAwalan(string $awalan): array
    {
        return $this->getRows('pengaturan_situs', [
            'select' => 'kunci,nilai',
            'kunci' => 'like.'.$awalan.'*',
        ]);
    }

    /** Simpan (buat atau timpa) satu kunci pengaturan. */
    public function simpanPengaturanSitus(string $kunci, mixed $nilai, ?int $idPengguna): void
    {
        $this->request()
            ->withHeaders(['Prefer' => 'resolution=merge-duplicates,return=minimal'])
            ->post($this->url('pengaturan_situs').'?on_conflict=kunci', [
                'kunci' => $kunci,
                'nilai' => $nilai,
                'diubah_oleh' => $idPengguna,
                'updated_at' => now()->toIso8601String(),
            ])
            ->throw();
    }

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

    private function jumlahDariContentRange(Response $response, string $tabel): int
    {
        $rentang = $response->header('Content-Range');
        if (! is_string($rentang) || ! preg_match('~/(\d+|\*)$~', $rentang, $cocok)) {
            throw new RuntimeException('Supabase tidak mengembalikan jumlah baris untuk '.$tabel.'.');
        }

        return $cocok[1] === '*' ? 0 : (int) $cocok[1];
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

    private function upsertRows(string $table, string $konflik, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->requestForIdempotentWrite()
            ->withHeaders(['Prefer' => 'resolution=merge-duplicates,return=minimal'])
            ->post($this->url($table).'?'.http_build_query(['on_conflict' => $konflik]), $rows)
            ->throw();
    }

    private function requestForIdempotentWrite(): PendingRequest
    {
        return $this->request()->retry(
            [1000, 2000, 5000, 10000],
            when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && (
                    $exception->response->serverError()
                    || $exception->response->tooManyRequests()
                )),
        );
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

    private function rpc(string $fungsi, array $arg, bool $idempoten = false): mixed
    {
        $request = $idempoten ? $this->requestForIdempotentWrite() : $this->request();
        $response = $request
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
            // connectTimeout membatasi tahap membuka koneksi; timeout membatasi seluruh request.
            // Seeder mengirim banyak batch ke Supabase sehingga keduanya perlu lebih longgar dari
            // nilai bawaan cURL (10 detik) dan dapat disetel tanpa mengubah kode.
            ->connectTimeout($config['connect_timeout'] ?? 30)
            ->timeout($config['timeout'] ?? 120);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.supabase.'.$this->database.'.url'), '/').'/rest/v1/'.$path;
    }
}
