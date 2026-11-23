-- Query native yang benar-benar dijalankan oleh app/Services/PostgreSQLService.php.
-- Nilai pengguna di-bind oleh Laravel; jangan gabungkan input ke SQL.

-- PostgreSQLService::ringkasanUlasanTempat($slug)
SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
FROM ringkasan_ulasan
WHERE tempat_slug = ?
LIMIT 1;

-- PostgreSQLService::peringkatUlasan($batas)
SELECT tempat_slug, jumlah_ulasan, rata_rata_bintang
FROM ringkasan_ulasan
WHERE jumlah_ulasan > 0
ORDER BY jumlah_ulasan DESC, rata_rata_bintang DESC NULLS LAST
LIMIT ?;

-- PostgreSQLService::jumlahPesanan($idPengguna)
SELECT COUNT(*) AS jumlah
FROM pesanan
WHERE id_pengguna = ?;

-- PostgreSQLService::profilPengguna($idPengguna), koneksi "katalog"
SELECT id, name, email, peran
FROM users
WHERE id = ?
LIMIT 1;

-- PostgreSQLService::riwayatPesanan($idPengguna, $batas)
SELECT kode, tempat_slug, tanggal_kunjungan, total, status, metode, created_at
FROM pesanan
WHERE id_pengguna = ?
ORDER BY created_at DESC, id_pesanan DESC
LIMIT ?;
