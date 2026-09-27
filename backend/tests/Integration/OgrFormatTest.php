<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Error\ApiError;
use App\Core\Geo\OgrAdapter;
use Tests\TestCase;
use ZipArchive;

/**
 * TASK-121 — OGR format detection and probing against the real GDAL binaries.
 *
 * These tests are skipped (with a clear message) on a host without `ogrinfo`,
 * so the suite stays green in an environment where GDAL cannot be installed;
 * where GDAL is present they prove the adapter reads real datasets end to end.
 */
class OgrFormatTest extends TestCase
{
    private const GEOJSON_FIXTURE = <<<'JSON'
    {
      "type": "FeatureCollection",
      "name": "parcels",
      "crs": {"type": "name", "properties": {"name": "urn:ogc:def:crs:OGC:1.3:CRS84"}},
      "features": [
        {"type": "Feature", "properties": {"parcel_code": "LOT-A"},
         "geometry": {"type": "Polygon", "coordinates": [[[121.000,14.000],[121.002,14.000],[121.002,14.002],[121.000,14.002],[121.000,14.000]]]}},
        {"type": "Feature", "properties": {"parcel_code": "LOT-B"},
         "geometry": {"type": "Polygon", "coordinates": [[[121.003,14.000],[121.005,14.000],[121.005,14.002],[121.003,14.002],[121.003,14.000]]]}}
      ]
    }
    JSON;

    private string $tmp;
    private OgrAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new OgrAdapter();
        if (!$this->adapter->isAvailable()) {
            $this->markTestSkipped('GDAL/ogr2ogr is not installed in this environment; OGR format tests skipped.');
        }

        $this->tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ogrformat_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            $this->removeDir($this->tmp);
        }
        parent::tearDown();
    }

    public function testProbesAGeoJsonFixture(): void
    {
        $path = $this->geojson();

        $result = $this->adapter->inspect($path, 'parcels.geojson');

        $this->assertSame(OgrAdapter::FORMAT_GEOJSON, $result['format']);
        $this->assertSame('GeoJSON', $result['driver']);
        $this->assertSame(2, $result['feature_count']);
        $this->assertSame('parcels', $result['layers'][0]['name']);
        $this->assertSame('EPSG:4326', $result['crs']);
        $this->assertSame('Polygon', $result['layers'][0]['geometry_type']);
        $this->assertContains('parcel_code', array_column($result['layers'][0]['fields'], 'name'));
    }

    public function testProbesAZipShapefileBuiltByOgr2ogr(): void
    {
        $shpDir = $this->tmp . DIRECTORY_SEPARATOR . 'shp';
        mkdir($shpDir);
        $this->adapter->convert($this->geojson(), $shpDir, [], 'ESRI Shapefile');

        $zipPath = $this->tmp . DIRECTORY_SEPARATOR . 'parcels.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach (glob($shpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        $this->assertSame(OgrAdapter::FORMAT_SHAPEFILE, $this->adapter->detectFormat($zipPath, 'parcels.zip'));

        $result = $this->adapter->inspect($zipPath, 'parcels.zip');

        $this->assertSame(OgrAdapter::FORMAT_SHAPEFILE, $result['format']);
        $this->assertSame(2, $result['feature_count']);
        $this->assertSame('EPSG:4326', $result['crs']);
        $this->assertSame('Polygon', $result['layers'][0]['geometry_type']);
    }

    public function testProbesAGeoPackageBuiltByOgr2ogr(): void
    {
        $gpkg = $this->tmp . DIRECTORY_SEPARATOR . 'parcels.gpkg';
        $this->adapter->convert($this->geojson(), $gpkg, [], 'GPKG');

        $this->assertSame(OgrAdapter::FORMAT_GEOPACKAGE, $this->adapter->detectFormat($gpkg, 'parcels.gpkg'));

        $result = $this->adapter->inspect($gpkg, 'parcels.gpkg');

        $this->assertSame(OgrAdapter::FORMAT_GEOPACKAGE, $result['format']);
        $this->assertSame('GPKG', $result['driver']);
        $this->assertSame(2, $result['feature_count']);
        $this->assertSame('EPSG:4326', $result['crs']);
    }

    public function testProbesACsvFixture(): void
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . 'points.csv';
        file_put_contents($path, "name,lat,lon\nA,14.5,121.0\nB,14.6,121.1\n");

        $result = $this->adapter->inspect($path, 'points.csv');

        $this->assertSame(OgrAdapter::FORMAT_CSV, $result['format']);
        $this->assertSame('CSV', $result['driver']);
        $this->assertSame(2, $result['feature_count']);
    }

    public function testMalformedArchiveFailsCleanlyAgainstTheRealAdapter(): void
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . 'broken.zip';
        // A valid ZIP signature followed by garbage — the classic truncated
        // upload. GDAL is never reached; detection must fail first, cleanly.
        file_put_contents($path, "PK\x03\x04" . random_bytes(256));

        try {
            $this->adapter->inspect($path, 'broken.zip');
            $this->fail('A malformed archive must not be accepted.');
        } catch (ApiError $e) {
            $this->assertSame('IMPORT_INVALID', $e->getErrorCode());
            $this->assertMatchesRegularExpression('/corrupt|truncated/i', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function geojson(): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . 'parcels.geojson';
        file_put_contents($path, self::GEOJSON_FIXTURE);
        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) && !is_link($child) ? $this->removeDir($child) : unlink($child);
        }
        rmdir($dir);
    }
}
