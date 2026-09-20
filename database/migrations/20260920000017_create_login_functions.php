<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-036 — public-context authentication functions (ADR-21).
 *
 * app.users and app.roles are RLS-gated on current_setting('app.user_id'), which
 * an unauthenticated login request cannot set. These SECURITY DEFINER functions
 * run with the privileges of their owner (app_migrator, the table owner, who is
 * exempt from RLS unless FORCE ROW LEVEL SECURITY is set), so they are the sole
 * window into credential data without an authenticated session context.
 *
 * The leak is deliberately bounded: fn_login_lookup is keyed by an exact
 * username match and returns a fixed column list; fn_login_record applies only
 * the failure-counter/lockout/success-reset state machine and the optional
 * password rehash. EXECUTE is revoked from PUBLIC and granted to app_rw only.
 */

final class CreateLoginFunctions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
CREATE OR REPLACE FUNCTION app.fn_login_lookup(p_username text)
RETURNS TABLE (
    id bigint, username text, email text, password_hash text, full_name text,
    "position" text, org_id bigint, status text, mfa_enabled boolean,
    mfa_secret_enc text, must_change_password boolean, failed_login_count integer,
    locked_until timestamp, last_login_at timestamp, password_changed_at timestamp,
    version integer, mfa_required boolean
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = app, public
AS $$
DECLARE
    r app.users%ROWTYPE;
BEGIN
    SELECT * INTO r FROM app.users
    WHERE lower(app.users.username) = lower(p_username)
      AND app.users.deleted_at IS NULL;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    RETURN QUERY
        SELECT r.id,
               r.username::text,
               r.email::text,
               r.password_hash::text,
               r.full_name::text,
               r."position"::text,
               r.org_id,
               r.status::text,
               r.mfa_enabled,
               r.mfa_secret_enc::text,
               r.must_change_password,
               r.failed_login_count,
               r.locked_until,
               r.last_login_at,
               r.password_changed_at,
               r.version,
               (r.mfa_enabled OR EXISTS (
                   SELECT 1
                   FROM app.user_roles ur
                   JOIN app.roles r2 ON r2.id = ur.role_id
                   WHERE ur.user_id = r.id
                     AND r2.requires_mfa
               ))::boolean AS mfa_required;
END;
$$;
SQL
        );

        $this->execute(<<<SQL
CREATE OR REPLACE FUNCTION app.fn_login_record(
    p_user_id bigint,
    p_succeeded boolean,
    p_new_password_hash text
)
RETURNS TABLE (
    id bigint, username text, email text, password_hash text, full_name text,
    "position" text, org_id bigint, status text, mfa_enabled boolean,
    mfa_secret_enc text, must_change_password boolean, failed_login_count integer,
    locked_until timestamp, last_login_at timestamp, password_changed_at timestamp,
    version integer, mfa_required boolean
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = app, public
AS $$
DECLARE
    r app.users%ROWTYPE;
    v_new_attempts integer;
    v_locked_until timestamp;
BEGIN
    -- Lock the row for the duration of the statement so concurrent login
    -- attempts cannot both read the same failure counter.
    SELECT * INTO r FROM app.users
    WHERE app.users.id = p_user_id AND app.users.deleted_at IS NULL
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF p_succeeded THEN
        UPDATE app.users
           SET failed_login_count = 0,
               locked_until = NULL,
               last_login_at = CURRENT_TIMESTAMP,
               password_hash = COALESCE(p_new_password_hash, app.users.password_hash)
         WHERE app.users.id = p_user_id;
    ELSE
        v_new_attempts := r.failed_login_count + 1;
        v_locked_until := CASE
            WHEN v_new_attempts >= 5 THEN CURRENT_TIMESTAMP + INTERVAL '15 minutes'
            ELSE NULL
        END;
        UPDATE app.users
           SET failed_login_count = v_new_attempts,
               locked_until = COALESCE(v_locked_until, app.users.locked_until)
         WHERE app.users.id = p_user_id;
    END IF;

    SELECT * INTO r FROM app.users WHERE app.users.id = p_user_id;

    RETURN QUERY
        SELECT r.id,
               r.username::text,
               r.email::text,
               r.password_hash::text,
               r.full_name::text,
               r."position"::text,
               r.org_id,
               r.status::text,
               r.mfa_enabled,
               r.mfa_secret_enc::text,
               r.must_change_password,
               r.failed_login_count,
               r.locked_until,
               r.last_login_at,
               r.password_changed_at,
               r.version,
               (r.mfa_enabled OR EXISTS (
                   SELECT 1
                   FROM app.user_roles ur
                   JOIN app.roles r2 ON r2.id = ur.role_id
                   WHERE ur.user_id = r.id
                     AND r2.requires_mfa
               ))::boolean AS mfa_required;
END;
$$;
SQL
        );

        $this->execute(<<<SQL
CREATE OR REPLACE FUNCTION app.fn_user_profile(p_user_id bigint)
RETURNS TABLE (
    id bigint, username text, full_name text, email text, org_id bigint,
    status text, must_change_password boolean, mfa_enabled boolean,
    mfa_secret_enc text, version integer
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = app, public
AS $$
DECLARE
    r app.users%ROWTYPE;
BEGIN
    SELECT * INTO r FROM app.users
    WHERE app.users.id = p_user_id AND app.users.deleted_at IS NULL;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    RETURN QUERY
        SELECT r.id,
               r.username::text,
               r.full_name::text,
               r.email::text,
               r.org_id,
               r.status::text,
               r.must_change_password,
               r.mfa_enabled,
               r.mfa_secret_enc::text,
               r.version;
END;
$$;
SQL
        );

        $this->execute(<<<SQL
REVOKE ALL ON FUNCTION app.fn_login_lookup(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION app.fn_login_record(bigint, boolean, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION app.fn_user_profile(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app.fn_login_lookup(text) TO app_rw;
GRANT EXECUTE ON FUNCTION app.fn_login_record(bigint, boolean, text) TO app_rw;
GRANT EXECUTE ON FUNCTION app.fn_user_profile(bigint) TO app_rw;
SQL
        );
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS app.fn_login_lookup(text);');
        $this->execute('DROP FUNCTION IF EXISTS app.fn_login_record(bigint, boolean, text);');
        $this->execute('DROP FUNCTION IF EXISTS app.fn_user_profile(bigint);');
    }
}