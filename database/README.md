# Data MedanVisit: PostgreSQL akun/transaksi dan Database 3 RDF

Data wisata hanya dikirim ke Database 3. PostgreSQL hanya menyimpan akun,
konfigurasi Laravel, dan transaksi:

```
database/rdf sparql/medanvisit-data.ttl
  └─► php artisan rdf:kirim ─► Database 3 AllegroGraph (SPARQL)
```

Kalau data tempat berubah: ubah RDF sumber di `database/rdf sparql/medanvisit-data.ttl`,
lalu jalankan `rdf:kirim` ke Database 3.

## Isi folder

| Folder / file | Isi |
|---|---|
| `migrations/2026_11_06_000300_buat_tabel_transaksi.php` | tabel DB 2, aktif |
| `migrations/2026_11_06_000400_buat_objek_transaksi.php` | index, view, function, trigger, procedure DB 2, aktif |
| `migrations/2026_11_15_000200_buat_admin_tempat.php` | hubungan admin dengan tempat wisata di DB 1; mengisi relasi dari `users.tempat_slug` yang sudah ada |
| `migrations/2026_11_18_000200_buat_pengaturan_situs.php` | pengaturan DB 1 untuk sampul beranda dan override status operasional tempat |
| `migrations/2026_11_24_000100_batasi_tiket_hari_ini.php` | DB 2 menolak pesanan dengan tanggal kunjungan selain hari ini |
| `migrations/2026_11_25_000100_hak_akses_rencana_service_role_db2.php` | memberi `service_role` akses DB 2 untuk membaca dan mengelola rencana perjalanan melalui REST/RPC |
| `migrations/2026_11_26_000100_buat_suka_tempat.php` | DB 2 menyimpan satu suka per akun dan tempat serta menyediakan ringkasan jumlah suka |
| `migrations/2026_11_26_000200_buat_rating_tempat.php` | Riwayat migration DB 2 untuk membuat tabel rating; trigger lama dinormalisasi oleh migration berikutnya |
| `migrations/2026_11_27_000100_normalisasi_db1_db2.php` | Memusatkan relasi admin-tempat, rating, dan moda rencana; ringkasan ulasan dihitung sebagai view |
| `migrations/2026_11_27_000200_hapus_tabel_fitur_lama.php` | Menghapus tabel mitra, check-in, poin, voucher, dan penukaran yang tidak lagi dipakai web |
| `migrations/2026_11_29_000100_buat_ringkasan_popularitas_tempat.php` | DB 2 menggabungkan ulasan, rating, dan suka serta menghitung ranking untuk Tanya Mas Nonov |
| `migrations/2026_12_01_000100_beri_akses_rest_service_role_db1.php` | memberi `service_role` akses REST DB 1 pada relasi admin-tempat, pesan, token reset, dan pengaturan situs |
| `migrations/2026_12_01_000200_beri_akses_analitik_service_role_db2.php` | memberi `service_role` akses DB 2 ke log pencarian dan view celah konten |
| `migrations/2026_12_02_000100_hardening_akses_rest_db1.php` | mencabut akses REST publik DB 1, mengaktifkan RLS pada data aplikasi, dan mempertahankan akses `service_role` |
| `migrations/2026_12_02_000200_hardening_rls_db2.php` | mencabut akses REST publik DB 2 dan mengaktifkan RLS pada seluruh tabel aplikasi |
| `migrations-transaksi/2026_11_06_000500_buat_role_database.php` | role hak akses kedua database, ditunda |
| `app/Console/Commands/KirimRdf.php` | mengirim RDF wisata ke Database 3 |
| `migrations/2026_11_11_000200_kirim_rdf_ke_database3.php` | migration SPARQL untuk ontologi dan data RDF Database 3 |
| `seeders/AdminTempatSeeder.php` | membuat satu akun admin untuk tiap tempat wisata dan memastikan tautannya tersimpan di `admin_tempat` (jalankan setelah migration DB 1) |
| `sql pg/` | Dokumentasi padanan query PostgreSQL runtime, dipisah berdasarkan fitur (lihat `sql pg/README.md`); bukan dump lengkap untuk membuat database |
| `rdf sparql/medanvisit-ontologi.ttl` | ontologi (TBox): kelas dan properti, diperlukan untuk penggantian graph |
| `rdf sparql/medanvisit-data.ttl` | sumber data wisata (ABox), dikirim langsung ke Database 3 |
| `rdf sparql/query/` | Query SPARQL dan pola update yang dipakai website, dipisah berdasarkan kegunaan (lihat `query/README.md`) |

## Server lokal (pengembangan)

