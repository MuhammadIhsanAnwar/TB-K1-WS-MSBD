# MedanVisit

Aplikasi wisata Kota Medan yang menggabungkan Web Semantik (RDF, OWL, SPARQL), pemesanan tiket, ulasan, rating, dan rekomendasi berbantuan AI.

## Informasi proyek

| Mata kuliah | Dosen pengampu | Kelas |
|---|---|---|
| Manajemen Sistem Basis Data | Muhammad Isa Dadi Hasibuan, S.Kom., M.Kom. | B |
| Web Semantik | Annisa Fadhillah Pulungan, S.Kom., M.Kom. | B |

**Disusun oleh Kelompok 1**

| Nama | NIM |
|---|---|
| Novri Ilyasah | 251402005 |
| Muhammad Dzakwan Ismail Rangkuti | 251302014 |
| Muhammad Ihsan Anwar | 251402044 |
| Viter Moldy Kesuma | 251402079 |

## Arsitektur umum

![Diagram arsitektur umum MedanVisit](<Arsitektur Umum.jpg>)

| Komponen | Teknologi dan fungsi |
|---|---|
| Aplikasi web | Laravel 13, PHP, Blade, CSS, dan JavaScript |
| DB 1 - katalog/akun | PostgreSQL di Supabase; akun, konfigurasi, dan relasi admin-tempat |
| DB 2 - transaksi | PostgreSQL di Supabase; pesanan, pembayaran, e-tiket, ulasan, rating, suka, dan rencana |
| DB 3 - data semantik | AllegroGraph; ontologi dan data wisata RDF yang diakses melalui SPARQL |
| Pembayaran | Midtrans Snap |
| AI | OpenRouter untuk asisten Tanya Mas Nonov |
| Peta dan rute | Leaflet/OpenStreetMap serta layanan routing OSRM dan Valhalla |

Saat aplikasi berjalan, DB 1 dan DB 2 diakses melalui Supabase REST API (HTTPS). Koneksi PostgreSQL langsung dipakai untuk menjalankan migration dari lingkungan pengembang yang memiliki akses ke database. DB 3 diakses melalui endpoint SPARQL.

## Persyaratan

- PHP 8.3 atau lebih baru dan ekstensi PHP yang dibutuhkan Laravel, termasuk `pdo_pgsql` dan `curl`.
- Composer.
- Node.js `^20.19.0` atau `>=22.12.0` dan npm (syarat Vite 8).
- Akses ke project Supabase DB 1 dan DB 2.
- Repository AllegroGraph untuk DB 3. Midtrans, OpenRouter, Google OAuth, dan SMTP dibutuhkan hanya untuk mengaktifkan fitur terkait.

## Menyiapkan dan menjalankan secara lokal

Jalankan perintah dari folder utama repository. Jika repository belum tersedia di komputer:

```powershell
git clone <URL-repository>
Set-Location TB-K1-WebSemantik-MSBD
```

Pasang dependensi, buat file konfigurasi lokal, dan buat kunci aplikasi:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
npm install
```

Lengkapi `.env` sesuai bagian [Konfigurasi layanan](#konfigurasi-layanan), lalu jalankan migration dan sinkronkan data RDF:

```powershell
php artisan config:clear
php artisan migrate --database=katalog
php artisan db3:migrate
```

`php artisan migrate --database=katalog` menjalankan migration DB 1 dan migration yang secara eksplisit memakai koneksi `transaksi` untuk DB 2. Perintah ini membutuhkan koneksi PostgreSQL langsung. Jika komputer atau hosting tidak dapat menjangkau port PostgreSQL Supabase, jalankan migration dari komputer yang memiliki akses, gunakan pooler resmi Supabase, atau jalankan SQL migration melalui Supabase SQL Editor. Jangan mengubah hostname berdasarkan tebakan.

`php artisan db3:migrate` memvalidasi sumber RDF lalu mengganti isi graph DB 3 dengan ontologi dan data dari `database/rdf sparql/`. Perintah ini mengganti data graph yang ada; untuk menambahkan tanpa menghapus graph, gunakan `php artisan db3:migrate --no-replace`. Pemeriksaan data saja dapat dilakukan dengan `php artisan db3:validasi`.

Jalankan server Laravel dan Vite pada dua terminal:

```powershell
php artisan serve
```

```powershell
npm run dev
```

Buka alamat yang ditampilkan Artisan, biasanya `http://127.0.0.1:8000`. Untuk membuat aset produksi, jalankan `npm run build`.

