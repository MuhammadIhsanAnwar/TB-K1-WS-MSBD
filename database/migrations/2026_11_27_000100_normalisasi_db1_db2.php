<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'katalog';

    public function up(): void
    {
        $katalog = DB::connection('katalog');
        $schemaKatalog = Schema::connection('katalog');

        if ($schemaKatalog->hasColumn('users', 'tempat_slug')) {
            foreach ($katalog->table('users')
                ->where('peran', 'admin')
                ->whereNotNull('tempat_slug')
                ->get(['id', 'tempat_slug']) as $admin) {
                $katalog->table('admin_tempat')->insertOrIgnore([
                    'id_admin' => $admin->id,
                    'tempat_slug' => $admin->tempat_slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $schemaKatalog->table('users', function (Blueprint $table): void {
                $table->dropUnique('users_tempat_slug_unique');
                $table->dropColumn('tempat_slug');
            });
            if ($katalog->getDriverName() === 'pgsql') {
                $katalog->statement("NOTIFY pgrst, 'reload schema'");
            }
        }

        $db = DB::connection('transaksi');
        $schema = Schema::connection('transaksi');

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS ulasan_sinkron_rating_tempat ON ulasan;
                DROP FUNCTION IF EXISTS fn_sinkron_rating_tempat();
                DROP TRIGGER IF EXISTS ulasan_ringkasan ON ulasan;
                DROP FUNCTION IF EXISTS trg_ulasan_ringkasan();
                DROP FUNCTION IF EXISTS fn_hitung_ringkasan_ulasan(varchar);
                DROP FUNCTION IF EXISTS fn_hitung_ringkasan_ulasan(integer);
                DROP FUNCTION IF EXISTS fn_simpan_rencana(varchar, bigint, varchar, smallint, boolean, boolean, varchar, text[]);
                SQL);
        }

        $schema->dropIfExists('ringkasan_ulasan');

        $db->statement(
            'INSERT INTO rating_tempat (id_pengguna, tempat_slug, bintang, created_at, updated_at) '
            .'SELECT id_pengguna, tempat_slug, bintang, created_at, updated_at FROM ulasan '
            .'WHERE 1 = 1 ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE '
            .'SET bintang = EXCLUDED.bintang, updated_at = EXCLUDED.updated_at'
        );

        $schema->table('ulasan', function (Blueprint $table): void {
            $table->foreign(['id_pengguna', 'tempat_slug'], 'fk_ulasan_rating_tempat')
                ->references(['id_pengguna', 'tempat_slug'])
                ->on('rating_tempat')
                ->cascadeOnDelete();
            $table->dropColumn('bintang');
        });

        $db->statement(
            "CREATE VIEW ringkasan_ulasan AS "
            ."SELECT u.tempat_slug, COUNT(*) AS jumlah_ulasan, ROUND(AVG(r.bintang), 2) AS rata_rata_bintang "
            ."FROM ulasan u JOIN rating_tempat r "
            ."ON r.id_pengguna = u.id_pengguna AND r.tempat_slug = u.tempat_slug "
            ."WHERE u.status = 'tayang' GROUP BY u.tempat_slug"
        );

        if (! $schema->hasTable('rencana_moda')) {
            $schema->create('rencana_moda', function (Blueprint $table): void {
                $table->bigInteger('id_rencana');
                $table->string('moda', 12);
                $table->primary(['id_rencana', 'moda']);
                $table->foreign('id_rencana')->references('id_rencana')->on('rencana')->cascadeOnDelete();
            });
        }

        foreach ($db->table('rencana')->get(['id_rencana', 'moda_jalan', 'moda_becak']) as $rencana) {
            foreach (['jalan' => 'moda_jalan', 'becak' => 'moda_becak'] as $moda => $kolom) {
                if ($rencana->{$kolom}) {
                    $db->table('rencana_moda')->insertOrIgnore([
                        'id_rencana' => $rencana->id_rencana,
                        'moda' => $moda,
                    ]);
                }
            }
        }

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('ALTER TABLE rencana DROP CONSTRAINT IF EXISTS ck_rencana_moda');
        }
        $schema->table('rencana', function (Blueprint $table): void {
            $table->dropColumn(['moda_jalan', 'moda_becak']);
        });

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                ALTER TABLE rencana_moda
                    ADD CONSTRAINT ck_rencana_moda_nilai CHECK (moda IN ('jalan', 'becak'));

                CREATE FUNCTION fn_simpan_rencana(
                    p_kode varchar, p_id_pengguna bigint, p_judul varchar, p_durasi_jam smallint,
                    p_moda text[], p_tempat_mulai_slug varchar, p_minat text[])
                RETURNS SETOF rencana LANGUAGE plpgsql AS $$
                DECLARE
                    v_id bigint;
                BEGIN
                    IF COALESCE(array_length(p_moda, 1), 0) = 0
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

                GRANT SELECT, INSERT, UPDATE, DELETE ON rencana_moda, rating_tempat TO anon, authenticated, service_role;
                GRANT SELECT ON ringkasan_ulasan TO anon, authenticated, service_role;
                GRANT EXECUTE ON FUNCTION fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[])
                    TO anon, authenticated, service_role;
                NOTIFY pgrst, 'reload schema';
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection('transaksi');
        $schema = Schema::connection('transaksi');

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('DROP FUNCTION IF EXISTS fn_simpan_rencana(varchar, bigint, varchar, smallint, text[], varchar, text[])');
        }

        $db->statement('DROP VIEW IF EXISTS ringkasan_ulasan');
        $schema->create('ringkasan_ulasan', function (Blueprint $table): void {
            $table->string('tempat_slug', 80)->primary();
            $table->integer('jumlah_ulasan')->default(0);
            $table->decimal('rata_rata_bintang', 3, 2)->nullable();
            $table->timestampTz('diperbarui_pada')->useCurrent();
        });

        $db->statement('ALTER TABLE ulasan DROP CONSTRAINT IF EXISTS fk_ulasan_rating_tempat');
        $schema->table('ulasan', function (Blueprint $table): void {
            $table->smallInteger('bintang')->nullable();
        });
        $db->statement(
            'UPDATE ulasan SET bintang = (SELECT bintang FROM rating_tempat r '
            .'WHERE r.id_pengguna = ulasan.id_pengguna AND r.tempat_slug = ulasan.tempat_slug)'
        );
        $db->statement('ALTER TABLE ulasan ALTER COLUMN bintang SET NOT NULL');
        if ($db->getDriverName() === 'pgsql') {
            $db->statement('ALTER TABLE ulasan ADD CONSTRAINT ck_ulasan_bintang CHECK (bintang BETWEEN 1 AND 5)');
        }

        $db->statement(
            'INSERT INTO ringkasan_ulasan (tempat_slug, jumlah_ulasan, rata_rata_bintang, diperbarui_pada) '
            .'SELECT u.tempat_slug, COUNT(*), ROUND(AVG(u.bintang), 2), now() FROM ulasan u '
            .'WHERE u.status = ? GROUP BY u.tempat_slug',
            ['tayang'],
        );

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                CREATE FUNCTION fn_hitung_ringkasan_ulasan(p_tempat_slug varchar)
                RETURNS void LANGUAGE sql AS $$
                    INSERT INTO ringkasan_ulasan (tempat_slug, jumlah_ulasan, rata_rata_bintang, diperbarui_pada)
                    SELECT p_tempat_slug, count(*), round(avg(bintang), 2), now()
                    FROM ulasan WHERE tempat_slug = p_tempat_slug AND status = 'tayang'
                    ON CONFLICT (tempat_slug) DO UPDATE
                        SET jumlah_ulasan = EXCLUDED.jumlah_ulasan,
                            rata_rata_bintang = EXCLUDED.rata_rata_bintang,
                            diperbarui_pada = EXCLUDED.diperbarui_pada;
                $$;

                CREATE FUNCTION trg_ulasan_ringkasan() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP IN ('UPDATE', 'DELETE') THEN
                        PERFORM fn_hitung_ringkasan_ulasan(OLD.tempat_slug);
                    END IF;
                    IF TG_OP IN ('INSERT', 'UPDATE') THEN
                        PERFORM fn_hitung_ringkasan_ulasan(NEW.tempat_slug);
                    END IF;
                    RETURN NULL;
                END $$;

                CREATE TRIGGER ulasan_ringkasan
                    AFTER INSERT OR UPDATE OF status, bintang, tempat_slug OR DELETE ON ulasan
                    FOR EACH ROW EXECUTE FUNCTION trg_ulasan_ringkasan();

                CREATE FUNCTION fn_sinkron_rating_tempat()
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
                GRANT SELECT, INSERT, UPDATE, DELETE ON ringkasan_ulasan TO anon, authenticated, service_role;
                SQL);
        }

        $schema->table('rencana', function (Blueprint $table): void {
            $table->boolean('moda_jalan')->default(true);
            $table->boolean('moda_becak')->default(false);
        });
        foreach ($db->table('rencana')->get(['id_rencana']) as $rencana) {
            $moda = $db->table('rencana_moda')->where('id_rencana', $rencana->id_rencana)->pluck('moda')->all();
            $db->table('rencana')->where('id_rencana', $rencana->id_rencana)->update([
                'moda_jalan' => in_array('jalan', $moda, true),
                'moda_becak' => in_array('becak', $moda, true),
            ]);
        }
        if ($db->getDriverName() === 'pgsql') {
            $db->statement('ALTER TABLE rencana ADD CONSTRAINT ck_rencana_moda CHECK (moda_jalan OR moda_becak)');
        }
        $schema->dropIfExists('rencana_moda');

        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                CREATE FUNCTION fn_simpan_rencana(
                    p_kode varchar, p_id_pengguna bigint, p_judul varchar, p_durasi_jam smallint,
                    p_moda_jalan boolean, p_moda_becak boolean, p_tempat_mulai_slug varchar, p_minat text[])
                RETURNS SETOF rencana LANGUAGE plpgsql AS $$
                DECLARE
                    v_id bigint;
                BEGIN
                    INSERT INTO rencana (kode, id_pengguna, judul, durasi_jam, moda_jalan, moda_becak,
                                         tempat_mulai_slug, created_at, updated_at)
                    VALUES (p_kode, p_id_pengguna, p_judul, p_durasi_jam, p_moda_jalan, p_moda_becak,
                            p_tempat_mulai_slug, now(), now())
                    RETURNING id_rencana INTO v_id;
                    INSERT INTO rencana_minat (id_rencana, kode_kategori)
                    SELECT v_id, kategori FROM (SELECT DISTINCT unnest(p_minat) AS kategori) AS minat_terpilih;
                    RETURN QUERY SELECT * FROM rencana WHERE id_rencana = v_id;
                END $$;
                GRANT EXECUTE ON FUNCTION fn_simpan_rencana(varchar, bigint, varchar, smallint, boolean, boolean, varchar, text[])
                    TO anon, authenticated, service_role;
                SQL);
            $db->statement("NOTIFY pgrst, 'reload schema'");
        }

        $katalog = DB::connection('katalog');
        $schemaKatalog = Schema::connection('katalog');
        if (! $schemaKatalog->hasColumn('users', 'tempat_slug')) {
            $schemaKatalog->table('users', function (Blueprint $table): void {
                $table->string('tempat_slug', 120)->nullable()->unique();
            });
            foreach ($katalog->table('admin_tempat')->orderBy('tempat_slug')->get(['id_admin', 'tempat_slug']) as $relasi) {
                $katalog->table('users')->where('id', $relasi->id_admin)
                    ->whereNull('tempat_slug')
                    ->update(['tempat_slug' => $relasi->tempat_slug]);
            }
            if ($katalog->getDriverName() === 'pgsql') {
                $katalog->statement("NOTIFY pgrst, 'reload schema'");
            }
        }
    }
};
