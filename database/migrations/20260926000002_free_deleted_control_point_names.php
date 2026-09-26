<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Make the control-point name uniqueness rule ignore soft-deleted rows.
 *
 * Problem: 20260920000007 created a plain unique index on
 * (point_name, native_crs_id) on a table that also carries a nullable
 * deleted_at soft-delete column. A plain unique index applies to every row,
 * soft-deleted or not, so deleting a control point permanently reserves its
 * name. Re-creating that point then fails with HTTP 409 "already exists for
 * this CRS" even though the conflicting row is invisible to every reader:
 * list() filters on deleted_at IS NULL, and get()/update() 404 because they do
 * too, so there is no supported way to free the name again.
 *
 * This is not theoretical for tie-point work. Surveyors delete and re-enter
 * points routinely, so the first re-entry after a deletion is guaranteed to
 * hit it. It also blocks legitimate data corrections: the 2026-09-26 BLLM
 * import of 95 tie points could not re-create a point name until the retired
 * row was renamed directly in the database, bypassing the API.
 *
 * Fix: rebuild the index as partial on deleted_at IS NULL. Soft-deleted rows
 * keep their history (and the audit log still records the deletion) but no
 * longer block a new row from taking the name.
 *
 * Safety: this only relaxes the constraint. Verified before applying that no
 * (point_name, native_crs_id) pair has more than one live row, so no existing
 * data can violate the new index. Live-row uniqueness - the actual intent - is
 * unchanged.
 */
final class MakeControlPointNameUniqueIgnoreDeleted extends AbstractMigration
{
    public function up(): void
    {
        // Defensive pre-check: a partial index cannot be built if live
        // duplicates already exist, and the failure would surface as a less
        // obvious error during CREATE UNIQUE INDEX.
        $duplicates = $this->fetchAll(
            "SELECT point_name, native_crs_id, count(*) AS n
               FROM app.survey_control_points
              WHERE deleted_at IS NULL
              GROUP BY point_name, native_crs_id
             HAVING count(*) > 1"
        );

        if ($duplicates !== []) {
            $names = array_map(
                static fn (array $row): string => $row['point_name'] . ' (crs ' . $row['native_crs_id'] . ')',
                $duplicates
            );
            throw new RuntimeException(
                'Cannot make control-point name uniqueness partial: live duplicates exist: ' . implode(', ', $names)
            );
        }

        $this->execute('DROP INDEX IF EXISTS app.survey_control_points_point_name_native_crs_id');
        $this->execute(
            'CREATE UNIQUE INDEX survey_control_points_point_name_native_crs_id
                 ON app.survey_control_points (point_name, native_crs_id)
               WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS app.survey_control_points_point_name_native_crs_id');
        $this->execute(
            'CREATE UNIQUE INDEX survey_control_points_point_name_native_crs_id
                 ON app.survey_control_points (point_name, native_crs_id)'
        );
    }
}
