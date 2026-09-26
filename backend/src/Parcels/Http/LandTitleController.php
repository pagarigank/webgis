<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\Audit\AuditWriter;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Land Title & Parcel-Title API.
 *
 *  GET    /parcels/{id}/titles               list titles linked to a parcel
 *  POST   /parcels/{id}/titles               create + link a new title
 *  GET    /titles/{title_id}                 title detail (with parties)
 *  PUT    /titles/{title_id}                 update title metadata
 *  POST   /titles/{title_id}/parties         add a party to a title
 *  DELETE /titles/{title_id}/parties/{pid}   remove a party from a title
 *  POST   /parcels/{id}/titles/{title_id}    link an existing title to a parcel
 *  DELETE /parcels/{id}/titles/{title_id}    unlink a title from a parcel
 */
final class LandTitleController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditWriter $audit,
    ) {}

    // ── List titles linked to a parcel ───────────────────────────────────────

    public function listForParcel(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $parcelId = $this->parseUuid($args, 'id');

        $stmt = $this->pdo->prepare(
            'SELECT lt.*, pt.relationship
               FROM app.land_titles lt
               JOIN app.parcel_titles pt ON lt.id = pt.title_id
              WHERE pt.parcel_id = :pid
                AND lt.deleted_at IS NULL
              ORDER BY lt.title_date DESC NULLS LAST, lt.id DESC'
        );
        $stmt->execute([':pid' => $parcelId]);
        $titles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach parties for each title
        foreach ($titles as &$title) {
            $title['parties'] = $this->fetchParties((int) $title['id']);
        }
        unset($title);

        return Envelope::success($response, $titles, 200);
    }

    // ── Create a new title and link it to a parcel ───────────────────────────

    public function createForParcel(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $parcelId = $this->parseUuid($args, 'id');

        $body = JsonBodyParser::parse($request);

        $titleNumber  = trim((string) ($body['title_number'] ?? ''));
        $titleType    = trim((string) ($body['title_type'] ?? 'OCT'));
        $titleDate    = ($body['title_date'] ?? null) ?: null;
        $registry     = trim((string) ($body['registry_office'] ?? ''));
        $lotNumber    = trim((string) ($body['lot_number'] ?? ''));
        $areaSqm      = isset($body['area_sqm']) ? (float) $body['area_sqm'] : null;
        $location     = trim((string) ($body['location_description'] ?? ''));
        $psgcBrgy     = trim((string) ($body['psgc_barangay'] ?? ''));
        $remarks      = trim((string) ($body['remarks'] ?? ''));
        $relationship = trim((string) ($body['relationship'] ?? 'COVERS'));

        if ($titleNumber === '') {
            throw new ApiError('VALIDATION_FAILED', 'title_number is required.', 422);
        }

        $this->pdo->beginTransaction();
        try {
            // Insert land_title
            $ins = $this->pdo->prepare(
                'INSERT INTO app.land_titles
                   (title_number, title_type, title_date, registry_office,
                    lot_number, area_sqm, location_description, psgc_barangay, remarks,
                    status, created_by, updated_by)
                 VALUES
                   (:tn, :tt, :td, :reg, :lot, :area, :loc, :psgc, :rem,
                    \'ACTIVE\', :uid, :uid)
                 RETURNING id'
            );
            $ins->execute([
                ':tn'   => $titleNumber,
                ':tt'   => $titleType,
                ':td'   => $titleDate,
                ':reg'  => $registry ?: null,
                ':lot'  => $lotNumber ?: null,
                ':area' => $areaSqm,
                ':loc'  => $location ?: null,
                ':psgc' => $psgcBrgy ?: null,
                ':rem'  => $remarks ?: null,
                ':uid'  => $uid,
            ]);
            $titleId = (int) $ins->fetchColumn();

            // Link to parcel
            $link = $this->pdo->prepare(
                'INSERT INTO app.parcel_titles (parcel_id, title_id, relationship)
                 VALUES (:pid, :tid, :rel)
                 ON CONFLICT DO NOTHING'
            );
            $link->execute([':pid' => $parcelId, ':tid' => $titleId, ':rel' => $relationship]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            // Duplicate title_number + registry_office
            if (str_contains($e->getMessage(), 'land_titles_title_number_registry_office')) {
                throw new ApiError('CONFLICT', 'A title with this number already exists in the registry office.', 409);
            }
            throw $e;
        }

        $this->audit->writeFromSession('INSERT', 'app.land_titles', (string) $titleId, null,
            ['title_number' => $titleNumber, 'parcel_id' => $parcelId], null, 'Land title created and linked');

        $title = $this->fetchTitle($titleId);
        return Envelope::success($response, $title, 201);
    }

    // ── Get one title with parties ────────────────────────────────────────────

    public function getTitle(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $titleId = (int) ($args['title_id'] ?? 0);
        $title   = $this->fetchTitle($titleId);
        return Envelope::success($response, $title, 200);
    }

    // ── Update title metadata ─────────────────────────────────────────────────

    public function updateTitle(Request $request, Response $response, array $args): Response
    {
        $uid     = $this->requireUser($request);
        $this->scopeSession($uid);
        $titleId = (int) ($args['title_id'] ?? 0);
        $body    = JsonBodyParser::parse($request);

        $allowed = ['title_type', 'title_date', 'registry_office', 'lot_number',
                    'area_sqm', 'location_description', 'psgc_barangay', 'remarks', 'status'];
        $sets = []; $params = [':uid' => $uid, ':id' => $titleId];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $body)) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $body[$col];
            }
        }
        if (empty($sets)) {
            throw new ApiError('VALIDATION_FAILED', 'No updatable fields provided.', 422);
        }
        $sets[] = 'updated_by = :uid';
        $sets[] = 'updated_at = NOW()';
        $sql = 'UPDATE app.land_titles SET ' . implode(', ', $sets) . ' WHERE id = :id AND deleted_at IS NULL';
        $this->pdo->prepare($sql)->execute($params);

        $this->audit->writeFromSession('UPDATE', 'app.land_titles', (string) $titleId, null,
            $body, null, 'Land title updated');

        return Envelope::success($response, $this->fetchTitle($titleId), 200);
    }

    // ── Link an existing title to a parcel ───────────────────────────────────

    public function linkToParcel(Request $request, Response $response, array $args): Response
    {
        $uid      = $this->requireUser($request);
        $this->scopeSession($uid);
        $parcelId = $this->parseUuid($args, 'id');
        $titleId  = (int) ($args['title_id'] ?? 0);
        $body     = JsonBodyParser::parse($request);
        $relationship = trim((string) ($body['relationship'] ?? 'COVERS'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO app.parcel_titles (parcel_id, title_id, relationship)
             VALUES (:pid, :tid, :rel)
             ON CONFLICT DO NOTHING'
        );
        $stmt->execute([':pid' => $parcelId, ':tid' => $titleId, ':rel' => $relationship]);

        $this->audit->writeFromSession('INSERT', 'app.parcel_titles', $parcelId, null,
            ['title_id' => $titleId, 'relationship' => $relationship], null, 'Title linked to parcel');

        return Envelope::success($response, ['linked' => true], 201);
    }

    // ── Unlink a title from a parcel ─────────────────────────────────────────

    public function unlinkFromParcel(Request $request, Response $response, array $args): Response
    {
        $uid      = $this->requireUser($request);
        $this->scopeSession($uid);
        $parcelId = $this->parseUuid($args, 'id');
        $titleId  = (int) ($args['title_id'] ?? 0);

        $stmt = $this->pdo->prepare(
            'DELETE FROM app.parcel_titles WHERE parcel_id = :pid AND title_id = :tid'
        );
        $stmt->execute([':pid' => $parcelId, ':tid' => $titleId]);

        $this->audit->writeFromSession('DELETE', 'app.parcel_titles', $parcelId, null,
            ['title_id' => $titleId], null, 'Title unlinked from parcel');

        return Envelope::success($response, ['unlinked' => true], 200);
    }

    // ── Add a party to a title ────────────────────────────────────────────────

    public function addParty(Request $request, Response $response, array $args): Response
    {
        $uid     = $this->requireUser($request);
        $this->scopeSession($uid);
        $titleId = (int) ($args['title_id'] ?? 0);
        $body    = JsonBodyParser::parse($request);

        $fullName   = trim((string) ($body['full_name'] ?? ''));
        $partyType  = trim((string) ($body['party_type'] ?? 'INDIVIDUAL'));
        $role       = trim((string) ($body['role'] ?? 'REGISTERED_OWNER'));
        $shareNum   = isset($body['share_numerator'])   ? (int) $body['share_numerator']   : null;
        $shareDen   = isset($body['share_denominator']) ? (int) $body['share_denominator'] : null;
        $effectFrom = ($body['effective_from'] ?? null) ?: null;

        if ($fullName === '') {
            throw new ApiError('VALIDATION_FAILED', 'full_name is required.', 422);
        }

        $this->pdo->beginTransaction();
        try {
            // Upsert the party (plain-text for now; encryption can be layered later)
            $ins = $this->pdo->prepare(
                "INSERT INTO app.parties (party_type, full_name_enc, version, created_by, updated_by)
                 VALUES (:pt, :fn::bytea, 1, :uid, :uid)
                 RETURNING id"
            );
            $ins->execute([':pt' => $partyType, ':fn' => $fullName, ':uid' => $uid]);
            $partyId = (int) $ins->fetchColumn();

            $link = $this->pdo->prepare(
                'INSERT INTO app.title_parties
                   (title_id, party_id, role, share_numerator, share_denominator, effective_from)
                 VALUES (:tid, :pid, :role, :sn, :sd, :ef)
                 RETURNING id'
            );
            $link->execute([
                ':tid'  => $titleId, ':pid' => $partyId,
                ':role' => $role, ':sn'   => $shareNum,
                ':sd'   => $shareDen, ':ef' => $effectFrom,
            ]);
            $tpId = (int) $link->fetchColumn();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->audit->writeFromSession('INSERT', 'app.title_parties', (string) $tpId, null,
            ['title_id' => $titleId, 'full_name' => $fullName, 'role' => $role], null, 'Party added to title');

        return Envelope::success($response, ['id' => $tpId, 'party_id' => $partyId, 'role' => $role], 201);
    }

    // ── Remove a party entry ──────────────────────────────────────────────────

    public function removeParty(Request $request, Response $response, array $args): Response
    {
        $uid   = $this->requireUser($request);
        $this->scopeSession($uid);
        $tpId  = (int) ($args['party_id'] ?? 0);

        $stmt = $this->pdo->prepare('DELETE FROM app.title_parties WHERE id = :id');
        $stmt->execute([':id' => $tpId]);

        $this->audit->writeFromSession('DELETE', 'app.title_parties', (string) $tpId, null,
            [], null, 'Party removed from title');

        return Envelope::success($response, ['removed' => true], 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchTitle(int $titleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM app.land_titles WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $titleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiError('NOT_FOUND', 'Title not found.', 404);
        }
        $row['parties'] = $this->fetchParties($titleId);
        return $row;
    }

    private function fetchParties(int $titleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tp.id, tp.party_id, tp.role, tp.share_numerator, tp.share_denominator,
                    tp.effective_from, tp.effective_to,
                    encode(p.full_name_enc, \'escape\') AS full_name,
                    p.party_type
               FROM app.title_parties tp
               JOIN app.parties p ON p.id = tp.party_id
              WHERE tp.title_id = :tid
              ORDER BY tp.id'
        );
        $stmt->execute([':tid' => $titleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireUser(Request $request): int
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $userId;
    }

    private function scopeSession(int $uid): void
    {
        $this->pdo->exec('SET LOCAL app.current_user_id = ' . (int) $uid);
    }

    private function parseUuid(array $args, string $key): string
    {
        $val = $args[$key] ?? '';
        if (!is_string($val) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', "Invalid UUID for '$key'.", 400);
        }
        return strtolower($val);
    }
}
