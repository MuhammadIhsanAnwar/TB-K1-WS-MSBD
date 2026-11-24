-- PostgreSQL lab topics 25-31: Views, table DDL, truncation, and constraints.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 25. View (session-local)
CREATE TEMP VIEW mv_lab_place_summary AS
SELECT p.slug, count(a.id) AS activity_count, coalesce(sum(a.amount), 0) AS total_amount
FROM mv_lab_places AS p
LEFT JOIN mv_lab_activity AS a ON a.place_id = p.id
GROUP BY p.slug;
SELECT * FROM mv_lab_place_summary ORDER BY total_amount DESC;

-- 26. Materialized View
-- Materialized views are persistent objects, not TEMP objects. Run only in a scratch DB,
-- then drop the view. Refresh it after its source data changes.
-- CREATE MATERIALIZED VIEW public.mv_lab_summary AS
-- SELECT current_database() AS database_name, now() AS created_at;
-- REFRESH MATERIALIZED VIEW public.mv_lab_summary;
-- DROP MATERIALIZED VIEW public.mv_lab_summary;

-- 27. CREATE TABLE Query (session-local)
CREATE TEMP TABLE mv_lab_create_demo (
    id bigint PRIMARY KEY,
    label text NOT NULL
);

-- 28. ALTER TABLE Query (changes only a temporary lab table)
ALTER TABLE mv_lab_create_demo ADD COLUMN created_at timestamptz DEFAULT now();

-- 29. DROP TABLE Query (the named object is temporary)
CREATE TEMP TABLE mv_lab_drop_demo (id integer);
DROP TABLE mv_lab_drop_demo;

-- 30. TRUNCATE TABLE Query (only clears temporary lab rows)
TRUNCATE TABLE mv_lab_ddl_demo;

-- 31. Constraints (PRIMARY KEY, FOREIGN KEY, UNIQUE, CHECK, NOT NULL)
CREATE TEMP TABLE mv_lab_constraints (
    id bigint PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES mv_lab_users(id),
    external_code text NOT NULL UNIQUE,
    score integer NOT NULL CHECK (score BETWEEN 0 AND 100)
);

