<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

/**
 * TASK-125 — raw DXF entity scanner for the dropped-entity report (FR-250).
 *
 * A DXF is an ASCII tagged format: a stream of `code` / `value` line pairs, with
 * entity definitions inside the `ENTITIES` section (code `0` names the entity,
 * code `8` names the CAD layer). `ogr2ogr` translates the geometric entities and
 * silently discards the annotation, block-reference and 3D-solid entities; this
 * scanner reads the source bytes directly so the platform can *report* what was
 * discarded rather than letting it vanish (architecture.md §17.2).
 *
 * It is deliberately a shallow scanner: it counts entity type names and collects
 * the CAD layer names, and does not attempt to interpret entity geometry. Only
 * the `ENTITIES` section is examined, so table/header values cannot be
 * misinterpreted as entities.
 */
final class DxfEntityScanner
{
    /**
     * Entity types the import does not transfer as GIS geometry: textual
     * annotations, block references, dimensions/leaders, and the 3D solid/region
     * entities GDAL cannot translate at all. Each occurrence is reported so the
     * user sees exactly what was left behind.
     */
    public const DROPPED_TYPES = [
        'TEXT',
        'MTEXT',
        'ATTDEF',
        'ATTRIB',
        'INSERT',
        'DIMENSION',
        'LEADER',
        'MULTILEADER',
        'TOLERANCE',
        '3DSOLID',
        'REGION',
        'BODY',
        'SURFACE',
        'MESH',
        'MPOLYGON',
        'RAY',
        'TABLE',
        'XLINE',
        'OLE2FRAME',
        'SHAPE',
        'IMAGE',
        'VIEWPORT',
    ];

    /**
     * @return array{
     *     entity_types: array<string,int>,
     *     entity_layers: list<string>,
     *     dropped: array<string,int>,
     *     total: int
     * }
     */
    public function scan(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $count = \count($lines);

        $entityTypes = [];
        $layers = [];
        $inEntities = false;
        $awaitingSectionName = false;

        // The DXF grammar is strictly tag/value pairs, so walking two lines at a
        // time is exact (and immune to a value that itself looks like a tag).
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $code = trim($lines[$i]);
            $value = trim($lines[$i + 1]);

            if ($code === '0' && $value === 'SECTION') {
                $awaitingSectionName = true;
                continue;
            }

            if ($awaitingSectionName) {
                $inEntities = $code === '2' && $value === 'ENTITIES';
                $awaitingSectionName = false;
                continue;
            }

            if (!$inEntities) {
                continue;
            }

            if ($code === '0') {
                if ($value === 'ENDSEC') {
                    $inEntities = false;
                    continue;
                }
                if ($value !== '') {
                    $entityTypes[$value] = ($entityTypes[$value] ?? 0) + 1;
                }
                continue;
            }

            if ($code === '8' && $value !== '') {
                $layers[$value] = true;
            }
        }

        $dropped = [];
        foreach (self::DROPPED_TYPES as $type) {
            if (isset($entityTypes[$type])) {
                $dropped[$type] = $entityTypes[$type];
            }
        }

        return [
            'entity_types'  => $entityTypes,
            'entity_layers' => array_keys($layers),
            'dropped'       => $dropped,
            'total'         => array_sum($entityTypes),
        ];
    }
}
