<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.sp_buat_transaksi_demo(
                p_referensi varchar,
                p_id_pengguna bigint,
                p_nama varchar,
                p_email varchar,
                p_hp varchar,
                p_tempat_slug varchar,
                p_tanggal date,
                p_metode varchar,
                p_item jsonb
            )
            RETURNS varchar
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_kode varchar;
                v_kode_qr varchar;
            BEGIN
                IF p_referensi IS NULL OR btrim(p_referensi) = '' THEN
                    RAISE EXCEPTION 'Referensi transaksi demo wajib diisi';
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended(p_referensi, 0));

                SELECT p.kode
                INTO v_kode
                FROM public.pembayaran AS b
                JOIN public.pesanan AS p ON p.id_pesanan = b.id_pesanan
                WHERE b.referensi = p_referensi
                LIMIT 1;

                IF v_kode IS NOT NULL THEN
                    RETURN v_kode;
                END IF;

                CALL public.sp_buat_pesanan(
                    p_id_pengguna, p_nama, p_email, p_hp, p_tempat_slug,
                    p_tanggal, p_metode, p_item, v_kode
                );
                CALL public.sp_bayar_pesanan(v_kode, p_referensi, v_kode_qr);

                IF v_kode_qr IS NULL THEN
                    RAISE EXCEPTION 'Transaksi demo % tidak berhasil dibayar', p_referensi;
                END IF;

                RETURN v_kode;
            END
            $$;

            GRANT EXECUTE ON FUNCTION public.sp_buat_transaksi_demo(
                varchar, bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb
            ) TO service_role;
            NOTIFY pgrst, 'reload schema';
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->statement(
                'DROP FUNCTION IF EXISTS public.sp_buat_transaksi_demo(varchar, bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb)'
            );
        }
    }
};
