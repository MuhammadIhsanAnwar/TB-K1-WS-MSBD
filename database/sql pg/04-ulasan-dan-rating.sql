-- Padanan PostgreSQL untuk operasi ulasan SupabaseApi.php.
-- Rating tersimpan sekali di rating_tempat; ulasan merujuknya dengan kunci pengguna-tempat.

SELECT u.*, r.bintang
FROM ulasan AS u
JOIN rating_tempat AS r
  ON r.id_pengguna = u.id_pengguna AND r.tempat_slug = u.tempat_slug
WHERE ($1::varchar IS NULL OR u.tempat_slug = $1)
  AND ($2::varchar IS NULL OR u.status = $2)
  AND ($3::bigint IS NULL OR u.id_pengguna = $3)
ORDER BY u.created_at DESC, u.id_ulasan DESC;

SELECT tempat_slug, rata_rata_bintang, jumlah_ulasan
FROM ringkasan_ulasan;

SELECT tempat_slug, rata_rata_bintang, jumlah_rating
FROM ringkasan_rating_tempat;

INSERT INTO rating_tempat
  (id_pengguna, tempat_slug, bintang, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5)
ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
SET bintang = EXCLUDED.bintang, updated_at = EXCLUDED.updated_at;

INSERT INTO ulasan
  (id_pengguna, tempat_slug, nama_pengguna, email_pengguna,
   teks, status, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
RETURNING *;

UPDATE ulasan
SET nama_pengguna = $2, teks = $3, status = $4, updated_at = $5
WHERE id_ulasan = $1
RETURNING *;

DELETE FROM ulasan
WHERE id_ulasan = $1
RETURNING id_ulasan;
