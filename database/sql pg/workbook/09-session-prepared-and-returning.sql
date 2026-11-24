-- PostgreSQL lab topics 57-60: SET/SHOW, prepared execution, RETURNING, and bind parameters.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 57. SET and SHOW Configuration Query
SHOW search_path;
SET TIME ZONE 'Asia/Jakarta';
SHOW TIME ZONE;

-- 58. PREPARE and EXECUTE Query (server-side prepared statement)
PREPARE mv_lab_user_by_id(bigint) AS
    SELECT id, name FROM mv_lab_users WHERE id = $1;
EXECUTE mv_lab_user_by_id(2);
DEALLOCATE mv_lab_user_by_id;

-- 59. RETURNING Clause
INSERT INTO mv_lab_ddl_demo (id, note) VALUES (2, 'returning demo')
RETURNING id, note;
UPDATE mv_lab_ddl_demo SET note = 'changed' WHERE id = 2
RETURNING id, note;
DELETE FROM mv_lab_ddl_demo WHERE id = 2
RETURNING id;

-- 60. Query with parameter binding
-- SQL prepared-statement form:
PREPARE mv_lab_bound_lookup(bigint) AS
    SELECT id, name FROM mv_lab_users WHERE id = $1;
EXECUTE mv_lab_bound_lookup(2);
DEALLOCATE mv_lab_bound_lookup;
-- Laravel/PDO form: DB::connection('transaksi')->select(
--     'SELECT kode, total FROM pesanan WHERE id_pengguna = ?', [$idPengguna]
-- );
-- Bind values; never interpolate user input into SQL or identifier names.

