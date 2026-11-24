-- Padanan PostgreSQL untuk operasi ulasan SupabaseApi.php.
-- Rating tersimpan di rating_tempat; ulasan tayang disinkronkan dengan trigger DB 2.

SELECT *
FROM ulasan
WHERE ($1::varchar IS NULL OR tempat_slug = $1)
  AND ($2::varchar IS NULL OR status = $2)
  AND ($3::bigint IS NULL OR id_pengguna = $3)
ORDER BY created_at DESC, id_ulasan DESC;

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
   bintang, teks, status, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
RETURNING *;

UPDATE ulasan
SET nama_pengguna = $2, bintang = $3, teks = $4, status = $5, updated_at = $6
WHERE id_ulasan = $1
RETURNING *;

DELETE FROM ulasan
WHERE id_ulasan = $1
RETURNING id_ulasan;
