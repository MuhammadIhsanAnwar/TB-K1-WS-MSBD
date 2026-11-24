# Workbook PostgreSQL per topik

Workbook latihan 60 topik dipisah menurut kegunaan. Semua contoh menggunakan objek `mv_lab_*`
sementara, kecuali statement yang secara eksplisit tetap dikomentari karena memerlukan objek
persisten, hak DBA, atau infrastruktur luar. Tidak ada file di sini yang merupakan migration
atau harus dijalankan pada tabel aplikasi.

## Persiapan

1. Buka satu koneksi ke PostgreSQL development/scratch DB (DB 1 atau DB 2).
2. Jalankan `00-lab-setup.sql` pada koneksi itu.
3. Jalankan hanya file topik yang diperlukan pada koneksi yang sama. Tabel TEMP hilang saat
   koneksi ditutup.
4. Jalankan `99-lab-cleanup.sql` jika ingin menghapus objek TEMP sebelum menutup koneksi.

Jangan menjalankan pada database production. Contoh yang diberi komentar bukan query siap
eksekusi: review dan sesuaikan dengan role/infrastruktur lokal terlebih dahulu.

## Indeks

| File | Topik |
|---|---|
| `00-lab-setup.sql` | Fixture TEMP untuk pengguna, tempat, aktivitas, dan tabel contoh DDL |
| `01-core-dml-and-select.sql` | DDL, DML, DQL, DCL, TCL, SELECT, INSERT, UPDATE, DELETE, UPSERT |
| `02-filtering-joins-and-relational.sql` | Filter/urut/grup, DISTINCT, JOIN, SELF JOIN, subquery, EXISTS, IN, set operators, agregat |
| `03-cte-window-and-json.sql` | CTE, recursive CTE, window functions, JSON/JSONB |
| `04-views-ddl-and-constraints.sql` | View, materialized view, table DDL, TRUNCATE, constraints |
| `05-indexes-and-performance.sql` | Index biasa/partial/composite/expression/covering, EXPLAIN, VACUUM, REINDEX |
| `06-transactions-and-access-control.sql` | Transaction/savepoint, prepared transaction, GRANT/REVOKE, RLS, policy |
| `07-functions-triggers-and-cursors.sql` | Function, procedure, trigger, cursor, dynamic SQL |
| `08-partitioning-and-distributed-data.sql` | Partitioning, FDW, distributed query, sequence, CHECKPOINT |
| `09-session-prepared-and-returning.sql` | SET/SHOW, PREPARE/EXECUTE, RETURNING, parameter binding |
| `99-lab-cleanup.sql` | Hapus fixture TEMP |

Query untuk fitur web yang memakai schema DB 1/DB 2 tetap berada di file fitur pada folder
induk; workbook ini hanya mengajarkan pola PostgreSQL umum.
