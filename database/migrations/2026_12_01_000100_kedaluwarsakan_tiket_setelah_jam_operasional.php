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
            CREATE OR REPLACE FUNCTION public.sp_kedaluwarsakan_etiket(p_id_pesanan bigint)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                v_status text;
                v_dipakai_pada timestamptz;
            BEGIN
                SELECT status INTO v_status
                FROM public.pesanan
                WHERE id_pesanan = p_id_pesanan
                FOR UPDATE;

                IF NOT FOUND OR v_status <> 'lunas' THEN
                    RETURN false;
                END IF;

                SELECT dipakai_pada INTO v_dipakai_pada
                FROM public.e_tiket
                WHERE id_pesanan = p_id_pesanan
                FOR UPDATE;

                IF FOUND AND v_dipakai_pada IS NOT NULL THEN
                    RETURN false;
                END IF;

                UPDATE public.pesanan
                SET status = 'kedaluwarsa', updated_at = now()
                WHERE id_pesanan = p_id_pesanan;

                RETURN true;
            END
            $$;

            CREATE OR REPLACE FUNCTION public.sp_pakai_etiket(
                p_id_pesanan bigint,
                p_dipakai_pada timestamptz
            )
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                v_status text;
            BEGIN
                SELECT status INTO v_status
                FROM public.pesanan
                WHERE id_pesanan = p_id_pesanan
                FOR UPDATE;

                IF NOT FOUND OR v_status <> 'lunas' THEN
                    RETURN false;
                END IF;

                UPDATE public.e_tiket
                SET dipakai_pada = p_dipakai_pada
                WHERE id_pesanan = p_id_pesanan
                  AND dipakai_pada IS NULL;

                RETURN FOUND;
            END
            $$;

            GRANT EXECUTE ON FUNCTION public.sp_kedaluwarsakan_etiket(bigint) TO service_role;
            GRANT EXECUTE ON FUNCTION public.sp_pakai_etiket(bigint, timestamptz) TO service_role;
            NOTIFY pgrst, 'reload schema';
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }

        $db->unprepared(<<<'SQL'
            REVOKE EXECUTE ON FUNCTION public.sp_kedaluwarsakan_etiket(bigint) FROM service_role;
            REVOKE EXECUTE ON FUNCTION public.sp_pakai_etiket(bigint, timestamptz) FROM service_role;
            DROP FUNCTION IF EXISTS public.sp_kedaluwarsakan_etiket(bigint);
            DROP FUNCTION IF EXISTS public.sp_pakai_etiket(bigint, timestamptz);
            NOTIFY pgrst, 'reload schema';
            SQL);
    }
};
