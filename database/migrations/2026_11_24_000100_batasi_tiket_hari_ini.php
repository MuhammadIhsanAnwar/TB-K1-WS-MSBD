<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP PROCEDURE IF EXISTS sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb, varchar);
            CREATE PROCEDURE sp_buat_pesanan(
                p_id_pengguna bigint, p_nama varchar, p_email varchar, p_hp varchar,
                p_tempat_slug varchar, p_tanggal date, p_metode varchar, p_item jsonb,
                INOUT p_kode varchar)
            LANGUAGE plpgsql AS $$
            DECLARE
                v_id bigint;
            BEGIN
                IF jsonb_array_length(p_item) = 0 THEN
                    RAISE EXCEPTION 'Pesanan harus berisi minimal satu tiket';
                END IF;
                IF p_tanggal <> (now() AT TIME ZONE 'Asia/Jakarta')::date THEN
                    RAISE EXCEPTION 'Tiket hanya dapat dipesan untuk hari ini';
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
            END $$;

            GRANT EXECUTE ON PROCEDURE sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb, varchar)
                TO PUBLIC, anon, authenticated, service_role;
            SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP PROCEDURE IF EXISTS sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb, varchar);
            CREATE PROCEDURE sp_buat_pesanan(
                p_id_pengguna bigint, p_nama varchar, p_email varchar, p_hp varchar,
                p_tempat_slug varchar, p_tanggal date, p_metode varchar, p_item jsonb,
                INOUT p_kode varchar)
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
            END $$;

            GRANT EXECUTE ON PROCEDURE sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb, varchar)
                TO PUBLIC, anon, authenticated, service_role;
            SQL);
    }
};
