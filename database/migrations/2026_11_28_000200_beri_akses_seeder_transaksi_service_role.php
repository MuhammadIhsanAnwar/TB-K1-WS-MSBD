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

        // Dipakai hanya oleh WisatawanTransaksiSeeder melalui REST service_role.
        $db->unprepared(<<<'SQL'
            GRANT SELECT, INSERT, UPDATE ON TABLE
                public.pesanan, public.pesanan_item, public.pembayaran, public.e_tiket
                TO service_role;
            GRANT USAGE, SELECT ON SEQUENCE
                public.pesanan_id_pesanan_seq,
                public.pesanan_item_id_item_seq,
                public.pembayaran_id_pembayaran_seq,
                public.e_tiket_id_etiket_seq
                TO service_role;
            GRANT EXECUTE ON FUNCTION public.sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb)
                TO service_role;
            GRANT EXECUTE ON FUNCTION public.sp_bayar_pesanan(varchar, varchar)
                TO service_role;
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
            REVOKE SELECT, INSERT, UPDATE ON TABLE
                public.pesanan, public.pesanan_item, public.pembayaran, public.e_tiket
                FROM service_role;
            REVOKE USAGE, SELECT ON SEQUENCE
                public.pesanan_id_pesanan_seq,
                public.pesanan_item_id_item_seq,
                public.pembayaran_id_pembayaran_seq,
                public.e_tiket_id_etiket_seq
                FROM service_role;
            REVOKE EXECUTE ON FUNCTION public.sp_buat_pesanan(bigint, varchar, varchar, varchar, varchar, date, varchar, jsonb)
                FROM service_role;
            REVOKE EXECUTE ON FUNCTION public.sp_bayar_pesanan(varchar, varchar)
                FROM service_role;
            SQL);
    }
};
