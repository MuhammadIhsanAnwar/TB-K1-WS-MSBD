<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memperbaiki galat 42725 "function sp_buat_pesanan(...) is not unique" saat checkout dan pelunasan.
 *
 * Function pembungkus untuk Supabase REST (migration 2026_11_15_000100) bernama sama dengan
 * procedure-nya. Karena parameter INOUT terakhir procedure punya DEFAULT NULL, pemanggilan function
 * dengan 8 argumen (sp_buat_pesanan) atau 2 argumen (sp_bayar_pesanan) juga cocok dengan procedure,
 * sehingga PostgreSQL tidak bisa memilih. Procedure dibuat ulang tanpa DEFAULT; isinya tidak berubah
 * dan function pembungkus tetap memanggilnya dengan argumen lengkap.
 */
return new class extends Migration
{
    protected $connection = 'transaksi';

    private const PERAN = 'PUBLIC, anon, authenticated, service_role';

    private const ISI_BUAT = <<<'SQL'
        LANGUAGE plpgsql AS $$
        DECLARE
            v_id bigint;
        BEGIN
            IF jsonb_array_length(p_item) = 0 THEN
                RAISE EXCEPTION 'Pesanan harus berisi minimal satu tiket';
            END IF;
            IF p_tanggal < (now() AT TIME ZONE 'Asia/Jakarta')::date THEN
                RAISE EXCEPTION 'Tanggal kunjungan tidak boleh di masa lalu';
            END IF;
            p_kode := fn_kode_acak('MV-', 8);
            INSERT INTO pesanan (kode, id_pengguna, nama_pemesan, email_pemesan, hp_pemesan,
                                 tempat_slug, tanggal_kunjungan, metode, batas_bayar, created_at, updated_at)
            VALUES (p_kode, p_id_pengguna, p_nama, p_email, p_hp,
                    p_tempat_slug, p_tanggal, p_metode, now() + interval '15 minutes', now(), now())
            RETURNING id_pesanan INTO v_id;
            INSERT INTO pesanan_item (id_pesanan, jenis_pengunjung, harga, jumlah)
            SELECT v_id, x->>'jenis', (x->>'harga')::integer, (x->>'jumlah')::smallint
            FROM jsonb_array_elements(p_item) AS x
            WHERE (x->>'jumlah')::integer > 0;
        END $$
        SQL;

    private const ISI_BAYAR = <<<'SQL'
        LANGUAGE plpgsql AS $$
        DECLARE
            p pesanan%ROWTYPE;
        BEGIN
            SELECT * INTO p FROM pesanan WHERE kode = p_kode FOR UPDATE;
            IF p.id_pesanan IS NULL THEN
                RAISE EXCEPTION 'Pesanan % tidak ditemukan', p_kode;
            END IF;
            IF p.status <> 'menunggu' THEN
                RAISE EXCEPTION 'Pesanan % berstatus %, tidak bisa dibayar', p_kode, p.status;
            END IF;
            IF p.batas_bayar < now() THEN
                UPDATE pesanan SET status = 'kedaluwarsa', updated_at = now() WHERE id_pesanan = p.id_pesanan;
                RAISE NOTICE 'Pesanan % sudah lewat batas bayar dan ditandai kedaluwarsa', p_kode;
                RETURN;
            END IF;
            INSERT INTO pembayaran (id_pesanan, metode, jumlah, referensi)
            VALUES (p.id_pesanan, p.metode, p.total, p_referensi);
            UPDATE pesanan SET status = 'lunas', updated_at = now() WHERE id_pesanan = p.id_pesanan;
            p_kode_qr := fn_kode_acak('QR-', 12);
            INSERT INTO e_tiket (id_pesanan, kode_qr) VALUES (p.id_pesanan, p_kode_qr);
        END $$
        SQL;

    public function up(): void
    {
        $this->buatUlang(false);
    }

    public function down(): void
    {
        $this->buatUlang(true);
    }

    /** $default = true mengembalikan bentuk lama (dengan DEFAULT NULL, yang ambigu). */
    private function buatUlang(bool $default): void
    {
        $bawaan = $default ? ' DEFAULT NULL' : '';
        $peran = self::PERAN;
        $isiBuat = self::ISI_BUAT;
        $isiBayar = self::ISI_BAYAR;

        DB::connection($this->connection)->unprepared(<<<SQL
            DROP PROCEDURE IF EXISTS sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb, varchar);
            CREATE PROCEDURE sp_buat_pesanan(
                p_id_pengguna bigint, p_nama varchar, p_email varchar, p_hp varchar,
                p_tempat_slug varchar, p_tanggal date, p_metode varchar, p_item jsonb,
                INOUT p_kode varchar{$bawaan})
            {$isiBuat};

            DROP PROCEDURE IF EXISTS sp_bayar_pesanan(varchar, varchar, varchar);
