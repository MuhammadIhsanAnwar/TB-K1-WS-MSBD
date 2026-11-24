-- PostgreSQL lab topics 32-41: Index variants, EXPLAIN, VACUUM, and REINDEX.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 32. CREATE INDEX Query
CREATE INDEX mv_lab_activity_user_idx ON mv_lab_activity (user_id);

-- 33. DROP INDEX Query
DROP INDEX mv_lab_activity_user_idx;

-- 34. Partial Index Query
CREATE INDEX mv_lab_activity_paid_idx
ON mv_lab_activity (created_at)
WHERE state = 'paid';

-- 35. Composite Index Query
CREATE INDEX mv_lab_activity_user_state_idx
ON mv_lab_activity (user_id, state, created_at DESC);

-- 36. Expression Index Query
CREATE INDEX mv_lab_user_name_lower_idx
ON mv_lab_users (lower(name));

-- 37. Covering Index Query (INCLUDE is supported by PostgreSQL 11+)
CREATE INDEX mv_lab_activity_place_cover_idx
ON mv_lab_activity (place_id, created_at DESC)
INCLUDE (user_id, amount, state);

-- 38. EXPLAIN Query (does not execute the query)
EXPLAIN
SELECT * FROM mv_lab_activity WHERE user_id = 2 AND state = 'paid';

-- 39. EXPLAIN ANALYZE Query (executes the read-only SELECT)
EXPLAIN (ANALYZE, BUFFERS)
SELECT count(*) FROM mv_lab_activity WHERE state = 'paid';

-- 40. VACUUM and ANALYZE Query
-- Run outside an explicit transaction. This only targets the temporary lab table.
VACUUM (ANALYZE) pg_temp.mv_lab_activity;

-- 41. REINDEX Query (only the temporary lab table index)
REINDEX TABLE pg_temp.mv_lab_activity;

