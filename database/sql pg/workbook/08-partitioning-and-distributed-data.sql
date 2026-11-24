-- PostgreSQL lab topics 52-56: Partitioning, FDW, distributed query, sequence, and checkpoint.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 52. Partitioning Query (RANGE, LIST, HASH; examples create temporary objects)
CREATE TEMP TABLE mv_lab_range_parent (
    id bigint NOT NULL,
    created_at date NOT NULL
) PARTITION BY RANGE (created_at);
CREATE TEMP TABLE mv_lab_range_2026 PARTITION OF mv_lab_range_parent
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');

CREATE TEMP TABLE mv_lab_list_parent (
    id bigint NOT NULL,
    region text NOT NULL
) PARTITION BY LIST (region);
CREATE TEMP TABLE mv_lab_list_medan PARTITION OF mv_lab_list_parent
    FOR VALUES IN ('Medan');

CREATE TEMP TABLE mv_lab_hash_parent (
    id bigint NOT NULL,
    user_id bigint NOT NULL
) PARTITION BY HASH (user_id);
CREATE TEMP TABLE mv_lab_hash_0 PARTITION OF mv_lab_hash_parent
    FOR VALUES WITH (MODULUS 2, REMAINDER 0);
CREATE TEMP TABLE mv_lab_hash_1 PARTITION OF mv_lab_hash_parent
    FOR VALUES WITH (MODULUS 2, REMAINDER 1);

-- 53. Foreign Data Wrapper (FDW)
-- Optional DBA setup only. Requires postgres_fdw availability, network reachability,
-- least-privilege remote credentials, and provider support. Never commit passwords.
-- CREATE EXTENSION IF NOT EXISTS postgres_fdw;
-- CREATE SERVER mv_lab_remote_db OPTIONS
--     (host '<approved-host>', dbname '<remote-database>', port '5432', sslmode 'require');
-- CREATE USER MAPPING FOR CURRENT_USER SERVER mv_lab_remote_db
--     OPTIONS (user '<read-only-role>', password '<retrieve-from-secret-store>');
-- CREATE FOREIGN TABLE mv_lab_remote_places (
--     id bigint, slug text, category text
-- ) SERVER mv_lab_remote_db OPTIONS (schema_name 'public', table_name 'places');
-- DROP FOREIGN TABLE mv_lab_remote_places;
-- DROP USER MAPPING FOR CURRENT_USER SERVER mv_lab_remote_db;
-- DROP SERVER mv_lab_remote_db;

-- 54. Distributed Query (pushdown, join pushdown, semi-join)
-- Requires foreign tables configured in section 53. Pushdown depends on the FDW,
-- PostgreSQL versions, compatible types/operators, and the EXPLAIN plan.
-- Pushdown candidate:
-- SELECT id, slug FROM mv_lab_remote_places WHERE category = 'sejarah';
-- Join-pushdown candidate (when both relations are on a compatible foreign server):
-- SELECT p.slug, r.rating
-- FROM mv_lab_remote_places AS p
-- JOIN mv_lab_remote_ratings AS r ON r.place_id = p.id;
-- Semi-join candidate:
-- SELECT p.id, p.slug FROM mv_lab_remote_places AS p
-- WHERE EXISTS (
--     SELECT 1 FROM mv_lab_remote_ratings AS r WHERE r.place_id = p.id
-- );
-- Verify remote execution with EXPLAIN (VERBOSE); do not assume pushdown occurred.

-- 55. Sequence Query
CREATE TEMP SEQUENCE mv_lab_sequence START WITH 1 INCREMENT BY 1;
SELECT nextval('pg_temp.mv_lab_sequence') AS generated_value;
SELECT currval('pg_temp.mv_lab_sequence') AS current_value;

-- 56. CHECKPOINT Query
-- Cluster-wide operation; superuser/managed-service permissions may be required.
-- Run only during DBA-managed maintenance, never as part of application request handling.
-- CHECKPOINT;

