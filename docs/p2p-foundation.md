# MedanVisit Hub (Fase 1)

## Audit singkat

- Aplikasi memakai Laravel 13 dan autentikasi sesi khusus di `session('pengguna')`; bukan Laravel Auth atau Sanctum.
- Akses runtime ke DB1 dan DB2 memakai Supabase REST melalui HTTPS. Migrasi PostgreSQL tetap dijalankan dari lingkungan yang dapat menjangkau DB1.
- Layout publik menggunakan Blade, Vite, dan ES modules. Belum ada browser Supabase Realtime client atau rute API.
- Fase ini hanya menambahkan peer registry, Presence, connection request/accept/reject, signaling SDP/ICE, serta pesan langsung lewat WebRTC DataChannel. Belum ada post, sinkronisasi event, atau media transfer.

## Yang perlu disiapkan

1. Gunakan signing key RSA atau EC P-256 (ES256) yang private key-nya Anda miliki. Supabase tidak dapat mengekspor private key dari key yang dibuat di dashboard. Untuk dipakai Laravel, buat pasangan P-256 sendiri:
   ```sh
   openssl ecparam -name prime256v1 -genkey -noout -out medanvisit-p2p-private.pem
   openssl pkey -in medanvisit-p2p-private.pem -pubout -out medanvisit-p2p-public.pem
   ```
   Impor key pair tersebut ke **Supabase DB1 → Authentication → JWT Signing Keys**. Simpan file private PEM hanya di server Laravel, lalu gunakan `kid` dari key yang diimpor:
   - `SUPABASE_DB1_JWT_PRIVATE_KEY_PATH`: path private PEM pada server Laravel.
   - `SUPABASE_DB1_JWT_KEY_ID`: `kid` signing key yang terdaftar di Supabase.
   `kid` yang dibuat Supabase sendiri tidak dapat digunakan untuk menandatangani JWT di Laravel tanpa private key pasangannya.
2. Pastikan DB1 memakai URL dan publishable key yang sudah ada di konfigurasi aplikasi. `SUPABASE_DB1_SECRET_KEY` tetap server-only dan tidak dikirim ke browser.
3. Aktifkan Supabase Realtime untuk DB1 dan nonaktifkan **Allow public access**. Migrasi memasang kebijakan private-channel pada `realtime.messages`.
   Sebelum rollout, tinjau kebijakan lama pada `realtime.messages`: kebijakan permissive yang sudah ada dapat memperluas akses channel; hapus/persempit kebijakan lama yang tidak dibutuhkan setelah menilai dampaknya pada fitur lain.
4. TURN opsional untuk memulai, tetapi diperlukan untuk jaringan NAT/firewall yang tidak dapat membuat koneksi langsung. Untuk coturn, isi `TURN_URLS` (dipisah koma) dan `TURN_SHARED_SECRET`; API Laravel menerbitkan kredensial berjangka pendek. Untuk Metered, gunakan `WEBRTC_TURN_PROVIDER=metered`, URL di `METERED_TURN_URLS`, `METERED_TURN_USERNAME`, dan `METERED_TURN_CREDENTIAL`. Nilai username/credential disimpan di environment server dan hanya dikirim melalui respons API P2P yang terautentikasi serta `Cache-Control: no-store`; jangan menaruhnya di JavaScript. `METERED_TURN_API_KEY` tidak digunakan karena format respons API Metered belum diverifikasi. Tanpa kredensial TURN, browser memakai STUN dan UI memperingatkan keterbatasannya.
5. Jalankan aplikasi melalui HTTPS (localhost juga memenuhi secure context browser). Pastikan browser dapat membuka koneksi WebSocket ke Supabase serta koneksi ICE ke STUN/TURN.

Jangan memakai `service_role`, Supabase secret key, atau private signing key di JavaScript. Token Realtime Laravel berlaku lima menit dan diperbarui saat halaman aktif.

## Migrasi dan uji dua browser

