<?php
declare(strict_types=1);

namespace App\Parcels\Application;

use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\Parcels\Domain\ConsolidationValidator;
use PDO;

/**
 * TASK-114 — Consolidation service (architecture.md §18.4, FR-220…FR-225).
 *
 * Dry run and commit share one derive→validate code path. The commit locks
 * ALL parents with SELECT … FOR UPDATE ordered by id (deadlock avoidance),
 * writes the new DRAFT parcel from ST_Union, CONSOLIDATION edges from every
 * parent, versions for the new parcel and each parent, flips every parent to
 * SUPERSEDED, and records the operation — inside the ambient transaction.
 * Any failure throws ApiError so the middleware rolls back everything.
 *
 * Rollback-test seam: failAfterNewParcel() is protected so a test subclass
 * can inject a failure after the new parcel INSERT and prove no parent was
 * superseded, no edge written, no operation recorded.
 */
class ConsolidationService
{
    /** Parents must not already be historical (§18.4). */
    private const BLOCKED_PARENT_STATUSES = ['SUPERSEDED', 'ARCHIVED'];

    private ?array $ifMatchVersions = null;

    public function __construct(
        private PDO $pdo,
        private ConsolidationValidator $validator,
        private AuditWriter $audit,
    ) {
    }

    /** @param array<int,int> $versions parent id => version the caller saw */
    public function setIfMatchVersions(array $versions): void
    {
        $this->ifMatchVersions = $versions === [] ? null : $versions;
    }

    /**
     * @param int[] $parentParcelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed> api.md §8.3 data payload
     */
    public function consolidate(array $parentParcelIds, int $userId, array $input, bool $dryRun, string $requestId, string $idempotencyKey = ''): array
    {
        $parentParcelIds = array_values(array_unique(array_map('strval', $parentParcelIds)));

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiError('VALIDATION_FAILED', 'A reason is required for a consolidation (FR-137).', 400);
        }

        // Existence first (404 — never leak scope misses), ordered by id.
        // Runs BEFORE the ≥2 rule so an unknown id is 404 even when fewer
        // than two distinct parents were supplied.
        $parents = $this->readParents($parentParcelIds);

        if (count($parentParcelIds) < 2) {
            throw new ApiError('CONSOLIDATION_INVALID', 'Consolidation requires at least two distinct parent parcels.', 422, [
                'failures' => [['rule' => 'VR-40', 'message' => 'Consolidation requires at least two distinct parent parcels.']],
            ]);
        }

        $allowMultipart = (bool) ($input['allow_multipart'] ?? false);
        $parentGeoms = array_map(fn (array $p): string => (string) $p['geom_gj'], $parents);

        // VALIDATE — identical path for dry run and commit.
        $validation = $this->validator->validateConsolidation($parentGeoms, $allowMultipart);
        if (!$validation['passed']) {
            throw new ApiError('CONSOLIDATION_INVALID', 'The consolidation failed validation; nothing was written.', 422, [
                'failures' => array_values(array_filter($validation['checks'], fn (array $c): bool => $c['status'] === 'fail')),
                'warnings' => $validation['warnings'],
            ]);
        }

        if ($dryRun) {
            return $this->payload(null, true, $parents, $validation, $input, null);
        }

