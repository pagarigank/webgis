<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 15 — TASK-111/112/114/115 lineage model (architecture.md §18.2/§18.4).
 *
 * app.parcel_relationships was already created by migration
 * 20260920000008_create_parcel_tables; this migration makes the Phase 15
 * additions idempotent so it applies cleanly both to databases that ran 0008
 * (the normal case — the table pre-exists) and to fresh databases.
 *
 * Two things ship here:
 *
 *  1. The VR-45 cycle guard (a parcel can never become its own ancestor) as
 *     a BEFORE INSERT trigger — the graph must stay acyclic regardless of
 *     which client writes an edge, not only through application code.
 *  2. app.parcel_operations.idempotency_key (unique when present) so a
 *     replayed split/consolidation commit returns the original operation
 *     instead of writing twice (§18.3/§18.4).
 *
 * down() removes what this migration adds; the table itself belongs to 0008.
 */
final class CreateParcelRelationships extends AbstractMigration
{
    public function up(): void
    {
        // Defensive: on any database that ran 0008 the table pre-exists and
        // this branch is skipped.
        if (!$this->hasTable('app.parcel_relationships')) {
            $this->execute("
                CREATE TABLE app.parcel_relationships (
                    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    parent_parcel_id uuid NOT NULL REFERENCES app.parcels(id),
                    child_parcel_id uuid NOT NULL REFERENCES app.parcels(id),
                    relationship_type varchar(16) NOT NULL,
                    operation_id bigint NULL,
                    effective_date date NULL,
                    reason text NULL,
                    source_document_id uuid NULL,
                    created_by bigint NULL,
                    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT ck_prel_type CHECK (relationship_type IN
                        ('SUBDIVISION','CONSOLIDATION','MERGER','REPLACEMENT','CORRECTION','ADJUSTMENT')),
                    CONSTRAINT uq_prel_edge UNIQUE (parent_parcel_id, child_parcel_id, relationship_type, operation_id)
                )
            ");
        }

        // Traversal indexes for the recursive CTE (ancestors walk parents,
        // descendants walk children).
        $this->execute("CREATE INDEX IF NOT EXISTS idx_prel_parent ON app.parcel_relationships (parent_parcel_id)");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_prel_child ON app.parcel_relationships (child_parcel_id)");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_prel_operation ON app.parcel_relationships (operation_id)");

        // VR-45 — reject an edge that would make the child its own ancestor:
        // walk the parent's ancestor chain (bounded by the same depth cap the
        // read API uses) and fail if the child appears in it.
        $this->execute("
            CREATE OR REPLACE FUNCTION app.fn_lineage_cycle_guard() RETURNS trigger AS $$
            DECLARE
                v_depth integer := 0;
                v_ancestor uuid;
                v_cursor uuid;
            BEGIN
                IF NEW.parent_parcel_id = NEW.child_parcel_id THEN
                    RAISE EXCEPTION 'VR-45: parcel % cannot be its own ancestor', NEW.child_parcel_id;
                END IF;
                v_cursor := NEW.parent_parcel_id;
                WHILE v_cursor IS NOT NULL AND v_depth < 50 LOOP
                    SELECT r.parent_parcel_id INTO v_ancestor
                      FROM app.parcel_relationships r
                     WHERE r.child_parcel_id = v_cursor
                     ORDER BY r.id
                     LIMIT 1;
                    IF v_ancestor IS NOT NULL AND v_ancestor = NEW.child_parcel_id THEN
                        RAISE EXCEPTION 'VR-45: edge % -> % would create a lineage cycle',
                            NEW.parent_parcel_id, NEW.child_parcel_id;
                    END IF;
                    v_cursor := v_ancestor;
                    v_depth := v_depth + 1;
                END LOOP;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ");

        $this->execute("DROP TRIGGER IF EXISTS trg_lineage_cycle_guard ON app.parcel_relationships");
        $this->execute("
            CREATE TRIGGER trg_lineage_cycle_guard
                BEFORE INSERT ON app.parcel_relationships
                FOR EACH ROW EXECUTE FUNCTION app.fn_lineage_cycle_guard()
        ");

        // Idempotent operation replays (§18.3/§18.4): the split/consolidation
        // commit stores the caller's Idempotency-Key here; the unique index
        // makes a double write impossible at the database level.
        if ($this->fetchRow("SELECT 1 FROM information_schema.columns WHERE table_schema = 'app' AND table_name = 'parcel_operations' AND column_name = 'idempotency_key'") === false) {
            $this->execute("ALTER TABLE app.parcel_operations ADD COLUMN idempotency_key varchar(100) NULL");
        }
        $this->execute("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_poperations_idempotency
                ON app.parcel_operations (idempotency_key)
                WHERE idempotency_key IS NOT NULL
        ");
    }

    public function down(): void
    {
        $this->execute("DROP INDEX IF EXISTS app.uq_poperations_idempotency");
        if ($this->fetchRow("SELECT 1 FROM information_schema.columns WHERE table_schema = 'app' AND table_name = 'parcel_operations' AND column_name = 'idempotency_key'") !== false) {
            $this->execute("ALTER TABLE app.parcel_operations DROP COLUMN idempotency_key");
        }
        $this->execute("DROP TRIGGER IF EXISTS trg_lineage_cycle_guard ON app.parcel_relationships");
        $this->execute("DROP FUNCTION IF EXISTS app.fn_lineage_cycle_guard()");
        // The table belongs to migration 0008 — not dropped here. The
        // defensive CREATE branch above is therefore not reversible; any
        // database without 0008 has no lineage model anyway.
    }
}
