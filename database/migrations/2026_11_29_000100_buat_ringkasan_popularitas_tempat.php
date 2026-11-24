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
            CREATE OR REPLACE VIEW public.ringkasan_popularitas_tempat AS
            WITH metrik AS (
                SELECT
                    COALESCE(u.tempat_slug, r.tempat_slug, s.tempat_slug) AS tempat_slug,
                    COALESCE(u.jumlah_ulasan, 0)::bigint AS jumlah_ulasan,
                    u.rata_rata_bintang AS rata_rata_ulasan,
                    COALESCE(r.jumlah_rating, 0)::bigint AS jumlah_rating,
                    r.rata_rata_bintang,
                    COALESCE(s.jumlah_suka, 0)::bigint AS jumlah_suka
                FROM public.ringkasan_ulasan AS u
                FULL OUTER JOIN public.ringkasan_rating_tempat AS r USING (tempat_slug)
                FULL OUTER JOIN public.ringkasan_suka_tempat AS s USING (tempat_slug)
            ),
            peringkat AS (
                SELECT
                    metrik.*,
                    DENSE_RANK() OVER (
                        ORDER BY jumlah_ulasan DESC, rata_rata_ulasan DESC NULLS LAST
                    ) AS peringkat_ulasan,
                    DENSE_RANK() OVER (
                        ORDER BY jumlah_rating DESC, rata_rata_bintang DESC NULLS LAST
                    ) AS peringkat_rating,
                    DENSE_RANK() OVER (ORDER BY jumlah_suka DESC) AS peringkat_suka
                FROM metrik
            )
            SELECT *
            FROM peringkat;

            GRANT USAGE ON SCHEMA public TO service_role;
            GRANT SELECT ON TABLE public.ringkasan_popularitas_tempat TO service_role;
            NOTIFY pgrst, 'reload schema';
            SQL);
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->statement('DROP VIEW IF EXISTS public.ringkasan_popularitas_tempat');
            $db->statement("NOTIFY pgrst, 'reload schema'");
        }
    }
};
