-- Padanan PostgreSQL untuk operasi rencana pada SupabaseApi.php / Penyimpanan.php.

-- Daftar rencana opsional untuk pengguna atau satu kode.
SELECT *
FROM rencana
WHERE ($1::bigint IS NULL OR id_pengguna = $1)
  AND ($2::varchar IS NULL OR kode = $2)
ORDER BY created_at DESC, id_rencana DESC;

SELECT *
FROM rencana_minat
WHERE id_rencana = ANY($1::bigint[]);

SELECT id_rencana, moda
FROM rencana_moda
WHERE id_rencana = ANY($1::bigint[]);

SELECT id_rencana, urutan, tempat_slug
FROM rencana_tujuan
WHERE id_rencana = ANY($1::bigint[])
ORDER BY id_rencana ASC, urutan ASC;

-- Menyimpan rencana dan minat secara atomik melalui RPC fn_simpan_rencana().
SELECT *
FROM public.fn_simpan_rencana(
  $1::varchar, $2::bigint, $3::varchar, $4::smallint,
  $5::text[], $6::varchar, $7::text[], $8::text[]
);

-- Perbarui rencana milik pengguna dan seluruh relasi moda/minat/tujuan secara atomik.
SELECT *
FROM public.fn_perbarui_rencana(
  $1::bigint, $2::bigint, $3::varchar, $4::smallint,
  $5::text[], $6::varchar, $7::text[], $8::text[]
);

-- Hapus rencana hanya jika id_rencana dan id_pengguna cocok.
SELECT public.fn_hapus_rencana($1::bigint, $2::bigint) AS berhasil;