Untuk sementara kedua database dan Fuseki berjalan di laptop; Supabase dipakai lagi saat deploy (baris Supabase di `.env` diberi awalan `# supabase:`).

| Layanan | Alamat | Menyalakan |
|---|---|---|
| PostgreSQL 17 portable | `127.0.0.1:5432`, user `postgres`, sandi `medanvisit_lokal`, database `medanvisit_katalog` dan `medanvisit_transaksi` | `C:\MSBD-WS\postgresql\pgsql\bin\pg_ctl -D C:\MSBD-WS\postgresql\data -l C:\MSBD-WS\postgresql\postgres.log start` (ganti `start` dengan `stop` untuk mematikan) |
| Apache Jena Fuseki 5.1 | `http://localhost:3030`, dataset `medanvisit` | dari folder `Downloads\apache-jena-fuseki-5.1.0\apache-jena-fuseki-5.1.0`: `fuseki-server --config=C:\MSBD-WS\TB-K1-WebSemantik-MSBD\database\rdf\fuseki-medanvisit.ttl` |

Membuka database lewat DBeaver: koneksi PostgreSQL baru, host `localhost`, port `5432`, isi user dan sandi di atas.

## 1. PostgreSQL: dua database

| Koneksi | Variabel `.env` | Isi |
|---|---|---|
| `katalog` (DB 1) | `DB_*` | users, admin_tempat, email_verification_tokens, password_reset_tokens, pesan_pengguna, balasan_pesan, pengaturan_situs, sessions, cache, jobs, failed_jobs |
| `transaksi` (DB 2) | `DB2_*` | pesanan, pesanan_item, pembayaran, e_tiket, ulasan, rating_tempat, suka_tempat, rencana, rencana_minat, rencana_moda, rencana_tujuan, log_pencarian |
| Database 3 | `SPARQL_*` | tempat wisata, kategori, kecamatan, tiket, entitas, relasi, dan jalur dalam RDF |

`tempat_slug` di DB 2 merujuk ke resource RDF di Database 3. Kecocokannya dijaga aplikasi.
DB 1 menyimpan override buka/tutup per tempat di `pengaturan_situs`; pilihan "Ikuti jam
operasional" mengembalikan status publik dan pemesanan ke jadwal RDF.

Relasi admin-tempat hanya disimpan di `admin_tempat`; rating hanya disimpan di
`rating_tempat`, dan ulasan merujuk rating itu dengan kunci akun-tempat. `ringkasan_ulasan`,
`ringkasan_rating_tempat`, dan `ringkasan_suka_tempat` adalah view, bukan salinan data.
Pilihan moda rencana disimpan sebagai baris di `rencana_moda`; minat kategori tetap berada di
`rencana_minat`. Data pengguna/tempat yang berada di database berbeda tidak dapat memakai
foreign key lintas database.

Kolom identitas pemesan dan pengulas merupakan snapshot transaksi/ulasan, supaya riwayat tetap
utuh bila profil akun berubah atau akun berada di DB 1. Harga item pesanan adalah snapshot harga
saat checkout; `pesanan.total` merupakan agregat yang dijaga trigger agar cocok dengan seluruh
subtotal item dan dapat dipakai konsisten oleh proses pembayaran.

Tabel `usaha_mitra`, `menu_usaha`, `checkin`, `riwayat_poin`, `voucher`, dan `penukaran` sudah
dipensiunkan karena tidak memiliki route web aktif. Migration penghapusannya tidak dapat
dibatalkan tanpa memulihkan backup.

### Normalisasi skema

- **1NF:** nilai berulang dipindah ke baris relasi, termasuk moda perjalanan dan minat rencana.
- **2NF:** relasi berkunci gabungan (`admin_tempat`, `rating_tempat`, `suka_tempat`,
  `rencana_moda`, `rencana_minat`) hanya menyimpan atribut yang bergantung pada seluruh kunci.
- **3NF/BCNF:** tempat admin hanya bersumber dari `admin_tempat`, rating hanya dari
  `rating_tempat`, dan ringkasan ulasan dihitung dari relasi dasar, bukan disalin ke tabel.
- Harga item, identitas pemesan/pengulas, dan penerima pesan merupakan snapshot historis yang
  disengaja. `pesanan.total` juga dipertahankan sebagai agregat transaksi yang selalu dihitung
  ulang oleh trigger item, bukan sumber data ulasan atau profil.

Laravel memuat schema dan objek DB 2 dari `database/migrations`; migration tersebut
memakai koneksi `transaksi` dan tidak menyimpan data wisata.
Untuk mengulang tahap awal pada database pengembangan, gunakan `php artisan migrate:fresh`
setelah memastikan database yang dituju memang aman untuk dihapus.

