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
