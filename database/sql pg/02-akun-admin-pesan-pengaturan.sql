-- Padanan SQL PostgreSQL untuk operasi Supabase PostgREST di SupabaseApi.php.
-- Runtime website memanggil HTTPS /rest/v1, bukan mengirim statement SQL berikut secara langsung.
-- $1, $2, ... adalah parameter prepared statement; kolom yang dipilih harus tetap dibatasi sesuai akses.

-- ---------- akun (koneksi "katalog") ----------
SELECT *
FROM users
WHERE email = lower($1)
LIMIT 1;

SELECT id, name, email, peran, tempat_slug, tanggal_lahir, no_hp,
       email_verified_at, created_at, ditangguhkan_pada, alasan_tangguh
FROM users
ORDER BY id DESC
LIMIT $1 OFFSET $2;

-- Contoh signup AkunController; SupabaseApi::createUser() meneruskan atribut yang diterima.
INSERT INTO users
  (name, email, no_hp, tanggal_lahir, password, peran, email_verified_at)
VALUES ($1, lower($2), $3, $4, $5, $6, $7)
RETURNING *;

-- SupabaseApi::updateUser() membentuk daftar SET dari atribut yang diberikan.
UPDATE users
SET {{KOLOM_DAN_NILAI_BIND}}
WHERE id = $1
RETURNING *;

DELETE FROM users
WHERE id = $1
RETURNING id;

-- ---------- relasi admin-tempat ----------
SELECT id_admin, tempat_slug
FROM admin_tempat
ORDER BY id_admin ASC, tempat_slug ASC;

SELECT tempat_slug
FROM admin_tempat
WHERE id_admin = $1
ORDER BY tempat_slug ASC;

INSERT INTO admin_tempat (id_admin, tempat_slug, created_at, updated_at)
VALUES ($1, $2, $3, $4)
RETURNING *;

DELETE FROM admin_tempat
WHERE id_admin = $1 AND tempat_slug = $2
RETURNING id_admin;

SELECT id_admin
FROM admin_tempat
WHERE tempat_slug = $1
LIMIT 1;

-- ---------- pesan pengguna dan balasan ----------
-- Inbox admin: kategori tempat dan hanya pesan yang dialamatkan kepadanya.
SELECT *
FROM pesan_pengguna
WHERE kategori = 'tempat' AND id_admin_tujuan = $1
ORDER BY created_at DESC, id DESC
LIMIT 500;

-- Inbox superadmin.
SELECT *
FROM pesan_pengguna
WHERE kategori IN ('akun', 'lainnya')
ORDER BY created_at DESC, id DESC
LIMIT 500;

SELECT *
FROM pesan_pengguna
WHERE id = $1
LIMIT 1;

INSERT INTO pesan_pengguna
  (id_pengguna, nama_pengguna, email_pengguna, kategori, tempat_slug,
   nama_tempat, id_admin_tujuan, pesan, status, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
RETURNING *;

SELECT *
FROM balasan_pesan
WHERE pesan_id = ANY($1::bigint[])
ORDER BY created_at ASC, id ASC;

INSERT INTO balasan_pesan
  (pesan_id, id_pengirim, peran_pengirim, isi, status_email,
   email_terkirim_pada, created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
RETURNING *;

UPDATE balasan_pesan
SET status_email = $2, email_terkirim_pada = $3, updated_at = $4
WHERE id = $1
RETURNING *;

UPDATE pesan_pengguna
SET status = 'dibalas', updated_at = $2
WHERE id = $1
RETURNING *;

-- ---------- token autentikasi ----------
INSERT INTO email_verification_tokens (email, token_hash, expires_at)
VALUES (lower($1), $2, $3)
RETURNING *;

SELECT *
FROM email_verification_tokens
WHERE token_hash = $1
LIMIT 1;

DELETE FROM email_verification_tokens
WHERE id = $1;

DELETE FROM password_reset_tokens
WHERE email = lower($1);

INSERT INTO password_reset_tokens (email, token, created_at)
VALUES (lower($1), $2, $3)
RETURNING *;

SELECT *
FROM password_reset_tokens
WHERE token = $1
LIMIT 1;

DELETE FROM password_reset_tokens
WHERE email = lower($1);

-- ---------- pengaturan situs ----------
SELECT nilai
FROM pengaturan_situs
WHERE kunci = $1
LIMIT 1;

INSERT INTO pengaturan_situs (kunci, nilai, diubah_oleh, updated_at)
VALUES ($1, $2::jsonb, $3, $4)
ON CONFLICT (kunci) DO UPDATE
SET nilai = EXCLUDED.nilai,
    diubah_oleh = EXCLUDED.diubah_oleh,
    updated_at = EXCLUDED.updated_at;

-- Ping SupabaseApi::ping($table); nama tabel/relasi harus berasal dari allowlist aplikasi.
SELECT *
FROM {{TABEL_DIIZINKAN}}
LIMIT 1;
