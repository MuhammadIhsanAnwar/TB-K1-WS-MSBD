# Query PostgreSQL MedanVisit (DB 1 dan DB 2)

Folder ini mendokumentasikan operasi PostgreSQL yang mendasari fitur website. Sebagian besar
request Laravel menggunakan Supabase REST/PostgREST (`/rest/v1` dan `/rest/v1/rpc`), bukan
mengirim SQL mentah. SQL di bawah adalah padanan relasional untuk memahami operasi dan skema.
Pengecualian runtime: memori Tanya Mas Nonov menggunakan Query Builder Laravel langsung ke DB 1.

## Indeks berdasarkan fitur

| File | Fitur dan query yang dicakup | Database |
|---|---|---|
| `01-ai-dan-ringkasan.sql` | Popularitas, jumlah pesanan/pencarian, profil, riwayat, ranking ulasan dan suka, definisi view ringkasan | DB 1 + DB 2 |
| `02-akun-admin-pesan-pengaturan.sql` | Akun dan penonaktifan, OTP tindakan akun, admin-tempat, inbox/balasan, verifikasi email, reset kata sandi, pengaturan situs | DB 1 |
| `03-pesanan-pembayaran-tiket.sql` | Pesanan dan item, pembayaran, transaksi demo, pembatalan, metode bayar, penerbitan QR, check-in dan kedaluwarsa tiket | DB 2 |
| `04-ulasan-dan-rating.sql` | Ulasan dan moderasi, rating, suka, upsert seeder, ringkasan | DB 2 |
| `05-log-pencarian-dan-analitik.sql` | Log pencarian, jumlah log, kata kunci tanpa hasil | DB 2 |
| `06-rencana-perjalanan.sql` | Rencana perjalanan, tujuan berurutan, moda, minat, penyimpanan/perubahan/penghapusan atomik | DB 2 |
| `08-memori-tanya-mas-nonov.sql` | Simpan, baca, dan perbarui memori chat terenkripsi | DB 1 |

## Pemetaan implementasi aktif

Operasi pada `app/Support/SupabaseApi.php` dipetakan ke file berikut:

- **Akun dan administrasi DB 1**: `findUserByEmail`, `findUserById`, `users`,
  `usersNonaktifKedaluwarsa`, `simpanTautanTindakanAkun`, `tautanTindakanAkun`,
  `hapusTautanTindakanAkun`, `createUser`, `updateUser`, `deleteUser`,
  `adminTempat`, `tempatAdmin`, `assignTempatAdmin`, `hapusTempatAdmin`,
  `adminUntukTempat`, `pesanPenggunaInbox`, `pesanPengguna`, `buatPesanPengguna`,
  `balasanPesanPengguna`, `buatBalasanPesan`, `ubahStatusBalasanEmail`,
  `tandaiPesanDibalas`, `createVerificationToken`, `findVerificationToken`,
  `deleteVerificationToken`, `createPasswordResetToken`, `findPasswordResetToken`,
  `deletePasswordResetToken`, `pengaturanSitus`, `pengaturanSitusAwalan`,
  `simpanPengaturanSitus`, dan `ping` → file `02`.
- **Pesanan dan tiket DB 2**: `pesanan`, `pesananDenganKodeQr`, `pesananItem`,
  `buatPesanan`, `buatTransaksiDemo`, `bayarPesanan`, `pesananSeederDemo`,
  `batalkanPesanan`, `ubahMetodePesanan`, `cariEtiket`, `buatEtiket`,
  `pesananLunasBelumCheckin`, `pakaiTiket`, `kedaluwarsakanEtiket`,
  `jumlahPesanan`, dan `riwayatPesanan` → file `01` dan `03`.
- **Popularitas, ulasan, rating, dan suka DB 2**: `ringkasanSukaTempat`,
  `ringkasanPopularitasTempat`, `peringkatUlasan`, `peringkatSuka`,
  `sukaTempatPengguna`, `sudahSukaTempat`, `tambahSukaTempat`, `hapusSukaTempat`,
  `ulasan`, `ulasanHalaman`, `ringkasanUlasan`, `ringkasanRatingTempat`,
  `upsertRatingTempat`, `tulisUlasan`, `upsertUlasan`, `upsertSukaTempat`,
  `ubahUlasan`, dan `hapusUlasan` → file `01` dan `04`.
