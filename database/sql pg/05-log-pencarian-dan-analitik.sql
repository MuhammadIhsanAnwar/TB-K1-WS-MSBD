-- Padanan PostgreSQL untuk log pencarian pada SupabaseApi.php / Penyimpanan.php.

INSERT INTO log_pencarian (kata_kunci, jumlah_hasil)
VALUES ($1, $2);

-- View yang dipakai admin untuk menemukan kata kunci tanpa hasil.
SELECT kata_kunci, jumlah_dicari, terakhir_dicari
FROM v_celah_konten
ORDER BY jumlah_dicari DESC;

-- Jumlah seluruh log pencarian (PostgREST count=exact).
SELECT COUNT(*) AS jumlah
FROM log_pencarian;
