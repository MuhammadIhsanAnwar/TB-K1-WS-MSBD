# Query SPARQL MedanVisit (Database 3)

Database 3 adalah AllegroGraph. Query baca dikirim melalui endpoint SPARQL W3C oleh
`app/Support/SparqlService.php`; entitas, relasi, dan cache katalog dipetakan di
`app/Support/Database3Catalog.php`. Mutasi data katalog admin dibangun di
`app/Support/Database3CatalogWriter.php`.

## Indeks berdasarkan kegunaan

| File | Kegunaan | Status |
|---|---|---|
| `01-jumlah-tempat-per-kategori.rq` | Contoh analitik jumlah tempat per kelas | Contoh eksplorasi |
| `02-dibangun-pemimpin-deli.rq` | Contoh traversal relasi tokoh dan kerajaan | Contoh eksplorasi |
| `03-bertiket-termurah.rq` | Contoh pembacaan penawaran tiket dan pengurutan harga | Contoh eksplorasi |
| `04-dekat-istana-maimun.rq` | Contoh pencarian relasi kedekatan dan jarak | Contoh eksplorasi |
| `06-relasi-dan-sumbernya.rq` | Contoh reifikasi relasi serta sumber | Contoh eksplorasi |
| `07-buka-pukul-20.rq` | Contoh filter rentang waktu bertipe `xsd:time` | Contoh eksplorasi |
| `08-rumah-ibadah-dan-budayanya.rq` | Contoh relasi opsional budaya | Contoh eksplorasi |
| `09-tautan-wikidata.rq` | Contoh linked data `owl:sameAs` | Contoh eksplorasi |
| `10-ask-meriam-bagian-istana.rq` | Contoh query boolean `ASK` | Contoh eksplorasi |
| `11-construct-schema-org.rq` | Contoh transformasi graf dengan `CONSTRUCT` | Contoh eksplorasi |
| `12-katalog-semua-triple.rq` | Mengambil triple relevan untuk membangun katalog/cache | Runtime: `Database3Catalog::triples()` |
| `13-cari-tempat-template.rq` | Cari dan paginasi tempat berdasarkan kelas, nama, kecamatan, fasilitas | Runtime: `SparqlService::cariTempat()` |
| `14-hitung-tempat-template.rq` | Hitung tempat per kelas wisata | Runtime/helper: `SparqlService::hitungTempat()` |
| `15-rekomendasi-satu-kecamatan-template.rq` | Rekomendasi tempat dalam kecamatan yang sama | Runtime/helper: `SparqlService::rekomendasikanSatuKecamatan()` |
| `16-audit-tempat-tanpa-deskripsi.rq` | Audit tempat yang belum memiliki deskripsi | Runtime/helper: `SparqlService::auditTempatTanpaDeskripsi()` |
| `17-statistik-harga-tiket.rq` | Agregat statistik harga penawaran tiket | Runtime/helper: `SparqlService::statistikHargaTiket()` |
| `18-ada-tempat-template.ask.rq` | Validasi keberadaan slug pada kelas wisata | Runtime/helper: `SparqlService::adaTempat()` |
| `19-describe-tempat-template.rq` | Mengambil deskripsi graf resource | Runtime/helper: `SparqlService::deskripsikanTempat()` |
| `20-relasi-tempat-template.rq` | Membaca relasi tempat ke entitas bernama | Runtime/helper: `SparqlService::relasiTempat()` |
| `21-grafik-tempat-template.construct.rq` | Membaca triple masuk dan keluar satu resource | Runtime/helper: `SparqlService::grafikTempat()` |
| `22-cek-endpoint-admin.ask.rq` | Pemeriksaan konektivitas endpoint AllegroGraph | Runtime: pemeriksaan kesehatan admin |
| `23-admin-create-wisata-template.ru` | Membuat resource tempat, termasuk penawaran dan atribut opsional | Runtime: pola `Database3CatalogWriter::create()` |
| `24-admin-update-wisata-template.ru` | Mengganti atribut tempat, alamat, jam terstruktur, serta penawaran | Runtime: pola `Database3CatalogWriter::update()` |
| `25-admin-delete-wisata-template.ru` | Menghapus resource, relasi masuk/keluar, dan reifikasi sumber | Runtime: pola `Database3CatalogWriter::delete()` |

Nomor `05` memang tidak digunakan (penomoran lama dipertahankan agar tautan/berkas yang sudah
ada tidak berubah). File `01`–`11` merupakan query eksplorasi, bukan klaim bahwa fitur web
menjalankan query tersebut.

## Aturan membaca dan menjalankan

- File berakhiran `template` atau `.ru` menjelaskan pola query yang dibentuk aplikasi.
  `{{...}}` adalah placeholder dokumentasi, bukan sintaks SPARQL. Nilai runtime divalidasi
  dan diserialisasi di service; jangan menggantinya dengan input mentah.
- `cariTempat()` hanya menerima kelas dari allowlist kategori; teks nama/kecamatan/fasilitas
  menjadi literal yang di-escape. Batas aktualnya `LIMIT 1..50`, `OFFSET 0..500`.
- Slug pada helper detail divalidasi sebagai `[a-z0-9-]{1,80}` sebelum menjadi IRI resource.
- `SparqlService::jalankan()` menerima satu query baca `SELECT`, `ASK`, `CONSTRUCT`, atau
  `DESCRIBE`; `INSERT`, `DELETE`, dan bentuk update lain ditolak. Halaman admin `/data`
  mengirim query baca yang dimasukkan admin, sehingga tidak ada satu query tetap untuk
  didokumentasikan sebagai query halaman itu.
- Mutasi hanya dikirim lewat `SparqlService::perbarui()` setelah pemeriksaan otorisasi admin.
  Berkas `.ru` mendokumentasikan keluarga operasi dan urutannya, bukan perintah yang dapat
  ditempel langsung dengan nilai placeholder.
- Cache katalog RDF disimpan selama 10 menit. JSON-LD untuk halaman dibentuk dari entitas
  hasil pemetaan RDF (dan ringkasan rating DB 2), bukan query SPARQL `CONSTRUCT` terpisah.
- Pencarian memakai katalog RDF dari SPARQL, termasuk `mv:alias`, lalu merangking typo dan
  singkatan di aplikasi. Alias nama dapat ditambahkan sebagai literal pada resource, misalnya
  `mv:alias "Lapmer"@id` untuk `res:lapangan-merdeka`.
- Query eksplorasi hanya untuk skema/data yang tersedia di AllegroGraph; beberapa contoh
  mengasumsikan kelas atau relasi tertentu telah ada pada dataset aktif.
