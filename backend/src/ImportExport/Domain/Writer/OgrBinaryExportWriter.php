<?php
declare(strict_types=1);

namespace App\ImportExport\Domain\Writer;

use App\Core\Error\ApiError;
use App\Core\Geo\OgrAdapter;
use App\ImportExport\Domain\ExportDataset;

/**
 * TASK-127 — OGR-backed writers for Shapefile and GeoPackage.
 *
 * Neither format is written by hand: the dataset is serialised to a GeoJSON
 * intermediate inside a throwaway sandbox and `ogr2ogr` produces the real
 * artefact, which is the same tool the import side already trusts.
 *
 * Provenance placement differs because the formats differ: a GeoPackage gets
 * proper `gpkg_metadata` rows (the OGC metadata extension), while a Shapefile
 * has nowhere to put metadata inside the .shp itself, so the disclaimer ships as
 * a `README.txt` member of the returned zip alongside the standard sidecars.
 */
final class OgrBinaryExportWriter implements ExportWriter
{
    public function __construct(
        private readonly OgrAdapter $ogr,
        private readonly string $format = 'SHAPEFILE',
    ) {
    }

    public function format(): string
    {
        return $this->format;
    }

    public function allowedCrs(): ?string
    {
        return 'EPSG:4326';
    }

    public function write(ExportDataset $dataset): array
    {
        if (!$this->ogr->isAvailable()) {
            throw new ApiError(
                'EXPORT_UNAVAILABLE',
                sprintf('The %s writer needs GDAL, which is not available on this server.', $this->format),
                503
            );
        }

        $isShapefile = $this->format === 'SHAPEFILE';
        $driver = $isShapefile ? 'ESRI Shapefile' : 'GPKG';
        $extension = $isShapefile ? 'shp' : 'gpkg';
        $basename = sprintf('layer_%d', $dataset->layerId);

        return $this->ogr->withSandbox(function (string $dir) use ($dataset, $driver, $extension, $basename, $isShapefile): array {
            $source = $dir . DIRECTORY_SEPARATOR . 'source.geojson';
            file_put_contents($source, $this->intermediate($dataset, $isShapefile));

            $target = $dir . DIRECTORY_SEPARATOR . $basename . '.' . $extension;

            // The reader has already reprojected the rows into the target CRS, so
            // the intermediate GeoJSON is *in* that CRS rather than in WGS 84.
            // ogr2ogr must therefore be told the source CRS (-a_srs) instead of
            // being asked to reproject (-t_srs), which would shift the
            // coordinates a second time.
            $extra = ['-a_srs', $dataset->provenance->crsCode, '-nln', $basename];
            if ($isShapefile) {
                // Without an explicit encoding GDAL guesses from the locale,
                // which silently mangles non-ASCII attribute values.
                $extra[] = '-lco';
                $extra[] = 'ENCODING=UTF-8';
            }
            $this->ogr->convert($source, $target, $extra, $driver);

            if (!is_file($target)) {
                throw new ApiError('EXPORT_FAILED', 'OGR produced no output for the requested format.', 500);
            }

            if ($isShapefile) {
                return [
                    'content_type' => 'application/zip',
                    'body'         => $this->zipShapefile($dir, $basename, $dataset),
                ];
            }

            $this->annotateGeoPackage($target, $dataset);

            return [
                'content_type' => 'application/geopackage+zip3',
                'body'         => (string) file_get_contents($target),
            ];
        });
    }

