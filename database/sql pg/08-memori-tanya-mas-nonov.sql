-- Query PostgreSQL DB 1 untuk app/Services/TanyaMasNonovMemori.php.
-- Query Builder Laravel menjalankan operasi ini langsung pada koneksi "katalog".
-- Isi pesan/jawaban yang dikirim ke DB sudah dienkripsi aplikasi; pemilik tamu disimpan sebagai hash.

-- Simpan pertanyaan/pesan pengguna atau tamu, lalu ambil id dari sequence.
INSERT INTO pesan_tanya_mas_nonov
  (id_pengguna, pengirim, pemilik_tamu, pesan_terenkripsi, pesan_hash,
   created_at, updated_at)
VALUES ($1, $2, $3, $4, $5, $6, $7)
RETURNING id;

-- Ambil paling banyak empat giliran terdahulu, selain pesan yang sedang dijawab.
-- Untuk pengguna, filter memakai id_pengguna; untuk tamu, pengirim dan hash pemilik tamu.
SELECT pesan_terenkripsi, jawaban_terenkripsi
FROM pesan_tanya_mas_nonov
WHERE id <> $1
  AND id_pengguna = $2
ORDER BY id DESC
LIMIT 4;

SELECT pesan_terenkripsi, jawaban_terenkripsi
FROM pesan_tanya_mas_nonov
WHERE id <> $1
  AND pengirim = 'tamu'
  AND pemilik_tamu = $2
ORDER BY id DESC
LIMIT 4;

-- Aplikasi membalik urutan hasil SELECT menjadi kronologis lalu mendekripsinya.
-- Saat selesai menjawab, simpan jawaban terenkripsi pada pesan yang sama.
UPDATE pesan_tanya_mas_nonov
SET jawaban_terenkripsi = $2,
    updated_at = $3
WHERE id = $1;
