-- Padanan PostgreSQL untuk alur pesanan pada SupabaseApi.php / Penyimpanan.php.
-- Website mengakses tabel melalui PostgREST; RPC dikirim sebagai POST /rest/v1/rpc/<fungsi>.

-- Daftar pesanan; $1 opsional id_pengguna, $2 opsional kode.
SELECT p.*,
       pay.dibayar_pada,
       pay.referensi,
       tiket.kode_qr,
       tiket.dipakai_pada
FROM pesanan AS p
LEFT JOIN pembayaran AS pay ON pay.id_pesanan = p.id_pesanan
LEFT JOIN e_tiket AS tiket ON tiket.id_pesanan = p.id_pesanan
WHERE ($1::bigint IS NULL OR p.id_pengguna = $1)
  AND ($2::varchar IS NULL OR p.kode = $2)
ORDER BY p.created_at DESC, p.id_pesanan DESC;

-- Detail item untuk sekumpulan pesanan.
SELECT *
FROM pesanan_item
WHERE id_pesanan = ANY($1::bigint[])
ORDER BY id_item ASC;

-- Pencarian pesanan melalui kode QR e-tiket (endpoint pemindaian/check-in).
SELECT p.*,
       pay.dibayar_pada,
       pay.referensi,
       tiket.kode_qr,
       tiket.dipakai_pada
FROM pesanan AS p
JOIN e_tiket AS tiket ON tiket.id_pesanan = p.id_pesanan
LEFT JOIN pembayaran AS pay ON pay.id_pesanan = p.id_pesanan
WHERE tiket.kode_qr = $1
LIMIT 1;

-- Buat pesanan secara atomik melalui function RPC; aturan bisnis berada di function/procedure DB.
SELECT public.sp_buat_pesanan(
  $1::bigint, $2::varchar, $3::varchar, $4::varchar,
  $5::varchar, $6::date, $7::varchar, $8::jsonb
) AS kode;

-- Transaksi simulasi idempoten untuk data/demo (RPC sp_buat_transaksi_demo).
-- Validasi dan operasi atomik berada di function; argumen dikirim sebagai JSON ke PostgREST.
SELECT public.sp_buat_transaksi_demo(
  $1::varchar, $2::bigint, $3::varchar, $4::varchar, $5::varchar,
  $6::varchar, $7::date, $8::varchar, $9::jsonb
) AS kode;

-- Bayar pesanan, catat pembayaran, dan terbitkan e-tiket lewat function RPC.
SELECT public.sp_bayar_pesanan($1::varchar, $2::varchar) AS kode_qr;

-- Baris transaksi demo yang digunakan seeder agar proses seed aman dijalankan ulang.
SELECT p.id_pesanan, p.tempat_slug, p.id_pengguna, pay.referensi
FROM pesanan AS p
JOIN pembayaran AS pay ON pay.id_pesanan = p.id_pesanan
WHERE pay.referensi LIKE 'SEED-DEMO-%'
LIMIT 10000;

-- Perubahan status hanya pada pesanan yang masih menunggu.
UPDATE pesanan
SET status = $2, updated_at = $3
WHERE kode = $1 AND status = 'menunggu'
RETURNING id_pesanan, kode, status;

-- Pilihan metode pembayaran hanya pada pesanan yang masih menunggu.
UPDATE pesanan
SET metode = $2, updated_at = $3
WHERE kode = $1 AND status = 'menunggu'
RETURNING id_pesanan, kode, metode;

-- Check-in QR tiket; hanya tandai bila belum digunakan.
UPDATE e_tiket
SET dipakai_pada = $2
WHERE id_pesanan = $1 AND dipakai_pada IS NULL
RETURNING id_pesanan, dipakai_pada;
