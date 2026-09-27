<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

use App\Core\Error\ApiError;
use App\Core\Geo\OgrAdapter;

/**
 * TASK-124 — OGR-backed reader for Shapefile, KML, GeoPackage and DXF.
 *
 * The stored source bytes are written into an {@see OgrAdapter::withSandbox()}
 * temp directory, `ogr2ogr` translates them to a GeoJSON intermediate, and the
 * GeoJSON body is handed to {@see GeoJsonRowReader}. The reader deliberately
 * does **not** reproject:
 *
 *  - No `-t_srs` is passed, so the feature coordinates stay in the source's own
 *    CRS. The declared CRS is applied later by `ImportJobService` when the row
 *    is transformed into the layer's 4326 staging geometry (FR-252).
 *  - A `.prj` is never silently applied. It only produces a *suggestion*
 *    surfaced to the user (see `ImportJobService::createJob()`); the user must
 *    confirm or override it before validation.
 *
 * The GeoJSON intermediate is written to disk and read back rather than piped
 * through stdout: `ogr2ogr`'s GeoJSON writer only emits RFC 7946 feature
 * collections reliably through a file target, and a file target keeps the
 * command argv free of a pipe.
 */
final class OgrRowReader implements RowReader
{
    /** api.md §10 source formats handled by the OGR adapter. */
    public const SUPPORTED = [
        'SHAPEFILE',
        'KML',
        'GEOPACKAGE',
        'DXF',
    ];

    public function __construct(
        private readonly OgrAdapter $ogr,
        private readonly GeoJsonRowReader $geojsonReader,
    ) {
    }

    public function supports(string $format): bool
    {
        return \in_array(strtoupper($format), self::SUPPORTED, true);
    }

    public function read(string $content, string $format, array $options = []): array
    {
        $format = strtoupper($format);

        $body = $this->ogr->withSandbox(function (string $dir) use ($content, $format): string {
            $source = $dir . DIRECTORY_SEPARATOR . 'source' . $this->extensionFor($format);
            if (file_put_contents($source, $content) === false) {
                throw new ApiError('IMPORT_INVALID', 'The uploaded file could not be staged for reading.', 422);
            }

            // A zipped shapefile is exposed to GDAL through its /vsizip/ virtual
            // path; every other supported format reads the file directly.
            $input = $source;
            if ($format === 'SHAPEFILE' && $this->ogr->isArchive($source)) {
                $input = '/vsizip/' . $source;
            }

            $target = $dir . DIRECTORY_SEPARATOR . 'converted.geojson';
            // No -t_srs: coordinates stay in the source CRS.
            $this->ogr->convert($input, $target, [], OgrAdapter::FORMAT_GEOJSON);

            $body = file_get_contents($target);
            if ($body === false) {
                throw new ApiError('IMPORT_INVALID', 'The OGR conversion produced no readable output.', 422);
            }

            return $body;
        });

        return $this->geojsonReader->read($body, GeoJsonRowReader::FORMAT, $options);
    }

    private function extensionFor(string $format): string
    {
        return match ($format) {
            'SHAPEFILE'  => '.zip',
            'GEOPACKAGE' => '.gpkg',
            'KML'        => '.kml',
            'DXF'        => '.dxf',
            default      => '.bin',
        };
    }
}