        return $this->commit($parents, $userId, $validation, $input, $reason, $requestId, $idempotencyKey, $allowMultipart);
    }

    // ------------------------------------------------------------------

    /**
     * @param int[] $ids
     * @return list<array<string,mixed>> ordered by id, each with geom_gj
     */
    private function readParents(array $ids): array
    {
        $placeholders = implode(', ', array_map(fn (int $i): string => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, parcel_code, lot_number, version, status, psgc_barangay, psgc_municipality, psgc_province, org_id, geometry_source,
                    COALESCE(ST_AsGeoJSON(geom), '') AS geom_gj
             FROM app.parcels WHERE id IN ($placeholders) AND deleted_at IS NULL ORDER BY id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new ApiError('NOT_FOUND', 'One or more parent parcels were not found.', 404);
        }
        // Preserve the caller's input order — parents[0] seeds the new code.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            $ordered[] = $byId[(string) $id];
        }
        $rows = $ordered;
        foreach ($rows as &$row) {
            if (trim((string) $row['geom_gj']) === '') {
                throw new ApiError('CONSOLIDATION_INVALID', sprintf('Parcel %s has no geometry.', $row['parcel_code']), 422, [
                    'failures' => [['rule' => 'VR-40', 'message' => sprintf('Parcel %s has no geometry.', $row['parcel_code'])]],
                ]);
            }
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $parents */
    private function commit(
        array $parents,
        int $userId,
        array $validation,
        array $input,
        string $reason,
        string $requestId,
        string $idempotencyKey,
        bool $allowMultipart,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey !== '') {
            $existing = $this->findOperationByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->payload($existing, false, $parents, $validation, $input, null);
            }
        }

        // Lock ALL parents, ordered by id (§18.4 deadlock avoidance).
        $locked = $this->lockParents(array_column($parents, 'id'));
        $this->assertParentsConsolidatable($locked);
        $this->assertScopeCoversAll($locked);

        $newParcelId = $this->insertNewParcel($locked, $validation, $input, $userId);
        $this->failAfterNewParcel();

        $operationId = $this->insertOperation($locked, $input, $validation, $newParcelId, $userId, $reason, $requestId, $idempotencyKey, $allowMultipart);

        foreach ($locked as $parent) {
            $this->insertRelationship((string) $parent['id'], $newParcelId, $operationId, $input, $userId);
        }

        $newParcelCode = $this->parcelCode($newParcelId);
        $this->writeNewParcelVersion($newParcelId, $locked, $reason, $requestId);
        foreach ($locked as $parent) {
            $this->supersedeParent($parent, $operationId, $reason, $requestId);
        }

        $this->audit->writeFromSession('CONSOLIDATION', 'app.parcel_operations', (string) $operationId, null, [
            'parents' => array_map(static fn (array $p): string => (string) $p['id'], $locked),
            'new_parcel' => $newParcelId,
        ], $requestId, $reason);

        return $this->payload($operationId, false, $parents, $validation, $input, [
            'new_parcel_id' => $newParcelId,
            'new_parcel_code' => $newParcelCode,
        ]);
    }

    /** @param string[] $ids @return list<array<string,mixed>> */
    private function lockParents(array $ids): array
    {
        $placeholders = implode(', ', array_map(fn (int $i): string => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }
        // ORDER BY id under FOR UPDATE — the §18.4 deadlock rule.
        $stmt = $this->pdo->prepare(
            "SELECT id, parcel_code, lot_number, version, status, psgc_barangay, psgc_municipality, psgc_province, org_id, geometry_source, geom
             FROM app.parcels WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new ApiError('NOT_FOUND', 'One or more parent parcels were not found.', 404);
        }
        // Rows are locked in deterministic id order (deadlock rule), but are
        // returned in the caller's input order — parents[0] seeds the new code.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            $ordered[] = $byId[(string) $id];
        }
        return $ordered;
    }

    /** @param list<array<string,mixed>> $parents */
    private function assertParentsConsolidatable(array $parents): void
    {
        $failures = [];
        foreach ($parents as $parent) {
            if (in_array($parent['status'], self::BLOCKED_PARENT_STATUSES, true)) {
                $failures[] = sprintf('Parcel %s is %s.', $parent['parcel_code'], $parent['status']);
            }
            $expected = $this->ifMatchVersions[$parent['id']] ?? null;
            if ($expected !== null && (int) $expected !== (int) $parent['version']) {
                throw new ApiError('VERSION_CONFLICT', sprintf('Parcel %s was modified by another user.', $parent['parcel_code']), 409, [
                    'parcel_id' => (string) $parent['id'],
                    'current_version' => (int) $parent['version'],
                ]);
            }
        }
        if ($failures !== []) {
            throw new ApiError('CONSOLIDATION_INVALID', 'Parents are not eligible for consolidation.', 409, [
                'failures' => array_map(fn (string $m): array => ['rule' => 'VR-40', 'message' => $m], $failures),
            ]);
        }
    }

    /** §18.4: scope must cover EVERY parent. */
    private function assertScopeCoversAll(array $parents): void
    {
        foreach ($parents as $parent) {
            $stmt = $this->pdo->prepare(
                'SELECT app.fn_user_can_edit(NULLIF(current_setting(\'app.user_id\', true), \'\')::bigint, p.psgc_barangay, p.org_id)
                 FROM app.parcels p WHERE p.id = :id'
            );
            $stmt->execute([':id' => $parent['id']]);
            $allowed = $stmt->fetchColumn();
            if ($allowed === false || (bool) $allowed !== true) {
                throw new ApiError('PERMISSION_DENIED', 'Your data scope does not cover every parent parcel.', 403);
            }
        }
    }

    /** @param list<array<string,mixed>> $parents */
    private function insertNewParcel(array $parents, array $validation, array $input, int $userId): string
    {
        $first = $parents[0];
        $new = is_array($input['new_parcel'] ?? null) ? $input['new_parcel'] : [];

        // Inherit provenance from the parents when uniform; mixed provenances
        // fall back to MANUAL_DRAWING (the record is a GIS operation product).
        $provenances = array_unique(array_column($parents, 'geometry_source'));
        $geometrySource = count($provenances) === 1 ? (string) reset($provenances) : 'MANUAL_DRAWING';

        $code = $this->generateParcelCode((string) $first['parcel_code']);

        $stmt = $this->pdo->prepare('
            WITH parents AS (
                SELECT geom FROM app.parcels
                 WHERE id = ANY(:ids::uuid[])
            )
            INSERT INTO app.parcels (
                id, parcel_code, lot_number, status, geometry_source,
                psgc_barangay, psgc_municipality, psgc_province, org_id,
                source_document_id, survey_plan_id, remarks, version, created_by, updated_by, geom
            ) VALUES (
                gen_random_uuid(), :code, :lot, \'DRAFT\', :gsrc,
                :brgy, :mun, :prov, :org,
                :doc, :plan, :remarks, 1, :uid, :uid,
                (SELECT ST_Multi(ST_UnaryUnion(ST_Collect(geom))) FROM parents)
            )
            RETURNING id
        ');
        $stmt->execute([
            ':ids' => '{' . implode(',', array_map(static fn (array $p): string => (string) $p['id'], $parents)) . '}',
            ':code' => $code,
            ':lot' => $new['lot_number'] ?? null,
            ':gsrc' => $geometrySource,
            ':brgy' => $first['psgc_barangay'],
            ':mun' => $first['psgc_municipality'],
            ':prov' => $first['psgc_province'],
            ':org' => $first['org_id'],
            ':doc' => $input['source_document_id'] ?? null,
            ':plan' => $new['survey_plan_id'] ?? null,
            ':remarks' => $new['remarks'] ?? null,
            ':uid' => $userId,
        ]);
        return (string) $stmt->fetchColumn();
    }

    /** Unique child code derived from the first parent (…-CONS, …-CONS2, …). */
    private function generateParcelCode(string $base): string
    {
        $candidate = $base . '-CONS';
        $suffix = 2;
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.parcels WHERE parcel_code = :code LIMIT 1');
        while (true) {
            $stmt->execute([':code' => $candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
            $candidate = $base . '-CONS' . $suffix++;
        }
    }

    private function parcelCode(string $parcelId): string
    {
        $stmt = $this->pdo->prepare('SELECT parcel_code FROM app.parcels WHERE id = :id');
        $stmt->execute([':id' => $parcelId]);
        return (string) $stmt->fetchColumn();
    }

    /** Rollback seam — overridden by test subclasses. */
    protected function failAfterNewParcel(): void
    {
    }

    /** @param list<array<string,mixed>> $parents */
    private function insertOperation(
        array $parents,
        array $input,
        array $validation,
        string $newParcelId,
        int $userId,
        string $reason,
        string $requestId,
        string $idempotencyKey,
        bool $allowMultipart,
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcel_operations (
                operation_type, method, status, inputs, parcel_snapshot, results,
                area_reconciliation, validation_result, performed_by, reason,
                source_document_id, idempotency_key, request_id
            ) VALUES (
                'CONSOLIDATION', :method, 'COMMITTED', :inputs::jsonb, :snapshot::jsonb, :results::jsonb,
                :recon::jsonb, :validation::jsonb, :uid, :reason, :doc, :idem, :rid
            )
            RETURNING id
        ");
        $stmt->execute([
            ':method' => $allowMultipart ? 'IMPORTED_GEOMETRY' : 'IMPORTED_GEOMETRY',
            ':inputs' => json_encode($input, JSON_THROW_ON_ERROR),
            ':snapshot' => json_encode(array_map(static fn (array $p): array => [
                'id' => $p['id'], 'parcel_code' => $p['parcel_code'], 'version' => $p['version'], 'status' => $p['status'],
            ], $parents), JSON_THROW_ON_ERROR),
            ':results' => json_encode(['new_parcel_id' => $newParcelId, 'union_area_sqm' => $validation['union_area_sqm']], JSON_THROW_ON_ERROR),
            ':recon' => json_encode([
                'parents_sum_sqm' => array_sum(array_column($validation['parents'], 'area_sqm')),
                'union_sqm' => $validation['union_area_sqm'],
                'difference_sqm' => round(array_sum(array_column($validation['parents'], 'area_sqm')) - (float) $validation['union_area_sqm'], 4),
            ], JSON_THROW_ON_ERROR),
            ':validation' => json_encode($validation, JSON_THROW_ON_ERROR),
            ':uid' => $userId,
            ':reason' => $reason,
            ':doc' => $input['source_document_id'] ?? null,
            ':idem' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ':rid' => $requestId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function insertRelationship(string $parentId, string $childId, int $operationId, array $input, int $userId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO app.parcel_relationships (
                parent_parcel_id, child_parcel_id, relationship_type,
                operation_id, effective_date, reason, source_document_id, created_by
            ) VALUES (:p, :c, \'CONSOLIDATION\', :op, :eff, :reason, :doc, :uid)
        ');
        $stmt->execute([
            ':p' => $parentId,
            ':c' => $childId,
            ':op' => $operationId,
            ':eff' => $input['effective_date'] ?? null,
            ':reason' => $input['reason'] ?? null,
            ':doc' => $input['source_document_id'] ?? null,
            ':uid' => $userId,
        ]);
    }

    /** @param list<array<string,mixed>> $parents */
    private function writeNewParcelVersion(string $newParcelId, array $parents, string $reason, string $requestId): void
    {
        $codes = implode(', ', array_column($parents, 'parcel_code'));
        $stmt = $this->pdo->prepare('
            INSERT INTO audit.parcel_versions (
                parcel_id, version, snapshot, status, geometry_source,
                change_summary, change_reason, changed_by, request_id
            ) SELECT id, 1, row_to_json(p), status, geometry_source,
                   :summary, :reason, NULLIF(current_setting(\'app.user_id\', true), \'\')::bigint, :rid
              FROM app.parcels p WHERE p.id = :id
        ');
        $stmt->execute([
            ':id' => $newParcelId,
            ':summary' => 'Created by CONSOLIDATION of ' . $codes,
            ':reason' => $reason,
            ':rid' => $requestId,
        ]);
    }

    /** @param array<string,mixed> $parent */
    private function supersedeParent(array $parent, int $operationId, string $reason, string $requestId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO audit.parcel_versions (
                parcel_id, version, snapshot, status, geometry_source,
                change_summary, change_reason, changed_by, request_id
            ) SELECT id, version, row_to_json(p), status, geometry_source,
                   :summary, :reason, NULLIF(current_setting(\'app.user_id\', true), \'\')::bigint, :rid
              FROM app.parcels p WHERE p.id = :id
        ');
        $stmt->execute([
            ':id' => $parent['id'],
            ':summary' => 'State before CONSOLIDATION; parcel superseded by operation ' . $operationId,
            ':reason' => $reason,
            ':rid' => $requestId,
        ]);

        $upd = $this->pdo->prepare("
            UPDATE app.parcels
               SET status = 'SUPERSEDED', version = version + 1,
                   superseded_by_operation_id = :op, updated_by = NULLIF(current_setting('app.user_id', true), '')::bigint,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
        ");
        $upd->execute([':op' => $operationId, ':id' => $parent['id']]);
    }

    private function findOperationByIdempotencyKey(string $key): ?int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM app.parcel_operations WHERE idempotency_key = :key LIMIT 1');
            $stmt->execute([':key' => $key]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $parents
     * @return array<string,mixed>
     */
    private function payload(?int $operationId, bool $dryRun, array $parents, array $validation, array $input, ?array $committed): array
    {
        $unionGeom = null;
        if ($dryRun) {
            // Rebuild the union read-only for the preview payload.
            $ids = array_map(static fn (array $p): string => (string) $p['id'], $parents);
            $placeholders = implode(', ', array_map(fn (int $i): string => ':id' . $i, array_keys($ids)));
            $params = [];
            foreach ($ids as $i => $id) {
                $params[':id' . $i] = $id;
            }
            $stmt = $this->pdo->prepare("SELECT COALESCE(ST_AsGeoJSON(ST_UnaryUnion(ST_Collect(geom))), '') FROM app.parcels WHERE id IN ($placeholders)");
            $stmt->execute($params);
            $gj = $stmt->fetchColumn();
            $unionGeom = is_string($gj) && $gj !== '' ? json_decode($gj, true) : null;
        }

        return [
            'operation_id' => $operationId,
            'dry_run' => $dryRun,
            'result' => [
                'parcel_id' => $committed['new_parcel_id'] ?? null,
                'parcel_code' => $committed['new_parcel_code'] ?? null,
                'area_sqm' => $validation['union_area_sqm'],
                'geometry' => $unionGeom,
            ],
            'area_reconciliation' => [
                'parents_sum_sqm' => round(array_sum(array_column($validation['parents'], 'area_sqm')), 4),
                'union_sqm' => $validation['union_area_sqm'],
                'difference_sqm' => round(array_sum(array_column($validation['parents'], 'area_sqm')) - (float) $validation['union_area_sqm'], 4),
            ],
            'validation' => [
                'passed' => $validation['passed'],
                'checks' => $validation['checks'],
                'warnings' => $validation['warnings'],
            ],
            'parents_after' => array_map(static fn (array $p): array => [
                'id' => (string) $p['id'],
                'parcel_code' => $p['parcel_code'],
                'status' => $dryRun ? 'SUPERSEDED' : 'SUPERSEDED',
            ], $parents),
        ];
    }
}