Super Admin mengelola akun dan tautan admin-tempat melalui Database 1; admin hanya
dapat mengelola tempat yang tertaut ke akunnya. Perubahan data tempat ditulis ke
Database 3 melalui SPARQL Update. Insight kunjungan menghitung pembukaan halaman
HTML publik yang berhasil (bukan pengunjung unik, dan tidak menghitung panel admin)
sebagai agregat harian pada cache database; alamat IP tidak disimpan.

```bash
php artisan migrate      # akun/Laravel di DB 1 dan transaksi di DB 2
php artisan rdf:kirim    # kirim berkas RDF sumber ke Database 3
php artisan db3:validasi # periksa 198 tempat dan identitas wajibnya
php artisan db3:migrate  # validasi lalu sinkronkan RDF ke Database 3
```

### Migration Supabase dari server hosting

`php artisan migrate` membutuhkan koneksi PostgreSQL langsung ke port 5432
(atau host pooler Supabase). Supabase REST API yang dipakai aplikasi saat
runtime tidak dapat menjalankan migration SQL. Jika server hosting tidak dapat
me-resolve atau menjangkau `db.<project-ref>.supabase.co`, jangan mengganti
hostname dengan tebakan dan jangan menjalankan `migrate` dari server tersebut.

Gunakan salah satu cara berikut:

1. Jalankan migration dari komputer/development environment yang dapat
   menjangkau PostgreSQL Supabase.
2. Salin nilai **Session pooler** atau **Transaction pooler** dari Supabase
   Dashboard → Connect, lalu isi `DB_HOST`, `DB_PORT`, `DB_USERNAME`, dan
   `DB_PASSWORD` dengan nilai tersebut pada environment yang menjalankan
   migration. Gunakan `DB_SSLMODE=require`.
3. Jalankan SQL migration melalui Supabase SQL Editor jika akses PostgreSQL
   langsung memang diblokir oleh hosting.

Setelah `.env` diubah, bersihkan konfigurasi Laravel sebelum mengulang:

```bash
php artisan config:clear
php artisan migrate --database=katalog
```

Migration yang memiliki `protected $connection = 'transaksi'` tetap dijalankan
ke DB 2, sedangkan tabel pelacak migration tetap berada di DB 1.

Jika Artisan melaporkan `connection ... failed: Connection timed out`, koneksi
belum mencapai PostgreSQL; ini masalah akses jaringan, bukan syntax migration atau
kredensial. Periksa host dan port yang dikonfigurasi dari PowerShell:

```powershell
Test-NetConnection <DB_HOST> -Port <DB_PORT>
```

Untuk migration, gunakan endpoint pooler Supabase mode session jika koneksi
langsung tidak dapat dijangkau. Mode transaction pooler tidak cocok untuk
migration. Jika `TcpTestSucceeded` bernilai `False`, gunakan jaringan yang
mengizinkan koneksi keluar ke port tersebut atau jalankan migration dari
lingkungan yang memiliki akses; mengulang Artisan atau mengganti kredensial
tidak memperbaiki timeout jaringan.

Migration akses `service_role` DB 1 dan DB 2 perlu dijalankan sebelum menggunakan
panel admin dan fitur analitik setelah deploy. Kunci `service_role` hanya boleh
dipakai di server; jangan pernah mengirimkannya ke browser.

Migration hardening akses mencabut grant tabel, sequence, dan routine dari `PUBLIC`,
`anon`, serta `authenticated`. Database 2 mengaktifkan RLS tanpa policy untuk role
publik; runtime Laravel memakai `service_role` yang tetap diberi hak akses. Tabel
sesi, cache, dan antrean DB 1 tidak dipaksa memakai RLS karena diakses melalui
koneksi PostgreSQL Laravel. Jalankan `php artisan migrate` pada kedua koneksi setelah
deploy; verifikasi grant dan policy live melalui Supabase SQL Editor karena migration
lokal tidak dapat memastikan konfigurasi role pada project Supabase.

Operasi tulis SPARQL Database 3 di production memerlukan `SPARQL_USERNAME` dan
`SPARQL_PASSWORD`. Kredensial kosong atau parsial akan menggagalkan penulisan secara
eksplisit; query baca tidak diwajibkan memakai Basic Auth.

Jangan menaruh password database atau secret key Supabase di repository.

Akun pengguna disimpan di DB 1 dengan role `wisatawan`, `admin`, atau `superadmin`. Seeder demo yang dijalankan secara eksplisit:

