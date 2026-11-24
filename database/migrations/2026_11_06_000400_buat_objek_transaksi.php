<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * DB 2 (koneksi "transaksi"): index, view, function, trigger, dan procedure aktif.
 * Aturan bisnis yang dulu ditulis di App\Support\Penyimpanan dipindah ke sini supaya tetap berlaku
 * walaupun data diubah dari luar aplikasi (misalnya lewat DBeaver).
 */
return new class extends Migration
{
    protected $connection = 'transaksi';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- ---------- index ----------
            CREATE INDEX ix_pesanan_pengguna   ON pesanan (id_pengguna);
            CREATE INDEX ix_pesanan_menunggu   ON pesanan (batas_bayar) WHERE status = 'menunggu';
            CREATE INDEX ix_pesanan_tempat     ON pesanan (id_tempat, tanggal_kunjungan);
            CREATE INDEX ix_ulasan_tempat      ON ulasan (id_tempat, status);
            CREATE INDEX ix_log_tanpa_hasil    ON log_pencarian (kata_kunci) WHERE jumlah_hasil = 0;

            -- ---------- function ----------
            -- kode acak huruf besar dan angka, misalnya fn_kode_acak('MV-', 8) -> MV-7K2Q9XZA
            CREATE OR REPLACE FUNCTION fn_kode_acak(p_awalan text, p_panjang integer)
            RETURNS text LANGUAGE sql VOLATILE AS $$
                SELECT p_awalan || upper(substr(md5(random()::text || clock_timestamp()::text), 1, p_panjang))
            $$;

            -- ---------- view ----------
            CREATE OR REPLACE VIEW v_penjualan_tiket AS
                SELECT p.id_tempat, date_trunc('month', p.tanggal_kunjungan)::date AS bulan,
                       count(*) AS jumlah_pesanan,
                       sum(i.jumlah_orang) AS jumlah_pengunjung,
                       sum(p.total) AS pendapatan
                FROM pesanan p
                JOIN (SELECT id_pesanan, sum(jumlah) AS jumlah_orang FROM pesanan_item GROUP BY id_pesanan) i
                  ON i.id_pesanan = p.id_pesanan
                WHERE p.status = 'lunas'
                GROUP BY p.id_tempat, date_trunc('month', p.tanggal_kunjungan);

            -- kata kunci yang dicari tapi tidak ada hasilnya: bahan kurator menambah konten
            CREATE OR REPLACE VIEW v_celah_konten AS
                SELECT kata_kunci, count(*) AS jumlah_dicari, max(waktu) AS terakhir_dicari
                FROM log_pencarian
                WHERE jumlah_hasil = 0
                GROUP BY kata_kunci;

            -- ---------- trigger ----------
            -- total pesanan selalu sama dengan jumlah subtotal itemnya
            CREATE OR REPLACE FUNCTION trg_pesanan_hitung_total() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_id bigint := COALESCE(NEW.id_pesanan, OLD.id_pesanan);
            BEGIN
                UPDATE pesanan
                SET total = (SELECT COALESCE(sum(subtotal), 0) FROM pesanan_item WHERE id_pesanan = v_id),
                    updated_at = now()
                WHERE id_pesanan = v_id;
                RETURN NULL;
            END $$;

            CREATE TRIGGER pesanan_item_total
                AFTER INSERT OR UPDATE OR DELETE ON pesanan_item
                FOR EACH ROW EXECUTE FUNCTION trg_pesanan_hitung_total();

            -- rating tempat dihitung ulang setiap ada ulasan baru, diubah statusnya, atau dihapus
            CREATE OR REPLACE FUNCTION fn_hitung_ringkasan_ulasan(p_id_tempat integer)
            RETURNS void LANGUAGE sql AS $$
                INSERT INTO ringkasan_ulasan (id_tempat, jumlah_ulasan, rata_rata_bintang, diperbarui_pada)
                SELECT p_id_tempat, count(*), round(avg(bintang), 2), now()
                FROM ulasan WHERE id_tempat = p_id_tempat AND status = 'tayang'
                ON CONFLICT (id_tempat) DO UPDATE
                    SET jumlah_ulasan = EXCLUDED.jumlah_ulasan,
                        rata_rata_bintang = EXCLUDED.rata_rata_bintang,
                        diperbarui_pada = EXCLUDED.diperbarui_pada;
            $$;

            CREATE OR REPLACE FUNCTION trg_ulasan_ringkasan() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    PERFORM fn_hitung_ringkasan_ulasan(OLD.id_tempat);
                END IF;
                IF TG_OP IN ('INSERT', 'UPDATE') THEN
                    PERFORM fn_hitung_ringkasan_ulasan(NEW.id_tempat);
                END IF;
                RETURN NULL;
            END $$;

            CREATE TRIGGER ulasan_ringkasan
                AFTER INSERT OR UPDATE OF status, bintang, id_tempat OR DELETE ON ulasan
                FOR EACH ROW EXECUTE FUNCTION trg_ulasan_ringkasan();

            -- ---------- procedure ----------
            -- buat pesanan beserta item tiketnya dalam satu transaksi; batas bayar 15 menit
            -- p_item: [{"jenis": "Dewasa", "harga": 10000, "jumlah": 2}, ...]
            -- (harga diambil aplikasi dari tabel tiket di DB 1, karena DB 2 tidak bisa membaca DB 1)
            CREATE OR REPLACE PROCEDURE sp_buat_pesanan(
                p_id_pengguna bigint, p_nama varchar, p_email varchar, p_hp varchar,
                p_id_tempat integer, p_tanggal date, p_metode varchar, p_item jsonb,
                INOUT p_kode varchar DEFAULT NULL)
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
                                     id_tempat, tanggal_kunjungan, metode, batas_bayar, created_at, updated_at)
                VALUES (p_kode, p_id_pengguna, p_nama, p_email, p_hp,
                        p_id_tempat, p_tanggal, p_metode, now() + interval '15 minutes', now(), now())
                RETURNING id_pesanan INTO v_id;

                INSERT INTO pesanan_item (id_pesanan, jenis_pengunjung, harga, jumlah)
                SELECT v_id, x->>'jenis', (x->>'harga')::integer, (x->>'jumlah')::smallint
                FROM jsonb_array_elements(p_item) AS x
                WHERE (x->>'jumlah')::integer > 0;
            END $$;

            -- bayar pesanan: catat pembayaran, ubah status menjadi lunas, terbitkan e-tiket.
            -- Pesanan yang sudah lewat batas bayar diubah menjadi kedaluwarsa dan tidak bisa dibayar.
            CREATE OR REPLACE PROCEDURE sp_bayar_pesanan(p_kode varchar, p_referensi varchar DEFAULT NULL,
                                                         INOUT p_kode_qr varchar DEFAULT NULL)
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
                    RETURN;
                END IF;

                INSERT INTO pembayaran (id_pesanan, metode, jumlah, referensi)
                VALUES (p.id_pesanan, p.metode, p.total, p_referensi);
                UPDATE pesanan SET status = 'lunas', updated_at = now() WHERE id_pesanan = p.id_pesanan;
                p_kode_qr := fn_kode_acak('QR-', 12);
                INSERT INTO e_tiket (id_pesanan, kode_qr) VALUES (p.id_pesanan, p_kode_qr);
            END $$;

            -- tandai semua pesanan yang lewat batas bayar; bisa dijadwalkan tiap menit
            CREATE OR REPLACE PROCEDURE sp_kedaluwarsakan_pesanan()
            LANGUAGE sql AS $$
                UPDATE pesanan SET status = 'kedaluwarsa', updated_at = now()
                WHERE status = 'menunggu' AND batas_bayar < now();
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP PROCEDURE IF EXISTS sp_kedaluwarsakan_pesanan();
            DROP PROCEDURE IF EXISTS sp_bayar_pesanan(varchar, varchar, varchar);
            DROP PROCEDURE IF EXISTS sp_buat_pesanan(bigint, varchar, varchar, varchar, integer, date, varchar, jsonb, varchar);
            DROP TRIGGER IF EXISTS ulasan_ringkasan ON ulasan;
            DROP TRIGGER IF EXISTS pesanan_item_total ON pesanan_item;
            DROP FUNCTION IF EXISTS trg_ulasan_ringkasan();
            DROP FUNCTION IF EXISTS fn_hitung_ringkasan_ulasan(integer);
            DROP FUNCTION IF EXISTS trg_pesanan_hitung_total();
            DROP VIEW IF EXISTS v_celah_konten;
            DROP VIEW IF EXISTS v_penjualan_tiket;
            DROP FUNCTION IF EXISTS fn_kode_acak(text, integer);
            DROP INDEX IF EXISTS ix_log_tanpa_hasil;
            DROP INDEX IF EXISTS ix_ulasan_tempat;
            DROP INDEX IF EXISTS ix_pesanan_tempat;
            DROP INDEX IF EXISTS ix_pesanan_menunggu;
            DROP INDEX IF EXISTS ix_pesanan_pengguna;
            SQL);
    }
};