Migration `2026_11_17_000100_buat_peer_foundation.php` menambahkan `peer_devices`, `peer_sessions`, dan `peer_blocks` pada DB1. Tidak ada perubahan pada tabel akun yang sudah ada dan tidak ada migration DB2 pada fase ini.

Jalankan migration dari komputer/deployment job yang bisa mengakses PostgreSQL DB1:

```powershell
php artisan migrate --database=katalog
npm run build
php artisan test --filter=P2PFoundationTest
```

Jika cPanel tidak dapat menjangkau port PostgreSQL, jalankan migration dari komputer pengembang atau terapkan migration DB1 lewat Supabase SQL Editor. Jangan menjalankan `migrate:fresh` pada database yang berisi data.

Setelah signing key, migration, dan Realtime disiapkan:

1. Buka `/MedanVisit-Hub` pada dua browser dengan akun MedanVisit berbeda.
2. Pastikan masing-masing browser terlihat pada daftar **Peer online**.
3. Browser pertama memilih **Minta koneksi**; browser kedua menekan **Terima**.
4. Tunggu status WebRTC menjadi `connected`. Jenis jalur menampilkan `Direct` atau `Relay (TURN)` jika browser menyediakan statistik ICE.
5. Kirim pesan. Pesan hanya memakai `RTCDataChannel`; Laravel/Supabase hanya menangani autentikasi, permintaan koneksi, Presence, dan signaling.

## Batasan dan deployment

- TURN belum tersedia sebagai layanan yang dikelola aplikasi. Tanpa `TURN_URLS`/`TURN_SHARED_SECRET`, koneksi lintas NAT tertentu bisa gagal walaupun signaling berhasil.
- Konfigurasi Metered tetap memprioritaskan kandidat koneksi langsung: aplikasi tidak mengatur `iceTransportPolicy: "relay"`. TURN hanya dipilih ICE bila koneksi langsung tidak berhasil/lebih buruk menurut negosiasi ICE.
- Halaman Hub memuat stylesheet mandiri `public/css/medanvisit-hub.css`, sehingga tata letaknya tidak bergantung pada rebuild Vite. Sertakan file tersebut saat deployment dan jalankan `php artisan view:clear` setelah mengganti view.
- Hub bergantung pada modul dinamis `resources/js/p2p/index.js`. Setelah `npm run build`, deploy seluruh isi `public/build/` bersama perubahan aplikasi. Pastikan `public/build/manifest.json` mencantumkan `resources/js/p2p/index.js` pada `dynamicImports` entry `resources/js/app.js`; manifest lama membuat halaman berhenti pada teks pemuatan awal.
- Shared hosting tidak perlu menjalankan proses Laravel yang terus hidup untuk Presence atau WebRTC: browser berhubungan langsung dengan Supabase Realtime dan browser peer lain. Hosting tetap harus melayani halaman HTTPS dan endpoint Laravel HTTPS; server TURN harus dapat dijangkau browser melalui transport yang diizinkan jaringan.
- `Peer online` berasal dari Supabase Presence (bukan hasil polling Laravel). WebRTC mengirim isi pesan secara langsung; jika signaling atau ICE gagal, UI menampilkan status gagal, bukan beralih diam-diam ke HTTP.
- Status perangkat tersimpan di DB1, tetapi masa online ditentukan oleh koneksi Presence. Request yang tidak dijawab kedaluwarsa setelah lima menit; sesi signaling yang diterima berlaku satu hari.

## Referensi resmi

- [Supabase Realtime Authorization](https://supabase.com/docs/guides/realtime/authorization)
- [Supabase JWT Signing Keys](https://supabase.com/docs/guides/auth/signing-keys)
- [Supabase Presence](https://supabase.com/docs/guides/realtime/presence)
- [Supabase Broadcast](https://supabase.com/docs/guides/realtime/broadcast)
- [Laravel 13 Routing](https://laravel.com/docs/13.x/routing)
- [MDN RTCPeerConnection](https://developer.mozilla.org/en-US/docs/Web/API/RTCPeerConnection)
- [MDN RTCDataChannel](https://developer.mozilla.org/en-US/docs/Web/API/RTCDataChannel)
