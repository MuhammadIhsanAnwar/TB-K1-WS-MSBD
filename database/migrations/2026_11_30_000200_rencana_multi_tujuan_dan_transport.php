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
        $db = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('rencana_tujuan')) {
            $schema->create('rencana_tujuan', function (Blueprint $table): void {
                $table->bigInteger('id_rencana');
                $table->smallInteger('urutan');
                $table->string('tempat_slug', 120);
                $table->primary(['id_rencana', 'urutan']);
                $table->unique(['id_rencana', 'tempat_slug']);
                $table->foreign('id_rencana')->references('id_rencana')->on('rencana')->cascadeOnDelete();
            });
        }

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                ALTER TABLE rencana_moda DROP CONSTRAINT IF EXISTS ck_rencana_moda_nilai;
                UPDATE rencana_moda SET moda = 'motor' WHERE moda = 'becak';
                ALTER TABLE rencana_moda
                    ADD CONSTRAINT ck_rencana_moda_nilai CHECK (moda IN ('jalan', 'motor', 'mobil'));
                ALTER TABLE rencana_tujuan ENABLE ROW LEVEL SECURITY;
                ALTER TABLE rencana_tujuan
                    ADD CONSTRAINT ck_rencana_tujuan_urutan CHECK (urutan > 0);
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE rencana_tujuan TO service_role;

                DROP FUNCTION IF EXISTS fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[]);

                CREATE FUNCTION fn_simpan_rencana(
                    p_kode varchar, p_id_pengguna bigint, p_judul varchar, p_durasi_jam smallint,
                    p_moda text[], p_tempat_mulai_slug varchar, p_minat text[], p_tujuan text[])
                RETURNS SETOF rencana LANGUAGE plpgsql AS $$
                DECLARE
                    v_id bigint;
                BEGIN
                    IF COALESCE(cardinality(p_moda), 0) = 0
                       OR EXISTS (
                           SELECT 1 FROM unnest(p_moda) AS pilihan(moda)
                           WHERE moda IS NULL OR moda NOT IN ('jalan', 'motor', 'mobil')
                       ) THEN
                        RAISE EXCEPTION 'Pilih setidaknya satu moda perjalanan yang valid';
                    END IF;

                    IF COALESCE(cardinality(p_minat), 0) = 0
                       OR EXISTS (SELECT 1 FROM unnest(p_minat) AS pilihan(minat) WHERE minat IS NULL OR btrim(minat) = '')
                       OR COALESCE(cardinality(p_tujuan), 0) > 10
                       OR EXISTS (SELECT 1 FROM unnest(p_tujuan) AS pilihan(slug) WHERE slug IS NULL OR btrim(slug) = '')
                       OR (SELECT COUNT(*) <> COUNT(DISTINCT slug) FROM unnest(p_tujuan) AS pilihan(slug)) THEN
                        RAISE EXCEPTION 'Minat atau tempat tujuan rencana tidak valid';
                    END IF;

                    INSERT INTO rencana (kode, id_pengguna, judul, durasi_jam, tempat_mulai_slug, created_at, updated_at)
                    VALUES (p_kode, p_id_pengguna, p_judul, p_durasi_jam, p_tempat_mulai_slug, now(), now())
                    RETURNING id_rencana INTO v_id;

                    INSERT INTO rencana_moda (id_rencana, moda)
                    SELECT v_id, DISTINCT_moda
                    FROM (SELECT DISTINCT unnest(p_moda) AS DISTINCT_moda) AS moda_terpilih;

                    INSERT INTO rencana_minat (id_rencana, kode_kategori)
                    SELECT v_id, kategori
                    FROM (SELECT DISTINCT unnest(p_minat) AS kategori) AS minat_terpilih;

                    INSERT INTO rencana_tujuan (id_rencana, urutan, tempat_slug)
                    SELECT v_id, urutan::smallint, slug
                    FROM unnest(p_tujuan) WITH ORDINALITY AS tujuan(slug, urutan);

                    RETURN QUERY SELECT * FROM rencana WHERE id_rencana = v_id;
                END $$;

                CREATE FUNCTION fn_perbarui_rencana(
                    p_id_rencana bigint, p_id_pengguna bigint, p_judul varchar, p_durasi_jam smallint,
                    p_moda text[], p_tempat_mulai_slug varchar, p_minat text[], p_tujuan text[])
                RETURNS SETOF rencana LANGUAGE plpgsql AS $$
                DECLARE
                    v_id bigint;
                BEGIN
                    IF COALESCE(cardinality(p_moda), 0) = 0
                       OR EXISTS (
                           SELECT 1 FROM unnest(p_moda) AS pilihan(moda)
                           WHERE moda IS NULL OR moda NOT IN ('jalan', 'motor', 'mobil')
                       ) THEN
                        RAISE EXCEPTION 'Pilih setidaknya satu moda perjalanan yang valid';
                    END IF;

                    IF COALESCE(cardinality(p_minat), 0) = 0
                       OR EXISTS (SELECT 1 FROM unnest(p_minat) AS pilihan(minat) WHERE minat IS NULL OR btrim(minat) = '')
                       OR COALESCE(cardinality(p_tujuan), 0) > 10
                       OR EXISTS (SELECT 1 FROM unnest(p_tujuan) AS pilihan(slug) WHERE slug IS NULL OR btrim(slug) = '')
                       OR (SELECT COUNT(*) <> COUNT(DISTINCT slug) FROM unnest(p_tujuan) AS pilihan(slug)) THEN
                        RAISE EXCEPTION 'Minat atau tempat tujuan rencana tidak valid';
                    END IF;

                    UPDATE rencana
                    SET judul = p_judul, durasi_jam = p_durasi_jam,
                        tempat_mulai_slug = p_tempat_mulai_slug, updated_at = now()
                    WHERE id_rencana = p_id_rencana AND id_pengguna = p_id_pengguna
                    RETURNING id_rencana INTO v_id;

                    IF NOT FOUND THEN
                        RETURN;
                    END IF;

                    DELETE FROM rencana_moda WHERE id_rencana = v_id;
                    DELETE FROM rencana_minat WHERE id_rencana = v_id;
                    DELETE FROM rencana_tujuan WHERE id_rencana = v_id;

                    INSERT INTO rencana_moda (id_rencana, moda)
                    SELECT v_id, DISTINCT_moda
                    FROM (SELECT DISTINCT unnest(p_moda) AS DISTINCT_moda) AS moda_terpilih;

                    INSERT INTO rencana_minat (id_rencana, kode_kategori)
                    SELECT v_id, kategori
                    FROM (SELECT DISTINCT unnest(p_minat) AS kategori) AS minat_terpilih;

                    INSERT INTO rencana_tujuan (id_rencana, urutan, tempat_slug)
                    SELECT v_id, urutan::smallint, slug
                    FROM unnest(p_tujuan) WITH ORDINALITY AS tujuan(slug, urutan);

                    RETURN QUERY SELECT * FROM rencana WHERE id_rencana = v_id;
                END $$;

                CREATE FUNCTION fn_hapus_rencana(p_id_rencana bigint, p_id_pengguna bigint)
                RETURNS boolean LANGUAGE plpgsql AS $$
                BEGIN
                    DELETE FROM rencana
                    WHERE id_rencana = p_id_rencana AND id_pengguna = p_id_pengguna;
                    RETURN FOUND;
                END $$;

                REVOKE ALL ON FUNCTION fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[], text[]) FROM PUBLIC, anon, authenticated;
                REVOKE ALL ON FUNCTION fn_perbarui_rencana(bigint, bigint, varchar, smallint, text[], varchar, text[], text[]) FROM PUBLIC, anon, authenticated;
                REVOKE ALL ON FUNCTION fn_hapus_rencana(bigint, bigint) FROM PUBLIC, anon, authenticated;
                GRANT EXECUTE ON FUNCTION fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[], text[]) TO service_role;
                GRANT EXECUTE ON FUNCTION fn_perbarui_rencana(bigint, bigint, varchar, smallint, text[], varchar, text[], text[]) TO service_role;
                GRANT EXECUTE ON FUNCTION fn_hapus_rencana(bigint, bigint) TO service_role;
                NOTIFY pgrst, 'reload schema';
                SQL);
        } else {
            $db->table('rencana_moda')->where('moda', 'becak')->update(['moda' => 'motor']);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        $schema = Schema::connection($this->connection);

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                DROP FUNCTION IF EXISTS fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[], text[]);
                DROP FUNCTION IF EXISTS fn_perbarui_rencana(bigint, bigint, varchar, smallint, text[], varchar, text[], text[]);
                DROP FUNCTION IF EXISTS fn_hapus_rencana(bigint, bigint);
                DROP TABLE IF EXISTS rencana_tujuan;
                ALTER TABLE rencana_moda DROP CONSTRAINT IF EXISTS ck_rencana_moda_nilai;
                DELETE FROM rencana_moda motor
                USING rencana_moda mobil
                WHERE motor.id_rencana = mobil.id_rencana
                  AND motor.moda = 'motor' AND mobil.moda = 'mobil';
                UPDATE rencana_moda SET moda = 'becak' WHERE moda IN ('motor', 'mobil');
                ALTER TABLE rencana_moda
                    ADD CONSTRAINT ck_rencana_moda_nilai CHECK (moda IN ('jalan', 'becak'));

                CREATE FUNCTION fn_simpan_rencana(
                    p_kode varchar, p_id_pengguna bigint, p_judul varchar, p_durasi_jam smallint,
                    p_moda text[], p_tempat_mulai_slug varchar, p_minat text[])
                RETURNS SETOF rencana LANGUAGE plpgsql AS $$
                DECLARE
                    v_id bigint;
                BEGIN
                    IF COALESCE(cardinality(p_moda), 0) = 0
                       OR EXISTS (
                           SELECT 1 FROM unnest(p_moda) AS pilihan(moda)
                           WHERE moda IS NULL OR moda NOT IN ('jalan', 'becak')
                       ) THEN
                        RAISE EXCEPTION 'Pilih setidaknya satu moda perjalanan yang valid';
                    END IF;

                    INSERT INTO rencana (kode, id_pengguna, judul, durasi_jam, tempat_mulai_slug, created_at, updated_at)
                    VALUES (p_kode, p_id_pengguna, p_judul, p_durasi_jam, p_tempat_mulai_slug, now(), now())
                    RETURNING id_rencana INTO v_id;

                    INSERT INTO rencana_moda (id_rencana, moda)
                    SELECT v_id, DISTINCT_moda
                    FROM (SELECT DISTINCT unnest(p_moda) AS DISTINCT_moda) AS moda_terpilih;

                    INSERT INTO rencana_minat (id_rencana, kode_kategori)
                    SELECT v_id, kategori
                    FROM (SELECT DISTINCT unnest(p_minat) AS kategori) AS minat_terpilih;

                    RETURN QUERY SELECT * FROM rencana WHERE id_rencana = v_id;
                END $$;
                GRANT EXECUTE ON FUNCTION fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[])
                    TO service_role;
                NOTIFY pgrst, 'reload schema';
                SQL);
        } else {
            $db->table('rencana_moda')->whereIn('moda', ['motor', 'mobil'])->update(['moda' => 'becak']);
        }

        $schema->dropIfExists('rencana_tujuan');
    }
};
