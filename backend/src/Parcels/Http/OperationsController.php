<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * api.md §8.3 — "GET /operations/{operationId} returns the full record of
 * either operation: inputs, snapshot, results, reconciliation, validation,
 * actor, reason."
 */
final class OperationsController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['operation_id'] ?? 0);
        if ($id <= 0) {
            throw new ApiError('NOT_FOUND', 'Operation not found', 404);
        }

        $stmt = $this->pdo->prepare('SELECT * FROM app.parcel_operations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $op = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($op === false) {
            throw new ApiError('NOT_FOUND', 'Operation not found', 404);
        }

        $edgesStmt = $this->pdo->prepare(
            'SELECT parent_parcel_id::varchar AS parent, child_parcel_id::varchar AS child,
                    relationship_type, effective_date
             FROM app.parcel_relationships WHERE operation_id = :id ORDER BY id'
        );
        $edgesStmt->execute([':id' => $id]);

        return Envelope::success($response, [
            'id' => (int) $op['id'],
            'operation_type' => (string) $op['operation_type'],
            'method' => (string) $op['method'],
            'status' => (string) $op['status'],
            'inputs' => $op['inputs'],
            'parcel_snapshot' => $op['parcel_snapshot'],
            'results' => $op['results'],
            'area_reconciliation' => $op['area_reconciliation'],
            'validation_result' => $op['validation_result'],
            'performed_by' => $op['performed_by'] !== null ? (int) $op['performed_by'] : null,
            'performed_at' => $op['performed_at'],
            'reason' => $op['reason'],
            'edges' => $edgesStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }
}
