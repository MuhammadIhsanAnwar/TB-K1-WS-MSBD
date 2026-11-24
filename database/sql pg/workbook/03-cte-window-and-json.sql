-- PostgreSQL lab topics 21-24: CTE, recursive CTE, window functions, and JSONB.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 21. Common Table Expression (CTE / WITH)
WITH paid_totals AS (
    SELECT user_id, sum(amount) AS total
    FROM mv_lab_activity
    WHERE state = 'paid'
    GROUP BY user_id
)
SELECT u.name, pt.total
FROM paid_totals AS pt
JOIN mv_lab_users AS u ON u.id = pt.user_id
ORDER BY pt.total DESC;

-- 22. Recursive CTE
WITH RECURSIVE user_tree AS (
    SELECT id, name, manager_id, 0 AS depth
    FROM mv_lab_users WHERE manager_id IS NULL
    UNION ALL
    SELECT child.id, child.name, child.manager_id, parent.depth + 1
    FROM mv_lab_users AS child
    JOIN user_tree AS parent ON child.manager_id = parent.id
)
SELECT id, name, manager_id, depth FROM user_tree ORDER BY depth, id;

-- 23. Window functions (ROW_NUMBER, RANK, DENSE_RANK, LAG, LEAD)
SELECT id, place_id, amount,
       row_number() OVER (PARTITION BY place_id ORDER BY amount DESC) AS row_number,
       rank() OVER (PARTITION BY place_id ORDER BY amount DESC) AS rank,
       dense_rank() OVER (PARTITION BY place_id ORDER BY amount DESC) AS dense_rank,
       lag(amount) OVER (PARTITION BY place_id ORDER BY created_at) AS previous_amount,
       lead(amount) OVER (PARTITION BY place_id ORDER BY created_at) AS next_amount
FROM mv_lab_activity;

-- 24. JSON and JSONB Query
SELECT id, profile->>'city' AS city
FROM mv_lab_users
WHERE profile @> '{"verified":true}'::jsonb;

SELECT slug, metadata->>'district' AS district
FROM mv_lab_places
WHERE jsonb_exists(metadata, 'district');

