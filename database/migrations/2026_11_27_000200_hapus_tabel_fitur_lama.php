<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        $db = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('DROP VIEW IF EXISTS v_saldo_poin');
            if ($schema->hasTable('penukaran')) {
                $db->unprepared(<<<'SQL'
                    DROP TRIGGER IF EXISTS penukaran_poin ON penukaran;
                    DROP TRIGGER IF EXISTS penukaran_cek ON penukaran;
                    SQL);
            }
            if ($schema->hasTable('checkin')) {
                $db->statement('DROP TRIGGER IF EXISTS checkin_poin ON checkin');
            }
            $db->unprepared(<<<'SQL'
                DROP FUNCTION IF EXISTS trg_penukaran_poin();
                DROP FUNCTION IF EXISTS trg_penukaran_cek();
                DROP FUNCTION IF EXISTS trg_checkin_poin();
                DROP FUNCTION IF EXISTS fn_saldo_poin(bigint);
                SQL);
        }

        foreach ([
            'penukaran',
            'voucher',
            'riwayat_poin',
            'checkin',
            'menu_usaha',
            'usaha_mitra',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Tabel fitur mitra, check-in, poin, voucher, dan penukaran telah dipensiunkan; pulihkan backup untuk mengembalikan datanya.'
        );
    }
};
