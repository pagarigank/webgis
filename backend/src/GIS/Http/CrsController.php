<?php
declare(strict_types=1);

namespace App\GIS\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CRS registry lookup (TASK-014 / TASK-056).
 *
 * Serves ref.crs_registry rows so the client can register display CRSes
 * (PRS92 zones, Luzon 1911 zones, Web Mercator) for readout/transform UI.
 * Display CRS is purely a client concern; transmitted geometry stays 4326.
 */
class CrsController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query(
            "SELECT srid, code, name, datum, zone, is_projected, is_historical
             FROM ref.crs_registry
             ORDER BY srid"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_projected'] = (bool) $row['is_projected'];
            $row['is_historical'] = (bool) $row['is_historical'];
        }
        unset($row);

        return Envelope::success($response, $rows);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $srid = (int) ($args['id'] ?? 0);
        if ($srid <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid CRS id', 400);
        }

        $stmt = $this->pdo->prepare(
            "SELECT srid, code, name, datum, zone, is_projected, is_historical
             FROM ref.crs_registry WHERE srid = :srid"
        );
        $stmt->execute([':srid' => $srid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'CRS not found', 404);
        }

        $row['is_projected'] = (bool) $row['is_projected'];
        $row['is_historical'] = (bool) $row['is_historical'];

        return Envelope::success($response, $row);
    }
}