- **Pencarian DB 2**: `catatCari`, `celahKonten`, dan `jumlahCari` → file `01` dan `05`.
- **Rencana DB 2**: `rencana`, `rencanaMinat`, `rencanaModa`, dan `simpanRencana` → file `06`.
- **Tujuan dan perubahan rencana DB 2**: `rencanaTujuan`, `perbaruiRencana`,
  `hapusRencana` → file `06`.
- Query generik internal `getRows`, `semua`, `insert`, `upsertRows`, `ubahBaris`, dan
  `rpc` dijelaskan melalui operasi fitur yang memanggilnya, bukan diduplikasi sebagai query
  terpisah. Validasi, retry, header, dan serialisasi JSON tetap ditangani `SupabaseApi`.

`app/Services/PostgreSQLService.php` memakai operasi popularitas, ranking, jumlah pesanan,
profil, dan riwayat yang sama; `ringkasanUlasanTempat()` mendelegasikan ke ringkasan popularitas
gabungan. Query-query tersebut berada di file `01` dan `04`.
`app/Services/TanyaMasNonovMemori.php` menggunakan Query Builder DB 1 untuk file `08`.
Query kalkulasi konten wisata sendiri berada di AllegroGraph/SPARQL, bukan PostgreSQL.
Insight tayangan halaman dan check-in memakai Laravel Cache melalui `app/Support/Kunjungan.php`,
bukan query tabel PostgreSQL khusus. Query migrasi skema dan operasi seeder tetap berada di
`database/migrations/` dan `database/seeders/`; file dalam folder ini hanya mendokumentasikan
query fitur website.

## Parameter, database, dan schema objects

- `$1`, `$2`, dan seterusnya adalah parameter terikat (prepared/bind values), bukan teks yang
  boleh digabungkan ke SQL. `{{...}}` menandai struktur dinamis dokumentasi; nama tabel/kolom
  harus berasal dari allowlist aplikasi.
- DB 1 (`katalog`) menyimpan akun, relasi admin, pesan, token, pengaturan, dan memori chat.
  DB 2 (`transaksi`) menyimpan pesanan/tiket, suka, rating/ulasan, pencarian, dan rencana.
  Keduanya adalah database terpisah; `JOIN` biasa tidak dapat menggabungkan tabel lintas DB.
- Function, procedure, view, trigger, indeks, constraint, grant, dan RLS yang membentuk
  kontrak query merupakan sumber kebenaran di `database/migrations/`. Migration penting:
  `2026_11_06_000300_buat_tabel_transaksi.php`,
  `2026_11_06_000400_buat_objek_transaksi.php`,
  `2026_11_15_000100_function_rpc_supabase.php`,
  `2026_11_25_000100_buat_pesan_tanya_mas_nonov.php`,
  `2026_11_26_000100_buat_suka_tempat.php`,
  `2026_11_26_000200_buat_rating_tempat.php`,
  `2026_11_27_000100_normalisasi_db1_db2.php`, dan
  `2026_11_29_000100_buat_ringkasan_popularitas_tempat.php`.
- View ringkasan digunakan agar agregat dijalankan oleh PostgreSQL. Metrik tempat yang
  dibutuhkan Tanya Mas Nonov dibaca dari `ringkasan_popularitas_tempat`; nama dan kategori
  tempat tetap dicocokkan dengan katalog RDF di aplikasi.

## Batasan dan cara membaca

Semua statement adalah padanan relasional untuk memahami operasi aplikasi. Runtime Supabase
mengirim request PostgREST/RPC melalui HTTPS; ia tidak mengirim statement SQL dokumentasi ini.
Placeholder `$1`, `$2`, dan seterusnya mewakili bind value. Placeholder `{{...}}` menandai
bagian dinamis yang dibatasi/di-allowlist oleh aplikasi, bukan teks yang boleh diinterpolasi.
Objek dan definisi database yang sebenarnya tetap bersumber dari `database/migrations/`.
