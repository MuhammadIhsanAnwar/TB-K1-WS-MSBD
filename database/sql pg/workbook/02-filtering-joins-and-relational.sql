-- PostgreSQL lab topics 11-20: Filtering, joins, subqueries, set operators, and aggregates.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 11. SELECT with WHERE, ORDER BY, GROUP BY, HAVING, LIMIT, and OFFSET
SELECT p.category, count(*) AS activity_count, sum(a.amount) AS total_amount
FROM mv_lab_activity AS a
JOIN mv_lab_places AS p ON p.id = a.place_id
WHERE a.state IN ('paid', 'pending')
GROUP BY p.category
HAVING sum(a.amount) >= 10000
ORDER BY total_amount DESC
LIMIT 10 OFFSET 0;

-- 12. DISTINCT Query
SELECT DISTINCT category FROM mv_lab_places ORDER BY category;

-- 13. JOIN Query (INNER, LEFT, RIGHT, FULL OUTER, CROSS)
SELECT u.name, p.slug FROM mv_lab_users AS u
INNER JOIN mv_lab_places AS p ON p.owner_id = u.id;

SELECT u.name, p.slug FROM mv_lab_users AS u
LEFT JOIN mv_lab_places AS p ON p.owner_id = u.id;

SELECT u.name, p.slug FROM mv_lab_users AS u
RIGHT JOIN mv_lab_places AS p ON p.owner_id = u.id;

SELECT u.name, p.slug FROM mv_lab_users AS u
FULL OUTER JOIN mv_lab_places AS p ON p.owner_id = u.id;

SELECT u.name, p.slug FROM mv_lab_users AS u
CROSS JOIN mv_lab_places AS p
LIMIT 10;

-- 14. SELF JOIN
SELECT child.name AS user_name, manager.name AS manager_name
FROM mv_lab_users AS child
LEFT JOIN mv_lab_users AS manager ON manager.id = child.manager_id;

-- 15. Non-correlated and correlated subqueries
SELECT slug FROM mv_lab_places
WHERE owner_id IN (SELECT id FROM mv_lab_users WHERE role = 'admin');

SELECT u.name
FROM mv_lab_users AS u
WHERE EXISTS (
    SELECT 1 FROM mv_lab_activity AS a
    WHERE a.user_id = u.id AND a.amount > 10000
);

-- 16. EXISTS and NOT EXISTS
SELECT u.id, u.name FROM mv_lab_users AS u
WHERE EXISTS (SELECT 1 FROM mv_lab_activity AS a WHERE a.user_id = u.id);

SELECT u.id, u.name FROM mv_lab_users AS u
WHERE NOT EXISTS (SELECT 1 FROM mv_lab_activity AS a WHERE a.user_id = u.id);

-- 17. IN and NOT IN (the subquery column should be non-NULL for NOT IN semantics)
SELECT id, name FROM mv_lab_users WHERE id IN (2, 4);
SELECT id, name FROM mv_lab_users WHERE id NOT IN (2, 4);

-- 18. UNION and UNION ALL
SELECT category AS label FROM mv_lab_places WHERE category = 'sejarah'
UNION
SELECT category AS label FROM mv_lab_places WHERE category = 'religi';

SELECT category AS label FROM mv_lab_places WHERE category = 'sejarah'
UNION ALL
SELECT category AS label FROM mv_lab_places WHERE category = 'sejarah';

-- 19. INTERSECT and EXCEPT
SELECT owner_id FROM mv_lab_places
INTERSECT
SELECT user_id FROM mv_lab_activity;

SELECT id FROM mv_lab_users
EXCEPT
SELECT user_id FROM mv_lab_activity;

-- 20. Aggregate Query (COUNT, SUM, AVG, MIN, MAX)
SELECT count(*) AS activity_count,
       sum(amount) AS total_amount,
       avg(amount) AS average_amount,
       min(amount) AS minimum_amount,
       max(amount) AS maximum_amount
FROM mv_lab_activity;