## Konfigurasi layanan

Salin `.env.example` menjadi `.env` dan isi nilai rahasia secara lokal. Jangan masukkan secret, password, atau private key ke README, Git, JavaScript, maupun commit.

### Aplikasi dan PostgreSQL untuk migration

| Variabel | Keterangan |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE` | Nama, mode, URL, dan zona waktu aplikasi. Gunakan `APP_DEBUG=false` di production. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Bahasa aplikasi dan locale data Faker. |
| `APP_KEY` | Dibuat dengan `php artisan key:generate`. |
| `DB_CONNECTION` | Gunakan `katalog` sebagai koneksi default DB 1. |
| `DB_URL` atau `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Koneksi PostgreSQL langsung DB 1 untuk migration; `DB_SSLMODE=require` untuk koneksi TLS. |
| `DB2_URL` atau `DB2_HOST`, `DB2_PORT`, `DB2_DATABASE`, `DB2_USERNAME`, `DB2_PASSWORD` | Koneksi PostgreSQL langsung DB 2 untuk migration; `DB2_SSLMODE=require` untuk koneksi TLS. |

Isi `DB_URL`/`DB2_URL` **atau** pasangan host dan kredensial, sesuai cara koneksi yang dipakai. Nilai ini berbeda dari kunci Supabase REST di bawah. Koneksi langsung biasanya hanya diperlukan saat migration dan tidak digunakan oleh jalur runtime website.

### DB 1 dan DB 2 - Supabase REST

Ambil URL project serta API keys dari dashboard masing-masing project Supabase. DB 1 dan DB 2 adalah project/database terpisah.

| Variabel | Keterangan |
|---|---|
| `SUPABASE_DB1_URL`, `SUPABASE_DB2_URL` | URL dasar project Supabase. |
| `SUPABASE_DB1_PUBLISHABLE_KEY`, `SUPABASE_DB2_PUBLISHABLE_KEY` | Publishable key project, bila dibutuhkan alur autentikasi terkait. |
| `SUPABASE_DB1_SECRET_KEY`, `SUPABASE_DB2_SECRET_KEY` | Secret key untuk request server Laravel. Simpan hanya di server; jangan pernah kirim ke browser. |
| `SUPABASE_DB1_JWKS_URL`, `SUPABASE_DB2_JWKS_URL` | Endpoint JWKS project untuk verifikasi token jika dikonfigurasi. |
| `SUPABASE_CONNECT_TIMEOUT`, `SUPABASE_TIMEOUT` | Batas waktu koneksi dan request; contoh bawaan masing-masing 30 dan 120 detik. |

DB 1 menyimpan akun dan konfigurasi aplikasi. DB 2 menyimpan data transaksi dan interaksi. Pastikan API schema `public` serta grants yang dibutuhkan oleh migration sudah diterapkan.

### DB 3 - AllegroGraph / SPARQL

DB 3 menyimpan ontologi dan data wisata dari file Turtle `database/rdf sparql/medanvisit-ontologi.ttl` dan `database/rdf sparql/medanvisit-data.ttl`.

| Variabel | Keterangan |
|---|---|
| `SPARQL_DRIVER` | Driver data semantik; contoh `.env.example` memakai `allegrograph`. |
| `SPARQL_HOST`, `SPARQL_DATABASE` | Host layanan dan nama repository AllegroGraph. |
| `SPARQL_USERNAME`, `SPARQL_PASSWORD` | Kredensial Basic Auth repository. |
| `SPARQL_ENDPOINT` | Endpoint query, umumnya `https://<host>/repositories/<repository>/sparql`. |
| `SPARQL_STATEMENTS_ENDPOINT` | Endpoint untuk mengirim data, umumnya `https://<host>/repositories/<repository>/statements`. |
| `SPARQL_TIMEOUT` | Batas waktu query dan pengiriman RDF; contoh bawaan 60 detik. |

Sesuaikan kedua endpoint dengan provider/repository yang digunakan. Aplikasi memakai `SPARQL_ENDPOINT` untuk query; `rdf:kirim` memakai `SPARQL_STATEMENTS_ENDPOINT` untuk mengirim ontologi dan data.

### Midtrans

Gunakan Access Keys dari dashboard Midtrans. Mulai dari Sandbox sebelum mengaktifkan production.

