<?php
declare(strict_types=1);

namespace App\Parcels\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-115 — Lineage API (api.md §8.4, FR-230…FR-233).
 *
 *   GET /parcels/{id}/lineage?direction=both|ancestors|descendants&depth=5
 *
 * Traversal is a recursive CTE per direction with a depth cap and an explicit
 * truncation flag — depth truncation is never silent. Each edge names the
 * operation that created it (FR-230). Cycle prevention is enforced at WRITE
 * time (VR-45 trigger); the read side additionally uses UNION (distinct) so
 * even a legacy cycle cannot loop the CTE forever.
 */
final class LineageController
{
    public const MAX_DEPTH = 10;
    private const ALLOWED_PARAMS = ['direction', 'depth'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lineage(Request $request, Response $response, array $args): Response
    {
        $query = $request->getQueryParams();
        foreach (array_keys($query) as $key) {
            if (!in_array((string) $key, self::ALLOWED_PARAMS, true)) {
                throw new ApiError('VALIDATION_FAILED', sprintf('Unknown query parameter %s.', $key), 400);
            }
        }

        $direction = strtolower((string) ($query['direction'] ?? 'both'));
        if (!in_array($direction, ['both', 'ancestors', 'descendants'], true)) {
            throw new ApiError('VALIDATION_FAILED', 'direction must be one of both, ancestors, descendants.', 400);
        }

        $depth = isset($query['depth']) ? (int) $query['depth'] : 3;
        if ($depth < 1 || $depth > self::MAX_DEPTH) {
            throw new ApiError('VALIDATION_FAILED', sprintf('depth must be between 1 and %d.', self::MAX_DEPTH), 400);
        }

        $parcelId = $this->parseUuid($args['id'] ?? null);

        // The root itself is always included (404 for unknown/out-of-scope).
        $rootStmt = $this->pdo->prepare(
            'SELECT id::varchar, parcel_code, lot_number, status,
                    COALESCE(ST_Area(geom::geography), 0) AS area_sqm
             FROM app.parcels WHERE id = :id AND deleted_at IS NULL'
        );
        $rootStmt->execute([':id' => $parcelId]);
        $root = $rootStmt->fetch(PDO::FETCH_ASSOC);
        if ($root === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        $root['depth'] = 0;

        // Discover the connected component ONCE (undirected, bounded at
        // MAX_DEPTH + 1 levels) and compute shortest-path distances in PHP:
        // a recursive CTE cannot express sibling-inclusive `both` traversal,
        // and its truncation probe false-positives on back-edges.
        [$edgeList] = $this->discover($parcelId, self::MAX_DEPTH + 1);
        $undirectedDist = $this->bfs($parcelId, $edgeList, 'both');
        $upDist = $this->bfs($parcelId, $edgeList, 'ancestors');
        $downDist = $this->bfs($parcelId, $edgeList, 'descendants');

        // Truncation is explicit, never silent: any node whose shortest-path
        // distance is exactly depth + 1 exists beyond the cap.
        $ancestorsTruncated = $direction !== 'descendants' && $this->hasAtDepth($upDist, $depth + 1);
        $descendantsTruncated = $direction !== 'ancestors' && $this->hasAtDepth($downDist, $depth + 1);

        $dist = match ($direction) {
            'ancestors' => $upDist,
            'descendants' => $downDist,
            default => $undirectedDist,
        };

        $nodeIds = [$parcelId];
        $dist[$parcelId] = 0; // the root always shows at depth 0
        foreach ($dist as $id => $d) {
            if ($id !== $parcelId && $d <= $depth) {
                $nodeIds[] = $id;
            }
        }

        $rows = $this->parcelRows($nodeIds, $dist);
        // Root first, then by depth and code (the root row carries depth 0).
        usort($rows, static fn (array $a, array $b): int => [$a['depth'], $a['parcel_code']] <=> [$b['depth'], $b['parcel_code']]);

        // Edges: every relationship within the returned node set, each naming
        // its operation (FR-230).
        $edges = $this->edgesAmong($nodeIds);

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[] = [
                'id' => (string) $row['id'],
                'parcel_code' => (string) $row['parcel_code'],
                'lot_number' => $row['lot_number'],
                'status' => (string) $row['status'],
                'area_sqm' => $row['area_sqm'] !== null ? round((float) $row['area_sqm'], 4) : null,
                'depth' => (int) $row['depth'],
            ];
        }

        return Envelope::success($response, [
            'nodes' => $nodes,
            'edges' => $edges,
            'truncated' => $ancestorsTruncated || $descendantsTruncated,
            'truncated_ancestors' => $ancestorsTruncated,
            'truncated_descendants' => $descendantsTruncated,
            'depth' => $depth,
            'direction' => $direction,
        ]);
    }

    /**
     * Walk the parcel's connected component level by level (undirected) and
     * collect every edge seen, up to $maxLevels. Cycles terminate naturally
     * via the visited set; each level is one GIST-indexed query.
     *
     * @return array{0: array<string, array<string, true>>, 1: array<string, int>} adjacency (parent => [child => true]) + undirected first-seen level per node
     */
    private function discover(string $rootId, int $maxLevels): array
    {
        $adjacency = [];
        $dist = [$rootId => 0];
        $frontier = [$rootId];

        for ($level = 1; $level <= $maxLevels && $frontier !== []; $level++) {
            $placeholders = [];
            $params = [];
            foreach ($frontier as $i => $id) {
                $placeholders[] = ':f' . $i;
                $params[':f' . $i] = $id;
            }
            $in = implode(', ', $placeholders);

            $stmt = $this->pdo->prepare("
                SELECT r.parent_parcel_id::varchar AS parent, r.child_parcel_id::varchar AS child
                  FROM app.parcel_relationships r
                 WHERE r.parent_parcel_id::varchar IN ($in)
                    OR r.child_parcel_id::varchar IN ($in)
            ");
            $stmt->execute($params);

            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $parent = (string) $row['parent'];
                $child = (string) $row['child'];
                $adjacency[$parent][$child] = true;
                // An edge may touch the frontier on either (or both) ends.
                foreach ([$parent, $child] as $endpoint) {
                    $other = $endpoint === $parent ? $child : $parent;
                    if (in_array($endpoint, $frontier, true) && !isset($dist[$other])) {
                        $dist[$other] = $level;
                        $next[] = $other;
                    }
                }
            }
            $frontier = array_values(array_unique($next));
        }

        return [$adjacency, $dist];
    }

    /**
     * Generation depths from the root.
     *
     * `both` uses family-tree semantics (api.md §8.4): the root sits at
     * depth 0; parents, children AND siblings are one generation away
     * (depth 1) — a sibling inherits its parent's generation rather than
     * paying two undirected hops. Grandparents/grandchildren/nephews sit at
     * depth 2. `ancestors`/`descendants` count pure hops in one direction.
     *
     * @param array<string, array<string, true>> $adjacency
     * @return array<string, int> node id => generation depth (root excluded)
     */
    private function bfs(string $rootId, array $adjacency, string $direction): array
    {
        // Invert once: the ancestor walk moves child -> parent.
        $parentsOf = [];
        foreach ($adjacency as $parent => $children) {
            foreach (array_keys($children) as $child) {
                $parentsOf[$child][] = $parent;
            }
        }

        if ($direction !== 'both') {
            // Pure one-direction hop count from the root.
            $dist = [];
            $frontier = [$rootId];
            $level = 0;
            while ($frontier !== [] && $level < self::MAX_DEPTH + 1) {
                $level++;
                $next = [];
                foreach ($frontier as $node) {
                    $neighbors = $direction === 'ancestors'
                        ? ($parentsOf[$node] ?? [])
                        : array_keys($adjacency[$node] ?? []);
                    foreach ($neighbors as $n) {
                        if (!isset($dist[$n])) {
                            $dist[$n] = $level;
                            $next[] = $n;
                        }
                    }
                }
                $frontier = $next;
            }
            return $dist;
        }

        // State relaxation over (node, orientation): children of an ancestor
        // are the root's siblings and inherit the ancestor's generation. A
        // sibling edge costs 0 (same generation), a generation step costs 1.
        // Bellman-Ford style: every accepted update strictly decreases a
        // state value bounded below by 0, so the loop terminates.
        $best = [$rootId => ['both' => 0]];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($best as $node => $kinds) {
                foreach ($kinds as $kind => $d) {
                    if ($d >= self::MAX_DEPTH + 1) {
                        continue;
                    }
                    $expansions = [];
                    if ($kind === 'both') {
                        foreach ($parentsOf[$node] ?? [] as $p) {
                            $expansions[] = [$p, 'up', $d + 1];
                        }
                        foreach (array_keys($adjacency[$node] ?? []) as $c) {
                            $expansions[] = [$c, 'down', $d + 1];
                        }
                    } elseif ($kind === 'up') {
                        foreach ($parentsOf[$node] ?? [] as $p) {
                            $expansions[] = [$p, 'up', $d + 1];
                        }
                        foreach (array_keys($adjacency[$node] ?? []) as $c) {
                            $expansions[] = [$c, 'side', $d];
                        }
                    } elseif ($kind === 'down') {
                        foreach (array_keys($adjacency[$node] ?? []) as $c) {
                            $expansions[] = [$c, 'down', $d + 1];
                        }
                        foreach ($parentsOf[$node] ?? [] as $p) {
                            $expansions[] = [$p, 'side', $d];
                        }
                    } else { // side
                        foreach (array_keys($adjacency[$node] ?? []) as $c) {
                            $expansions[] = [$c, 'side', $d + 1];
                        }
                        foreach ($parentsOf[$node] ?? [] as $p) {
                            $expansions[] = [$p, 'side', $d];
                        }
                    }
                    foreach ($expansions as [$n, $k, $nd]) {
                        if ($n === $rootId) {
                            continue;
                        }
                        if (!isset($best[$n][$k]) || $nd < $best[$n][$k]) {
                            $best[$n][$k] = $nd;
                            $changed = true;
                        }
                    }
                }
            }
        }

        $dist = [];
        foreach ($best as $n => $kinds) {
            $dist[$n] = min($kinds);
        }
        return $dist;
    }

