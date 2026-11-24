-- Padanan SQL untuk kueri yang dipakai layanan Tanya Mas Nonov.
-- Runtime Laravel mengirim filter/order/limit yang sama melalui SupabaseApi dan PostgREST,
-- bukan membuka koneksi PostgreSQL langsung dari web server.
-- DDL view di bawah merangkum migration DB 2 sebagai referensi; jangan menjalankannya ulang
-- pada database yang sudah dimigrasikan. Migration adalah sumber definisi otoritatif.

-- View pendukung; definisi otoritatifnya ada pada migration DB 2.
CREATE VIEW ringkasan_ulasan AS
SELECT u.tempat_slug,
       COUNT(*) AS jumlah_ulasan,
       ROUND(AVG(r.bintang), 2) AS rata_rata_bintang
FROM ulasan AS u
JOIN rating_tempat AS r
  ON r.id_pengguna = u.id_pengguna
 AND r.tempat_slug = u.tempat_slug
WHERE u.status = 'tayang'
GROUP BY u.tempat_slug;

CREATE VIEW ringkasan_rating_tempat AS
SELECT tempat_slug,
       COUNT(*) AS jumlah_rating,
       ROUND(AVG(bintang), 2) AS rata_rata_bintang
FROM rating_tempat
GROUP BY tempat_slug;

CREATE VIEW ringkasan_suka_tempat AS
SELECT tempat_slug, COUNT(*) AS jumlah_suka
FROM suka_tempat
GROUP BY tempat_slug;

-- View gabungan yang menyediakan metrik dan ranking untuk halaman/AI.
CREATE VIEW public.ringkasan_popularitas_tempat AS
WITH metrik AS (
  SELECT COALESCE(u.tempat_slug, r.tempat_slug, s.tempat_slug) AS tempat_slug,
         COALESCE(u.jumlah_ulasan, 0)::bigint AS jumlah_ulasan,
         u.rata_rata_bintang AS rata_rata_ulasan,
         COALESCE(r.jumlah_rating, 0)::bigint AS jumlah_rating,
         r.rata_rata_bintang,
         COALESCE(s.jumlah_suka, 0)::bigint AS jumlah_suka
  FROM ringkasan_ulasan AS u
  FULL OUTER JOIN ringkasan_rating_tempat AS r USING (tempat_slug)
  FULL OUTER JOIN ringkasan_suka_tempat AS s USING (tempat_slug)
),
peringkat AS (
  SELECT metrik.*,
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

-- Ringkasan jumlah suka untuk seluruh tempat (DB 2; halaman beranda/peta).
SELECT tempat_slug, jumlah_suka
FROM ringkasan_suka_tempat;

-- Detail jumlah ulasan, rating, dan suka untuk satu tempat (DB 2).
SELECT tempat_slug, jumlah_ulasan, rata_rata_ulasan,
       jumlah_rating, rata_rata_bintang, jumlah_suka
FROM ringkasan_popularitas_tempat
WHERE tempat_slug = $1
LIMIT 1;

-- Ranking ulasan menggunakan DENSE_RANK yang disediakan oleh view DB 2.
SELECT tempat_slug, jumlah_ulasan, rata_rata_ulasan,
       jumlah_rating, rata_rata_bintang, peringkat_ulasan
FROM ringkasan_popularitas_tempat
WHERE jumlah_ulasan > 0
ORDER BY peringkat_ulasan ASC, tempat_slug ASC
LIMIT $1;

-- Ranking suka menggunakan DENSE_RANK yang disediakan oleh view DB 2.
SELECT tempat_slug, jumlah_suka, peringkat_suka
FROM ringkasan_popularitas_tempat
WHERE jumlah_suka > 0
ORDER BY peringkat_suka ASC, tempat_slug ASC
LIMIT $1;

-- Jumlah pesanan untuk pengguna yang sedang masuk (DB 2).
SELECT count(*) AS jumlah
FROM pesanan
WHERE id_pengguna = $1;

-- Profil yang boleh ditampilkan ke pengguna (DB 1).
SELECT id, name, email, peran
FROM users
WHERE id = $1
LIMIT 1;

-- Riwayat pesanan pengguna yang sedang masuk (DB 2).
SELECT kode, tempat_slug, tanggal_kunjungan, total, status, metode, created_at
FROM pesanan
WHERE id_pengguna = $1
ORDER BY created_at DESC, id_pesanan DESC
LIMIT $2;

-- Laravel bind nilai; jangan interpolasikan input menjadi SQL:
-- DB::connection('transaksi')->select(
--     'SELECT kode, total FROM pesanan WHERE id_pengguna = ?', [$idPengguna]
-- );
