# Query SPARQL MedanVisit (Database 3)

Folder ini hanya menyimpan query dan pola update yang memiliki pemanggil pada website.
Runtime baca menggunakan endpoint SPARQL di `app/Support/SparqlService.php`; pembacaan
katalog dilakukan oleh `app/Support/Database3Catalog.php`; perubahan katalog admin
dibangun oleh `app/Support/Database3CatalogWriter.php`.

## Indeks berdasarkan kegunaan

| File | Kegunaan di website | Pemanggil |
|---|---|---|
| `00-seluruh-query-website.sparql` | Kumpulan gabungan query baca dan pola update yang diterapkan website, dengan judul dan fungsi tiap query | Referensi lengkap; jalankan satu bagian pada satu waktu |
| `12-katalog-semua-triple.rq` | Mengambil triple entitas, properti, alamat, penawaran, dan sumber untuk membangun katalog/cache | `Database3Catalog::triples()` |
| `13-cari-tempat-template.rq` | Mencari dan memaginasi tempat berdasarkan kelas; dapat ditambah filter nama, kecamatan, dan fasilitas | `SparqlService::cariTempat()` |
| `14-hitung-tempat-template.rq` | Menghitung tempat per kelas wisata untuk jawaban Tanya Mas Nonov | `SparqlService::hitungTempat()` |
| `22-cek-endpoint-admin.ask.rq` | Memeriksa konektivitas endpoint AllegroGraph | pemeriksaan kesehatan admin |
| `23-admin-create-wisata-template.ru` | Pola SPARQL Update untuk membuat resource tempat dan penawarannya | `Database3CatalogWriter::create()` |
| `24-admin-update-wisata-template.ru` | Pola SPARQL Update untuk memperbarui atribut terkelola, alamat, jam, dan penawaran | `Database3CatalogWriter::update()` |
| `25-admin-delete-wisata-template.ru` | Menghapus resource, relasi masuk/keluar, serta reifikasi sumbernya | `Database3CatalogWriter::delete()` |

Query baca yang diketik admin pada halaman `/data` dibuat saat request; tidak ada satu query
tetap untuk didokumentasikan. `SparqlService::jalankan()` hanya menerima satu query baca
`SELECT`, `ASK`, `CONSTRUCT`, atau `DESCRIBE`.

## Aturan membaca

- `{{...}}` adalah placeholder dokumentasi, bukan sintaks SPARQL siap jalan. Nilai IRI dan
  literal pada request sebenarnya divalidasi dan diserialisasi oleh service.
- Kelas wisata berasal dari allowlist aplikasi. Pencarian membatasi `LIMIT` ke 1–50 dan
  `OFFSET` ke 0–500; teks pengguna dikirim sebagai literal.
- Berkas `.ru` mendokumentasikan pola operasi yang disusun writer. Mutasi hanya dikirim
  setelah otorisasi admin dan bukan untuk dijalankan dengan substitusi nilai mentah.
- Query eksplorasi dan contoh yang tidak memiliki pemanggil website telah dihapus dari folder.
