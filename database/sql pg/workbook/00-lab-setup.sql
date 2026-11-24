-- MedanVisit PostgreSQL query workbook: DB 1 (katalog) and DB 2 (transaksi).
-- This is a reference workbook, not a migration or a batch to run blindly.
-- Run one section at a time in a development database. The lab fixtures are TEMP tables,
-- so their rows disappear when the session ends. Sections requiring cluster privileges,
-- remote infrastructure, or persistent objects are comments and require DBA review.
--
-- DB 1 and DB 2 are separate PostgreSQL databases. Run this workbook in either connection.
-- It cannot join their real tables directly; use the application/API for cross-database data.
-- Application query references are documented in the feature files beside this workbook.

-- LAB SETUP (temporary; safe for either database)
DO $$
BEGIN
    IF to_regclass('pg_temp.mv_lab_place_summary') IS NOT NULL THEN
        DROP VIEW pg_temp.mv_lab_place_summary;
    END IF;
    IF to_regclass('pg_temp.mv_lab_activity') IS NOT NULL THEN
        DROP TABLE pg_temp.mv_lab_activity;
    END IF;
    IF to_regclass('pg_temp.mv_lab_places') IS NOT NULL THEN
        DROP TABLE pg_temp.mv_lab_places;
    END IF;
    IF to_regclass('pg_temp.mv_lab_users') IS NOT NULL THEN
        DROP TABLE pg_temp.mv_lab_users;
    END IF;
END
$$;

CREATE TEMP TABLE mv_lab_users (
    id bigint PRIMARY KEY,
    name text NOT NULL,
    role text NOT NULL CHECK (role IN ('wisatawan', 'admin')),
    manager_id bigint REFERENCES mv_lab_users(id),
    profile jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE TEMP TABLE mv_lab_places (
    id bigint PRIMARY KEY,
    slug text NOT NULL UNIQUE,
    owner_id bigint NOT NULL REFERENCES mv_lab_users(id),
    category text NOT NULL,
    rating numeric(2, 1) CHECK (rating BETWEEN 1 AND 5),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TEMP TABLE mv_lab_activity (
    id bigint PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES mv_lab_users(id),
    place_id bigint NOT NULL REFERENCES mv_lab_places(id),
    amount integer NOT NULL CHECK (amount >= 0),
    state text NOT NULL CHECK (state IN ('paid', 'pending', 'cancelled')),
    created_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO mv_lab_users (id, name, role, manager_id, profile) VALUES
    (1, 'Ayu', 'admin', NULL, '{"city":"Medan","verified":true}'),
    (2, 'Bima', 'wisatawan', 1, '{"city":"Medan","verified":true}'),
    (3, 'Citra', 'wisatawan', 1, '{"city":"Binjai","verified":false}'),
    (4, 'Dodi', 'wisatawan', 2, '{"city":"Medan","verified":true}'),
    (5, 'Eka', 'wisatawan', 2, '{"city":"Deli Serdang","verified":false}');

INSERT INTO mv_lab_places (id, slug, owner_id, category, rating, metadata) VALUES
    (10, 'istana-maimun', 1, 'sejarah', 4.8, '{"district":"Medan Maimun"}'),
    (11, 'masjid-raya', 1, 'religi', 4.6, '{"district":"Medan Kota"}'),
    (12, 'tjong-a-fie', 1, 'sejarah', 4.7, '{"district":"Medan Barat"}');

INSERT INTO mv_lab_activity (id, user_id, place_id, amount, state, created_at) VALUES
    (100, 2, 10, 20000, 'paid', now() - interval '3 days'),
    (101, 3, 10, 10000, 'paid', now() - interval '2 days'),
    (102, 4, 11, 15000, 'pending', now() - interval '1 day'),
    (103, 5, 12, 25000, 'paid', now());


CREATE TEMP TABLE mv_lab_ddl_demo (id integer PRIMARY KEY, note text NOT NULL);