    /**
     * GeoJSON is the lingua franca both OGR drivers read, and it lets this
     * writer reuse one serialiser instead of two native ones.
     */
    private function intermediate(ExportDataset $dataset, bool $isShapefile): string
    {
        $features = [];
        foreach ($dataset->features as $row) {
            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $features[] = [
                'type'       => 'Feature',
                'id'         => (string) ($row['id'] ?? ''),
                'geometry'   => $row['geometry'] ?? null,
                'properties' => [
                    'id'            => (string) ($row['id'] ?? ''),
                    'status'        => (string) ($row['status'] ?? ''),
                    'psgc_barangay' => (string) ($row['psgc_barangay'] ?? ''),
                    'provenance'    => (string) ($row['provenance'] ?? ''),
                ] + $this->normaliseKeys($attributes, $isShapefile),
            ];
        }

        return (string) json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Shapefile attribute names are DBF field names: at most 10 characters, and
     * no leading digit. Arbitrary JSON keys would otherwise fail the conversion,
     * so they are folded to unique, legal names. A GeoPackage has no such limit
     * and keeps the original keys.
     *
     * @param array<array-key,mixed> $attributes
     * @return array<string,mixed>
     */
    private function normaliseKeys(array $attributes, bool $isShapefile): array
    {
        if (!$isShapefile) {
            $out = [];
            foreach ($attributes as $key => $value) {
                $out[is_string($key) ? $key : 'field_' . $key] = $value;
            }
            return $out;
        }

        $out = [];
        $used = [];
        foreach ($attributes as $key => $value) {
            $name = strtolower((string) $key);
            $name = preg_replace('/[^a-z0-9_]/', '_', $name) ?? '';
            $name = substr($name, 0, 10);
            if ($name === '' || preg_match('/^[0-9]/', $name) === 1) {
                $name = 'f_' . $name;
            }
            $name = substr($name, 0, 10);
            $candidate = $name;
            $suffix = 1;
            while (in_array($candidate, $used, true)) {
                $suffix++;
                $tail = '_' . $suffix;
                $candidate = substr($name, 0, 10 - strlen($tail)) . $tail;
            }
            $used[] = $candidate;
            $out[$candidate] = $value;
        }

        return $out;
    }

    /**
     * A Shapefile is only usable as the set of files GDAL produced, so they are
     * returned as a zip with the disclaimer sidecar added.
     */
    private function zipShapefile(string $dir, string $basename, ExportDataset $dataset): string
    {
        $members = glob($dir . DIRECTORY_SEPARATOR . $basename . '.*') ?: [];
        if ($members === []) {
            throw new ApiError('EXPORT_FAILED', 'OGR produced no Shapefile components.', 500);
        }

        $readme = $dir . DIRECTORY_SEPARATOR . 'README.txt';
        file_put_contents($readme, $dataset->provenance->toText() . "\n");
        $members[] = $readme;

        $zipPath = $dir . DIRECTORY_SEPARATOR . 'export.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ApiError('EXPORT_FAILED', 'Could not assemble the Shapefile archive.', 500);
        }
        foreach ($members as $member) {
            $name = basename($member);
            // Defence in depth: these all come from a sandbox we just created.
            $zip->addFile($member, $name);
        }
        $zip->close();

        return (string) file_get_contents($zipPath);
    }

    /**
     * Write the provenance as OGC `gpkg_metadata` rows so the disclaimer is
     * inside the GeoPackage rather than beside it.
     *
     * GDAL already creates both metadata tables when it writes a GPKG, matching
     * the columns below. The CREATE statements are kept as a fallback for GDAL
     * builds that omit them, and use the same column names so the two paths
     * cannot produce incompatible tables.
     */
    private function annotateGeoPackage(string $path, ExportDataset $dataset): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec('CREATE TABLE IF NOT EXISTS gpkg_metadata ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . "md_scope TEXT NOT NULL DEFAULT 'dataset', "
            . 'md_standard_uri TEXT NOT NULL, '
            . "mime_type TEXT NOT NULL DEFAULT 'text/xml', "
            . "metadata TEXT NOT NULL DEFAULT '')");

        $pdo->exec('CREATE TABLE IF NOT EXISTS gpkg_metadata_reference ('
            . "reference_scope TEXT NOT NULL, "
            . 'table_name TEXT, column_name TEXT, row_id_value INTEGER, '
            . 'timestamp DATETIME NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\')), '
            . 'md_file_id INTEGER NOT NULL, md_parent_id INTEGER)');

        $insert = $pdo->prepare(
            'INSERT INTO gpkg_metadata (md_scope, md_standard_uri, mime_type, metadata) '
            . "VALUES ('dataset', 'http://webgis.local/export', 'text/plain', :metadata)"
        );
        $insert->execute([':metadata' => $dataset->provenance->toText()]);
        $mdFileId = (int) $pdo->lastInsertId();

        // A dataset-scope reference carries no table/column/row: the metadata
        // describes the whole package, not one feature.
        $reference = $pdo->prepare(
            'INSERT INTO gpkg_metadata_reference (reference_scope, table_name, column_name, row_id_value, md_file_id) '
            . "VALUES ('dataset', NULL, NULL, NULL, :md)"
        );
        $reference->execute([':md' => $mdFileId]);
    }

    public function filename(ExportDataset $dataset): string
    {
        return $this->format === 'SHAPEFILE'
            ? sprintf('layer_%d_features.zip', $dataset->layerId)
            : sprintf('layer_%d_features.gpkg', $dataset->layerId);
    }
}
