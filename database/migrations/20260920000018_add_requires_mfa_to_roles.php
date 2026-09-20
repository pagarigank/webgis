<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-036 — MFA policy column on roles.
 *
 * specification.md FR-006 and architecture.md §6.1 mandate TOTP MFA for roles
 * flagged `requires_mfa` (administrators, approvers). The column was documented
 * in database.md but never migrated. This closes the gap; fn_login_lookup /
 * fn_login_record (20260920000017) already read app.roles.requires_mfa to
 * determine mfa_required.
 *
 * SYS_ADMIN is back-filled as the first MFA-mandatory role.
 */

final class AddRequiresMfaToRoles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE app.roles ADD COLUMN IF NOT EXISTS requires_mfa boolean NOT NULL DEFAULT false"
        );
        $this->execute(
            "UPDATE app.roles SET requires_mfa = true WHERE lower(code) IN ('sys_admin')"
        );
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE app.roles DROP COLUMN IF EXISTS requires_mfa");
    }
}