-- Optional cleanup; execute in the same session as 00-lab-setup.sql.
-- Optional cleanup of session-local demo objects. Closing the DB session also removes them.
DROP VIEW IF EXISTS pg_temp.mv_lab_place_summary;
DROP TABLE IF EXISTS pg_temp.mv_lab_activity;
DROP TABLE IF EXISTS pg_temp.mv_lab_places;
DROP TABLE IF EXISTS pg_temp.mv_lab_constraints;
DROP TABLE IF EXISTS pg_temp.mv_lab_users;
DROP TABLE IF EXISTS pg_temp.mv_lab_ddl_demo;
DROP TABLE IF EXISTS pg_temp.mv_lab_ddl_example;
DROP TABLE IF EXISTS pg_temp.mv_lab_create_demo;
DROP TABLE IF EXISTS pg_temp.mv_lab_range_2026;
DROP TABLE IF EXISTS pg_temp.mv_lab_list_medan;
DROP TABLE IF EXISTS pg_temp.mv_lab_hash_0;
DROP TABLE IF EXISTS pg_temp.mv_lab_hash_1;
DROP TABLE IF EXISTS pg_temp.mv_lab_range_parent;
DROP TABLE IF EXISTS pg_temp.mv_lab_list_parent;
DROP TABLE IF EXISTS pg_temp.mv_lab_hash_parent;
DROP SEQUENCE IF EXISTS pg_temp.mv_lab_sequence;
