# Query PostgreSQL MedanVisit

- `01-ai-dan-ringkasan.sql` menyalin query SQL native dari `app/Services/PostgreSQLService.php`.
  Laravel menjalankannya melalui `DB::connection(...)->select()` dengan parameter binding.
- File `02`–`06` mencatat padanan SQL PostgreSQL untuk operasi website lain. Implementasi aktif
  di `app/Support/SupabaseApi.php` menggunakan Supabase PostgREST (`/rest/v1` dan `/rest/v1/rpc`),
  bukan mengirim SQL mentah dari Laravel. Placeholder `$1`, `$2`, dan seterusnya menunjukkan
  parameter; bagian `{{...}}` adalah template dan perlu diganti hanya dengan identifier allowlist.
- Function, procedure, view, dan trigger yang dipanggil atau menjadi dependensi bisnis didefinisikan
  di `database/migrations/`; file query ini adalah inventaris/padanan, bukan pengganti migration.
- Review, transaksi, pencarian, serta rencana memakai koneksi/database `transaksi`; akun dan
  pengaturan situs memakai `katalog`. Kunjungan dan suka yang terlihat di halaman peta berasal
  dari layanan lain/cache, bukan query PostgreSQL pada daftar ini.