| Variabel | Keterangan |
|---|---|
| `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY` | Kunci server dan client. Jika salah satunya kosong, checkout menggunakan mode simulasi. |
| `MIDTRANS_SIGNATURE_KEY` | Signature Secret Key untuk validasi notifikasi. |
| `MIDTRANS_MERCHANT_ID` | ID merchant. |
| `MIDTRANS_IS_PRODUCTION` | `false` untuk Sandbox; ubah menjadi `true` hanya saat siap memakai endpoint production. |

Atur Notification URL di dashboard Midtrans ke `https://<domain-aplikasi>/midtrans/notifikasi`. URL lokal tidak dapat dijangkau Midtrans tanpa tunnel publik.

### OpenRouter dan layanan opsional

| Variabel | Keterangan |
|---|---|
| `OPENROUTER_API_KEY` | API key untuk asisten AI. Tanpa key, Tanya Mas Nonov menggunakan jawaban fallback aplikasi. |
| `OPENROUTER_BASE_URL` | Default `https://openrouter.ai/api/v1`. |
| `OPENROUTER_MODEL` | Model OpenRouter; contoh bawaan `openrouter/free`. |
| `OPENROUTER_TIMEOUT` | Batas waktu request; contoh bawaan 120 detik. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | OAuth Google; callback default `${APP_URL}/masuk/google/callback`. |
| `MAIL_MAILER`, `MAIL_URL`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Pengiriman email. Default `MAIL_MAILER=log` hanya menulis email ke log, bukan mengirimkannya. Konfigurasikan SMTP untuk verifikasi akun dan email production. |
| `ROUTING_OSRM_URL`, `ROUTING_VALHALLA_URL`, `ROUTING_TIMEOUT` | Endpoint routing; nilai bawaan tersedia di `config/services.php`. |
| `FILESYSTEM_DISK`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Penyimpanan file; disk default adalah `local`, AWS/S3 opsional. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Default menggunakan database; tabel Laravel dibuat melalui migration. |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis opsional jika cache/queue dipindahkan dari database. |
| `LOG_CHANNEL`, `LOG_LEVEL` | Kanal dan ambang log Laravel. |

`.env.example` juga mencantumkan opsi Laravel lain seperti broadcast, maintenance, dan konfigurasi cache/queue lanjutan. Gunakan file tersebut sebagai daftar lengkap variabel yang dikenali aplikasi.

### Password akun seeder demo

Seeder demo dapat dioverride melalui `SEED_WISATAWAN_PASSWORD`, `SEED_ADMIN_TEMPAT_PASSWORD`, dan `SEED_SUPERADMIN_PASSWORD`. Jika tidak diatur, password default masing-masing adalah `@User1234`, `@Admin1234`, dan `@Adminmedanvisit1`. Password tersebut hanya untuk data demo; ganti sebelum penggunaan nyata dan jangan gunakan untuk akun production.

## Seeder dan data demo

`php artisan db:seed` menjalankan `DatabaseSeeder`, yaitu `SuperadminSeeder` dan `AdminTempatSeeder`. Seeder data demo lain dijalankan secara eksplisit:

```powershell
php artisan db:seed --class=Wisatawan500Seeder
php artisan db:seed --class=WisatawanRatingSeeder
php artisan db:seed --class=WisatawanUlasanSeeder
php artisan db:seed --class=WisatawanTransaksiSeeder
```

Seeder ulasan dan transaksi membutuhkan migration yang sesuai, konfigurasi DB 1/DB 2, serta data tempat yang sudah tersedia di DB 3. `Wisatawan500Seeder` membuat 10.000 akun contoh. Ulasan, rating, suka, dan pesanan yang dihasilkan adalah data simulasi, bukan kontribusi pengunjung sungguhan. Seeder interaksi dirancang agar aman dijalankan ulang menggunakan kunci akun-tempat atau referensi transaksi demo.

## Pemeriksaan dan dokumentasi lanjutan

```powershell
php artisan test
php artisan route:list
```

- Rincian koneksi REST dan deployment: `docs/supabase-rest.md`.
- Skema, migration, dan data RDF: `database/README.md`.
- Referensi query PostgreSQL: `database/sql pg/README.md`.
- Sumber RDF dan query SPARQL: folder `database/rdf sparql/`.

Setelah mengubah `.env`, jalankan `php artisan config:clear`. Jangan pernah commit file `.env` atau membagikan secret key.