    /** @param array<string, int> $dist */
    private function hasAtDepth(array $dist, int $depth): bool
    {
        return in_array($depth, $dist, true);
    }

    /**
     * Parcel rows for the node set; depth comes from the caller's distance
     * map (root => 0).
     *
     * @param string[] $nodeIds
     * @param array<string, int> $dist
     * @return list<array<string, mixed>>
     */
    private function parcelRows(array $nodeIds, array $dist): array
    {
        $placeholders = [];
        $params = [];
        foreach ($nodeIds as $i => $id) {
            $placeholders[] = ':n' . $i;
            $params[':n' . $i] = $id;
        }
        $in = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare("
            SELECT id::varchar, parcel_code, lot_number, status,
                   COALESCE(ST_Area(geom::geography), 0) AS area_sqm
              FROM app.parcels
             WHERE id::varchar IN ($in) AND deleted_at IS NULL
        ");
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (string) $row['id'];
            $row['depth'] = $dist[$id] ?? 0;
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Every edge between the returned nodes (both orientations).
     *
     * @param string[] $nodeIds
     * @return list<array<string,mixed>>
     */
    private function edgesAmong(array $nodeIds): array
    {
        $nodeIds = array_values(array_unique($nodeIds));
        if (count($nodeIds) <= 1) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($nodeIds as $i => $id) {
            $placeholders[] = ':n' . $i;
            $params[':n' . $i] = $id;
        }
        $in = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare("
            SELECT r.parent_parcel_id::varchar AS parent,
                   r.child_parcel_id::varchar AS child,
                   r.relationship_type AS type,
                   r.operation_id,
                   r.effective_date
              FROM app.parcel_relationships r
             WHERE r.parent_parcel_id::varchar IN ($in)
               AND r.child_parcel_id::varchar IN ($in)
             ORDER BY r.id
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'parent' => (string) $r['parent'],
            'child' => (string) $r['child'],
            'type' => (string) $r['type'],
            'operation_id' => $r['operation_id'] !== null ? (int) $r['operation_id'] : null,
            'effective_date' => $r['effective_date'],
        ], $rows);
    }

    private function parseUuid(mixed $raw): string
    {
        $v = (string) ($raw ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        return $v;
    }
}