```bash
php artisan db:seed --class=Wisatawan500Seeder   # 10.000 akun wisatawan
php artisan db:seed --class=AdminTempatSeeder    # satu admin per tempat dalam RDF
php artisan db:seed --class=WisatawanRatingSeeder # rating simulasi untuk semua tempat
php artisan db:seed --class=WisatawanUlasanSeeder # ulasan dan suka simulasi
php artisan db:seed --class=WisatawanTransaksiSeeder # transaksi tiket demo
```

`WisatawanUlasanSeeder` juga menjalankan seeder akun wisatawan. Teks ulasan dibuat natural
tanpa penanda di isi pesan, tetapi seluruh ulasan dan metrik yang dibuat seeder tetap data
demo, bukan ulasan pengunjung sungguhan. Sepuluh tempat yang diberikan memiliki
jumlah ulasan dan rata-rata rating sesuai target; jumlah suka dibuat bervariasi dan banyak
untuk tempat populer. `WisatawanTransaksiSeeder` membuat transaksi demo bertanda referensi
`SEED-DEMO-` dengan tiket termurah di RDF untuk tanggal berjalan. Rating disimpan satu
kali per akun-tempat di
`rating_tempat`; ulasan merujuk rating itu dengan kunci gabungan pengguna-tempat.
Jalankan migration DB 2 sebelum seeder interaksi. Jika dijalankan ulang, pasangan akun-tempat
yang sama akan diperbarui, bukan dibuat sebagai baris duplikat.

### Objek database

| Jenis | DB 1 katalog | DB 2 transaksi |
|---|---|---|
| View | `v_simpul`, `v_tempat_lengkap`, `v_relasi_kalimat`, `v_statistik_kategori` | `v_penjualan_tiket`, `v_celah_konten`, `ringkasan_ulasan`, `ringkasan_suka_tempat`, `ringkasan_rating_tempat`, `ringkasan_popularitas_tempat` |
| Function | `fn_jarak_meter`, `fn_tempat_terdekat`, `fn_buka_pada` | `fn_kode_acak`, `fn_simpan_rencana` |
| Trigger | `relasi_cek_simpul` (slug harus ada, jarak dihitung otomatis), `*_jaga_hapus` / `*_ikut_ganti` (integritas slug), `tempat_updated_at` | `pesanan_item_total` |
| Procedure | `sp_hitung_relasi_dekat` | `sp_buat_pesanan`, `sp_bayar_pesanan`, `sp_kedaluwarsakan_pesanan` |
| Role | `medanvisit_baca`, `medanvisit_kurator` | `medanvisit_aplikasi`, `medanvisit_laporan` |

Contoh di DBeaver atau psql:

```sql
-- DB 1
SELECT * FROM fn_tempat_terdekat(3.5752, 98.6840, 500);
SELECT kalimat, sumber FROM v_relasi_kalimat WHERE subjek = 'tjong-a-fie';
CALL sp_hitung_relasi_dekat(300);

-- DB 2
CALL sp_buat_pesanan(1, 'Rina', 'rina@contoh.id', '0812', 'istana-maimun', current_date, 'qris',
                     '[{"jenis":"Dewasa","harga":10000,"jumlah":2}]', NULL);
CALL sp_bayar_pesanan('MV-XXXXXXXX', 'REF-1', NULL);
```

`sp_buat_pesanan` hanya menerima tanggal kunjungan hari ini (zona waktu Asia/Jakarta).
Aplikasi juga memeriksa jam operasional RDF saat memilih tiket dan mengulang pemeriksaan
sebelum membuat pesanan. Tiket lunas yang belum dipakai menjadi kedaluwarsa setelah tanggal
kunjungannya lewat; pemindaian admin hanya dapat menandai e-tiket yang belum pernah dipakai.

## 2. Web Semantik: standar W3C

RDF (model data), RDFS dan OWL 2 (ontologi), Turtle / JSON-LD / RDF-XML (format file), SPARQL 1.1 (query). Kosakata yang dipakai ulang: schema.org, WGS84 `geo:`, Dublin Core `dcterms:`, dan `owl:sameAs` ke Wikidata. Sumber setiap relasi disimpan lewat reifikasi `rdf:Statement` + `dcterms:source`.

Menjalankan Fuseki (Java 17+), dari folder Fuseki:

```bash
fuseki-server --config=<path repo>/database/rdf/fuseki-medanvisit.ttl
```

Buka `http://localhost:3030`, dataset `medanvisit`. Endpoint SPARQL: `http://localhost:3030/medanvisit/sparql` (isi `SPARQL_ENDPOINT` di `.env`). Halaman `/data` di web memakai endpoint ini; jika Database 3 mati, web menampilkan error koneksi dan tidak memakai data lokal.
