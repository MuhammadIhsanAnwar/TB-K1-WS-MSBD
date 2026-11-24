-- PostgreSQL lab topics 01-10: Core SQL language groups and basic CRUD/upsert.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 01. DDL (Data Definition Language)
-- Create a temporary object rather than changing the application's permanent schema.
CREATE TEMP TABLE mv_lab_ddl_example (id integer PRIMARY KEY, note text NOT NULL);

-- 02. DML (Data Manipulation Language)
INSERT INTO mv_lab_ddl_demo (id, note) VALUES (1, 'demo');
UPDATE mv_lab_ddl_demo SET note = 'updated demo' WHERE id = 1;
DELETE FROM mv_lab_ddl_demo WHERE id = 1;

-- 03. DQL (Data Query Language)
SELECT id, name, role FROM mv_lab_users ORDER BY id;

-- 04. DCL (Data Control Language)
-- These grants are limited to the session-local lab table.
GRANT SELECT ON TABLE mv_lab_users TO PUBLIC;
REVOKE SELECT ON TABLE mv_lab_users FROM PUBLIC;

-- 05. TCL (Transaction Control Language)
-- Run this block by itself; the final ROLLBACK leaves the lab rows unchanged.
BEGIN;
UPDATE mv_lab_users SET name = 'temporary name' WHERE id = 2;
SAVEPOINT mv_lab_savepoint;
UPDATE mv_lab_users SET name = 'rolled back name' WHERE id = 2;
ROLLBACK TO SAVEPOINT mv_lab_savepoint;
ROLLBACK;

-- 06. SELECT Query (real application tables, select the matching database)
-- DB 1 (katalog):
SELECT id, name, email, peran FROM users ORDER BY id DESC LIMIT 10;
-- DB 2 (transaksi):
SELECT kode, tempat_slug, total, status, created_at
FROM pesanan ORDER BY created_at DESC LIMIT 10;

-- 07. INSERT Query (temporary lab data)
INSERT INTO mv_lab_activity (id, user_id, place_id, amount, state)
VALUES (199, 2, 10, 5000, 'pending');

-- 08. UPDATE Query
UPDATE mv_lab_activity SET state = 'paid' WHERE id = 199;

-- 09. DELETE Query
DELETE FROM mv_lab_activity WHERE id = 199;

-- 10. UPSERT (INSERT ... ON CONFLICT)
INSERT INTO mv_lab_places (id, slug, owner_id, category, rating)
VALUES (12, 'tjong-a-fie', 1, 'sejarah', 4.9)
ON CONFLICT (id) DO UPDATE
SET rating = EXCLUDED.rating, category = EXCLUDED.category
RETURNING id, slug, rating;

