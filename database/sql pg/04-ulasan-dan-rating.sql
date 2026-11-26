-- Padanan PostgreSQL untuk operasi ulasan SupabaseApi.php.
-- Rating tersimpan sekali di rating_tempat; ulasan merujuknya dengan kunci pengguna-tempat.

-- Daftar ulasan dengan filter opsional; rating diambil dalam query terpisah di bawah.
SELECT *
FROM ulasan
WHERE ($1::varchar IS NULL OR tempat_slug = $1)
  AND ($2::varchar IS NULL OR status = $2)
  AND ($3::bigint IS NULL OR id_pengguna = $3)
ORDER BY created_at DESC, id_ulasan DESC;

-- Halaman moderasi/ulasan publik; aplikasi meminta jumlah total dan halaman terpisah.
SELECT COUNT(*) AS jumlah
FROM ulasan
WHERE status = $1
  AND ($2::varchar[] IS NULL OR tempat_slug = ANY($2))
  AND (
    $3::text IS NULL
    OR nama_pengguna ILIKE '%' || $3 || '%'
    OR email_pengguna ILIKE '%' || $3 || '%'
    OR teks ILIKE '%' || $3 || '%'
    OR tempat_slug ILIKE '%' || $3 || '%'
    OR tempat_slug ILIKE '%' || replace($3, ' ', '-') || '%'
  );

SELECT *
FROM ulasan
WHERE status = $1
  AND ($2::varchar[] IS NULL OR tempat_slug = ANY($2))
  AND (
    $3::text IS NULL
    OR nama_pengguna ILIKE '%' || $3 || '%'
    OR email_pengguna ILIKE '%' || $3 || '%'
    OR teks ILIKE '%' || $3 || '%'
    OR tempat_slug ILIKE '%' || $3 || '%'
    OR tempat_slug ILIKE '%' || replace($3, ' ', '-') || '%'
  )
ORDER BY created_at DESC, id_ulasan DESC
LIMIT $4 OFFSET $5;

-- Rating untuk pasangan pengguna-tempat pada satu halaman ulasan.
SELECT id_pengguna, tempat_slug, bintang
FROM rating_tempat
WHERE id_pengguna = ANY($1::bigint[])
  AND tempat_slug = ANY($2::varchar[]);

SELECT tempat_slug, rata_rata_bintang, jumlah_ulasan
FROM ringkasan_ulasan;

SELECT tempat_slug, rata_rata_bintang, jumlah_rating
FROM ringkasan_rating_tempat;

-- Semua tempat yang disukai pengguna; atau cek satu pasangan pengguna-tempat.
SELECT tempat_slug
FROM suka_tempat
WHERE id_pengguna = $1;

SELECT EXISTS (
  SELECT 1
  FROM suka_tempat
  WHERE id_pengguna = $1 AND tempat_slug = $2
) AS sudah_suka;

-- Tambahkan suka secara idempoten (endpoint dan seeder memakai ON CONFLICT merge).
INSERT INTO suka_tempat (id_pengguna, tempat_slug, created_at)
VALUES ($1, $2, $3)
ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
SET created_at = EXCLUDED.created_at;

-- Hapus suka dari satu tempat.
DELETE FROM suka_tempat
WHERE id_pengguna = $1 AND tempat_slug = $2
RETURNING id_pengguna, tempat_slug;

INSERT INTO rating_tempat
  (id_pengguna, tempat_slug, bintang, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5)
ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
SET bintang = EXCLUDED.bintang,
    created_at = EXCLUDED.created_at,
    updated_at = EXCLUDED.updated_at;

INSERT INTO ulasan
  (id_pengguna, tempat_slug, nama_pengguna, email_pengguna,
   teks, status, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
RETURNING *;

UPDATE ulasan
SET nama_pengguna = $2, teks = $3, status = $4, updated_at = $5
WHERE id_ulasan = $1
RETURNING *;

-- Seeder meng-upsert ulasan berdasarkan pasangan pengguna-tempat.
-- Rating terkait di-upsert terpisah ke rating_tempat sebelum operasi ini.
INSERT INTO ulasan
  (id_pengguna, tempat_slug, nama_pengguna, email_pengguna,
   teks, status, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
SET nama_pengguna = EXCLUDED.nama_pengguna,
    email_pengguna = EXCLUDED.email_pengguna,
    teks = EXCLUDED.teks,
    status = EXCLUDED.status,
    created_at = EXCLUDED.created_at,
    updated_at = EXCLUDED.updated_at;

-- Upsert suka batch seeder: conflict target adalah primary key gabungan.
INSERT INTO suka_tempat (id_pengguna, tempat_slug, created_at)
VALUES ($1, $2, $3)
ON CONFLICT (id_pengguna, tempat_slug) DO UPDATE
SET created_at = EXCLUDED.created_at;

DELETE FROM ulasan
WHERE id_ulasan = $1
RETURNING id_ulasan;
