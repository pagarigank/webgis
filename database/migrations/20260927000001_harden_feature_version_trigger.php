<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Parcel-module audit (2026-09-27) — harden the GIS feature-version trigger.
 *
 * audit.fn_write_feature_version() inserted into audit.gis_feature_versions on
 * every INSERT ATTEMPT on app.gis_features, including attempts that
 * `ON CONFLICT (id) DO NOTHING` immediately discarded. When an orphan version
 * row exists for a (feature_id, version) pair — the fixture feature is
 * hard-deleted by test teardowns while its audit rows survive — every later
 * `INSERT ... ON CONFLICT DO NOTHING` attempt still fired the trigger, whose
 * own INSERT then violated the (feature_id, version) unique constraint and
 * aborted the whole Phinx seed run. Result: the FixtureSeeder rolled back all
 * of its work and every SAMPLE_ parcel, title, layer-permission and demo
 * password silently disappeared from the shared database.
 *
 * The trigger now checks for an existing identical version row and no-ops
 * instead of raising, which keeps audit history append-only while making
 * re-seeding idempotent. down() restores the original function body verbatim.
 */
final class HardenFeatureVersionTrigger extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE OR REPLACE FUNCTION audit.fn_write_feature_version()
            RETURNS trigger AS $$
            DECLARE
                op varchar(10);
                existing int;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    op := 'INSERT';
                ELSIF TG_OP = 'UPDATE' THEN
                    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                        op := 'DELETE';
                    ELSE
                        op := 'UPDATE';
                    END IF;
                ELSIF TG_OP = 'DELETE' THEN
                    op := 'DELETE';
                    -- Handle hard deletes as well if they occur
                    INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                    VALUES (OLD.id, OLD.layer_id, OLD.version, op, OLD.geom, OLD.attributes, OLD.status, OLD.updated_by, now())
                    ON CONFLICT (feature_id, version) DO NOTHING;
                    RETURN OLD;
                END IF;

                -- An INSERT that ON CONFLICT (id) DO NOTHING is about to discard
                -- still fires this trigger; an orphan row for the same
                -- (feature_id, version) — e.g. the feature was hard-deleted while
                -- its audit rows survived — must not abort the statement.
                -- Skip when the identical version row already exists.
                SELECT 1 INTO existing
                  FROM audit.gis_feature_versions
                 WHERE feature_id = NEW.id AND version = NEW.version;
                IF existing IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                VALUES (NEW.id, NEW.layer_id, NEW.version, op, NEW.geom, NEW.attributes, NEW.status, NEW.updated_by, NEW.updated_at);

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }

    public function down(): void
    {
        // Restore the original function body verbatim (migration
        // 20260920000006_create_gis_core_tables.php).
        $this->execute("
            CREATE OR REPLACE FUNCTION audit.fn_write_feature_version()
            RETURNS trigger AS $$
            DECLARE
                op varchar(10);
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    op := 'INSERT';
                ELSIF TG_OP = 'UPDATE' THEN
                    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                        op := 'DELETE';
                    ELSE
                        op := 'UPDATE';
                    END IF;
                ELSIF TG_OP = 'DELETE' THEN
                    op := 'DELETE';
                    -- Handle hard deletes as well if they occur
                    INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                    VALUES (OLD.id, OLD.layer_id, OLD.version, op, OLD.geom, OLD.attributes, OLD.status, OLD.updated_by, now());
                    RETURN OLD;
                END IF;

                INSERT INTO audit.gis_feature_versions(feature_id, layer_id, version, operation, geom, attributes, status, changed_by, changed_at)
                VALUES (NEW.id, NEW.layer_id, NEW.version, op, NEW.geom, NEW.attributes, NEW.status, NEW.updated_by, NEW.updated_at);

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }
}
