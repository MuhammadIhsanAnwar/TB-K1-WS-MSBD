<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);

        $schema->create('rating_tempat', function (Blueprint $table): void {
            $table->bigInteger('id_pengguna');
            $table->string('tempat_slug', 80);
            $table->smallInteger('bintang');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['id_pengguna', 'tempat_slug']);
            $table->index('tempat_slug');
        });

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('ALTER TABLE rating_tempat ADD CONSTRAINT ck_rating_tempat_bintang CHECK (bintang BETWEEN 1 AND 5)');
        }

        $db->statement(
            'CREATE VIEW ringkasan_rating_tempat AS '
            .'SELECT tempat_slug, COUNT(*) AS jumlah_rating, ROUND(AVG(bintang), 2) AS rata_rata_bintang '
            .'FROM rating_tempat GROUP BY tempat_slug'
        );

        $db->statement(
            'INSERT INTO rating_tempat (id_pengguna, tempat_slug, bintang, created_at, updated_at) '
            .'SELECT id_pengguna, tempat_slug, bintang, created_at, updated_at '
            .'FROM ulasan WHERE status = ? '
            .'ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE SET '
            .'bintang = EXCLUDED.bintang, updated_at = EXCLUDED.updated_at',
            ['tayang'],
        );

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fn_sinkron_rating_tempat()
                RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        DELETE FROM rating_tempat
                        WHERE id_pengguna = OLD.id_pengguna AND tempat_slug = OLD.tempat_slug;
                        RETURN OLD;
                    END IF;

                    IF TG_OP = 'UPDATE' AND OLD.status = 'tayang'
                       AND (NEW.status <> 'tayang'
                            OR OLD.id_pengguna IS DISTINCT FROM NEW.id_pengguna
                            OR OLD.tempat_slug IS DISTINCT FROM NEW.tempat_slug) THEN
                        DELETE FROM rating_tempat
                        WHERE id_pengguna = OLD.id_pengguna AND tempat_slug = OLD.tempat_slug;
                    END IF;

                    IF NEW.status = 'tayang' THEN
                        INSERT INTO rating_tempat (id_pengguna, tempat_slug, bintang, created_at, updated_at)
                        VALUES (NEW.id_pengguna, NEW.tempat_slug, NEW.bintang, NEW.created_at, NEW.updated_at)
                        ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
                            SET bintang = EXCLUDED.bintang, updated_at = EXCLUDED.updated_at;
                    END IF;

                    RETURN NEW;
                END $$;

                CREATE TRIGGER ulasan_sinkron_rating_tempat
                    AFTER INSERT OR UPDATE OR DELETE ON ulasan
                    FOR EACH ROW EXECUTE FUNCTION fn_sinkron_rating_tempat();
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS ulasan_sinkron_rating_tempat ON ulasan;
                DROP FUNCTION IF EXISTS fn_sinkron_rating_tempat();
                SQL);
        }

        $db->statement('DROP VIEW IF EXISTS ringkasan_rating_tempat');
        Schema::connection($this->connection)->dropIfExists('rating_tempat');
    }
};
