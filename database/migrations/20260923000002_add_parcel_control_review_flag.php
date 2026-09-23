<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-074 — "flagged for review" columns on app.parcels (FR-081).
 *
 * Editing a control point's coordinates affects every parcel whose current
 * computation used that point. Those parcels must be flagged for review so an
 * operator re-checks them; past computations are left byte-identical because
 * they hold their own snapshots. Only the flag columns change — version is NOT
 * bumped so an in-flight optimistically-locked parcel write never conflicts
 * with the flagging.
 */
final class AddParcelControlReviewFlag extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE app.parcels
                ADD COLUMN control_review_pending boolean NOT NULL DEFAULT false,
                ADD COLUMN control_review_since timestamp NULL
        ");

        // Partial index: pending parcels are queried by flag status and the
        // dependents endpoint joins on the flagged set.
        $this->execute("
            CREATE INDEX idx_parcels_control_review
                ON app.parcels (control_review_pending)
                WHERE control_review_pending = true
        ");
    }

    public function down(): void
    {
        $this->execute("DROP INDEX IF EXISTS app.idx_parcels_control_review");
        $this->execute("
            ALTER TABLE app.parcels
                DROP COLUMN control_review_since,
                DROP COLUMN control_review_pending
        ");
    }
}