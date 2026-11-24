-- PostgreSQL lab topics 42-46: Transactions, prepared transactions, grants, RLS, and policies.
-- Prerequisite: run 00-lab-setup.sql in the same PostgreSQL session.
-- Examples use temporary mv_lab_* objects unless explicitly commented otherwise.

-- 42. Transaction Query (BEGIN, COMMIT, ROLLBACK, SAVEPOINT)
-- Run by itself. This example rolls back the changes after demonstrating a savepoint.
BEGIN;
UPDATE mv_lab_users SET name = 'transaction demo' WHERE id = 3;
SAVEPOINT mv_lab_checkpoint;
UPDATE mv_lab_users SET name = 'savepoint demo' WHERE id = 3;
ROLLBACK TO SAVEPOINT mv_lab_checkpoint;
ROLLBACK;
-- To commit an intentional test transaction instead, replace the final ROLLBACK with COMMIT.

-- 43. Prepared Transaction (requires max_prepared_transactions > 0 and DBA coordination)
-- Never leave a prepared transaction unresolved; it holds locks and must be completed
-- from a later session. It cannot include changes to TEMP tables; use a persistent scratch
-- table in a disposable database. Supabase plans may not permit this feature.
-- BEGIN;
-- UPDATE public.mv_lab_prepared_demo SET note = 'prepared demo' WHERE id = 1;
-- PREPARE TRANSACTION 'mv_lab_demo';
-- -- In a later session, choose exactly one:
-- COMMIT PREPARED 'mv_lab_demo';
-- ROLLBACK PREPARED 'mv_lab_demo';

-- 44. GRANT and REVOKE Query
GRANT SELECT ON TABLE mv_lab_places TO PUBLIC;
REVOKE SELECT ON TABLE mv_lab_places FROM PUBLIC;

-- 45. Row-Level Security (RLS) Query
-- DB 1 currently has RLS migrations for users and admin_tempat. DB 2 policy
-- requirements are table/role-specific. Review current policies before changing either DB.
-- ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
-- ALTER TABLE public.pesanan ENABLE ROW LEVEL SECURITY;

-- 46. CREATE POLICY Query
-- Policy predicates must use a trusted identity claim and match the actual application roles.
-- Do not apply a broad USING (true) policy to personal or transactional data.
-- Example deny-all policy for a controlled test table only:
-- CREATE POLICY mv_lab_deny_all ON public.mv_lab_policy_test
--     FOR SELECT TO authenticated USING (false);
-- DROP POLICY mv_lab_deny_all ON public.mv_lab_policy_test;

