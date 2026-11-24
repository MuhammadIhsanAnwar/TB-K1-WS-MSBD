-- PostgreSQL lab topics 47-51: Functions, procedures, triggers, cursors, and dynamic SQL.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 47. CREATE FUNCTION Query
-- Persistent object example; run in a development schema and clean up afterward.
-- CREATE FUNCTION public.mv_lab_rating_label(p_rating numeric)
-- RETURNS text LANGUAGE sql IMMUTABLE AS $$
--     SELECT CASE WHEN p_rating >= 4.5 THEN 'unggul' ELSE 'umum' END
-- $$;
-- SELECT public.mv_lab_rating_label(4.8);
-- DROP FUNCTION public.mv_lab_rating_label(numeric);

-- 48. CREATE PROCEDURE Query
-- Procedure DDL is persistent and is intentionally not executed by this workbook.
-- DB 2 scratch-database example:
-- CREATE PROCEDURE public.mv_lab_mark_cancelled(p_kode varchar)
-- LANGUAGE sql AS $$
--     UPDATE public.pesanan SET status = 'dibatalkan' WHERE kode = p_kode
-- $$;
-- CALL public.mv_lab_mark_cancelled('MV-DEMO');
-- DROP PROCEDURE public.mv_lab_mark_cancelled(varchar);

-- 49. CREATE TRIGGER Query
-- The test table is temporary; the function is persistent, so remove it after the test.
-- CREATE TEMP TABLE mv_lab_trigger_test (id bigint PRIMARY KEY, updated_at timestamptz);
-- CREATE FUNCTION public.mv_lab_touch_updated_at()
-- RETURNS trigger LANGUAGE plpgsql AS $$
-- BEGIN
--     NEW.updated_at := now();
--     RETURN NEW;
-- END
-- $$;
-- CREATE TRIGGER mv_lab_touch_updated_at
--     BEFORE UPDATE ON pg_temp.mv_lab_trigger_test
--     FOR EACH ROW EXECUTE FUNCTION public.mv_lab_touch_updated_at();
-- DROP TRIGGER mv_lab_touch_updated_at ON pg_temp.mv_lab_trigger_test;
-- DROP FUNCTION public.mv_lab_touch_updated_at();

-- 50. Cursor Query
-- Run as a standalone transaction.
BEGIN;
DECLARE mv_lab_cursor NO SCROLL CURSOR FOR
    SELECT id, name FROM mv_lab_users ORDER BY id;
FETCH NEXT FROM mv_lab_cursor;
FETCH ALL FROM mv_lab_cursor;
CLOSE mv_lab_cursor;
ROLLBACK;

-- 51. Dynamic SQL Query (identifiers are quoted with %I; never concatenate user input)
DO $$
DECLARE
    v_count bigint;
BEGIN
    EXECUTE format('SELECT count(*) FROM %I', 'mv_lab_places')
        INTO v_count;
END
$$;

