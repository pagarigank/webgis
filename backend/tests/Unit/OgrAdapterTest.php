<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Error\ApiError;
use App\Core\Geo\OgrAdapter;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * TASK-121 — OGR adapter and format detection.
 *
 * These tests never require GDAL: command construction uses an injected
 * executor closure, and format detection is pure filesystem magic-byte and
 * extension probing. The real `ogr2ogr`/`ogrinfo` binaries are exercised
 * separately in `Tests\Integration\OgrFormatTest`.
 */
class OgrAdapterTest extends TestCase
{
    /** Minimal ogrinfo -json payload with one polygon layer in EPSG:4326. */
    private const OGRINFO_JSON = '{
        "driverShortName": "GeoJSON",
        "layers": [{
            "name": "parcels",
            "geometryFields": [{"name": "", "type": "Polygon",
                "coordinateSystem": {"projjson": {"id": {"authority": "EPSG", "code": 4326}}}}],
            "featureCount": 3,
            "fields": [{"name": "id", "type": "Integer"}]
        }]
    }';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ogrtest_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Format detection (no GDAL needed)
    // ------------------------------------------------------------------

    public function testDetectsGeoJsonFromContent(): void
    {
        $adapter = new OgrAdapter();
        $path = $this->write('parcels.geojson', '{"type":"FeatureCollection","features":[]}');

        $this->assertSame(OgrAdapter::FORMAT_GEOJSON, $adapter->detectFormat($path));
    }

    public function testDetectsCsvFromExtension(): void
    {
        $adapter = new OgrAdapter();
        $path = $this->write('points.csv', "name,lat,lon\nA,14.5,121.0\n");

        $this->assertSame(OgrAdapter::FORMAT_CSV, $adapter->detectFormat($path));
    }

    public function testDetectsKmlFromContent(): void
    {
        $adapter = new OgrAdapter();
        $path = $this->write('map.bin', '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document/></kml>');

        $this->assertSame(OgrAdapter::FORMAT_KML, $adapter->detectFormat($path));
    }

    public function testDetectsShapefileInsideZipArchive(): void
    {
        $path = $this->makeZip('parcels.zip', [
            'parcels.shp' => 'fake-shp',
            'parcels.dbf' => 'fake-dbf',
            'parcels.shx' => 'fake-shx',
        ]);

        $this->assertSame(OgrAdapter::FORMAT_SHAPEFILE, (new OgrAdapter())->detectFormat($path, 'parcels.zip'));
    }

    public function testDetectsGeoPackageHeader(): void
    {
        // SQLite header with the GPKG application_id at byte offset 68.
        $head = str_pad('SQLite format 3' . "\x00", 68, "\x00") . pack('N', 0x47504B47);
        $path = $this->write('data.gpkg', $head . str_repeat("\x00", 100));

        $this->assertSame(OgrAdapter::FORMAT_GEOPACKAGE, (new OgrAdapter())->detectFormat($path));
    }

    public function testUnknownFormatIsReportedNotGuessed(): void
    {
        $path = $this->write('data.dat', 'hello world, this is plain text');
        $this->assertSame(OgrAdapter::FORMAT_UNKNOWN, (new OgrAdapter())->detectFormat($path));
    }

    public function testMalformedArchiveFailsCleanlyWithUsefulMessage(): void
    {
        $path = $this->write('broken.zip', 'this is definitely not a zip archive');

        try {
            (new OgrAdapter())->detectFormat($path, 'broken.zip');
            $this->fail('A file with a .zip extension and no ZIP signature must be rejected.');
        } catch (ApiError $e) {
            $this->assertSame('IMPORT_INVALID', $e->getErrorCode());
            $this->assertSame(422, $e->getApiStatus());
            $this->assertStringContainsString('zip', strtolower($e->getMessage()));
        }
    }

    public function testTruncatedZipFailsCleanly(): void
    {
        // Correct signature, but no central directory: a truncated download.
        $path = $this->write('truncated.zip', "PK\x03\x04" . random_bytes(32));

        try {
            (new OgrAdapter())->detectFormat($path, 'truncated.zip');
            $this->fail('A truncated zip archive must be rejected.');
        } catch (ApiError $e) {
            $this->assertSame('IMPORT_INVALID', $e->getErrorCode());
            $this->assertMatchesRegularExpression('/corrupt|truncated/i', $e->getMessage());
        }
    }

    public function testZipWithoutSpatialDataFailsCleanly(): void
    {
        $path = $this->makeZip('docs.zip', ['readme.txt' => 'no spatial data here']);

        try {
            (new OgrAdapter())->detectFormat($path, 'docs.zip');
            $this->fail('A zip with no .shp/.gpkg entry must be rejected.');
        } catch (ApiError $e) {
            $this->assertSame('IMPORT_INVALID', $e->getErrorCode());
            $this->assertStringContainsString('does not contain', $e->getMessage());
        }
    }

    public function testUnreadableFileFailsCleanly(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('could not be read');

        (new OgrAdapter())->detectFormat($this->tmp . '/missing.geojson');
    }

    // ------------------------------------------------------------------
    // Command construction / injection safety
    // ------------------------------------------------------------------

    public function testArgumentsArePassedAsAnArrayNeverInterpolatedIntoAShellString(): void
    {
        $captured = null;
        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, function (array $argv, int $timeout) use (&$captured): array {
            $captured = $argv;
            return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => '', 'timed_out' => false];
        });

        $hostile = '"; rm -rf / #.shp';
        $adapter->run(['ogrinfo', '-ro', '-so', '-al', '-json', $hostile]);

        $this->assertIsArray($captured, 'The executor must receive an argv array, not a command string.');
        $this->assertSame($hostile, $captured[5], 'A hostile filename must survive intact as one argv element.');
        $this->assertCount(6, $captured);
    }

    public function testProbeBuildsTheExpectedOgrinfoArguments(): void
    {
        $captured = null;
        $adapter = new OgrAdapter('/usr/bin/ogrinfo', '/usr/bin/ogr2ogr', 30, function (array $argv, int $timeout) use (&$captured): array {
            $captured = $argv;
            return ['exit_code' => 0, 'stdout' => self::OGRINFO_JSON, 'stderr' => '', 'timed_out' => false];
        });

        $path = $this->write('parcels.geojson', '{"type":"FeatureCollection","features":[]}');
        $result = $adapter->probe($path, OgrAdapter::FORMAT_GEOJSON);
        $this->assertIsArray($captured);

        $this->assertSame('/usr/bin/ogrinfo', $captured[0]);
        $this->assertContains('-ro', $captured);
        $this->assertContains('-so', $captured);
        $this->assertContains('-al', $captured);
        $this->assertContains('-json', $captured);
        $this->assertSame($path, end($captured), 'The dataset path must be the final argument.');

        $this->assertSame('GeoJSON', $result['format']);
        $this->assertSame('GeoJSON', $result['driver']);
        $this->assertSame(3, $result['feature_count']);
        $this->assertSame('EPSG:4326', $result['crs']);
        $this->assertSame('Polygon', $result['layers'][0]['geometry_type']);
        $this->assertSame('parcels', $result['layers'][0]['name']);
        $this->assertNull($result['error']);
    }

    public function testCrsFallsBackToWktWhenProjJsonIdIsAbsent(): void
    {
        $json = '{"driverShortName":"GPKG","layers":[{"name":"l","featureCount":1,
            "geometryFields":[{"type":"Point","coordinateSystem":{"wkt":"PROJCRS[\"x\",ID[\"EPSG\",3123]]"}}]}]}';

        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, fn (array $argv, int $timeout): array => [
            'exit_code' => 0, 'stdout' => $json, 'stderr' => '', 'timed_out' => false,
        ]);

        $path = $this->write('x.geojson', '{"type":"FeatureCollection"}');
        $result = $adapter->probe($path, OgrAdapter::FORMAT_GEOJSON);

        $this->assertSame('EPSG:3123', $result['crs']);
    }

    public function testProbeRejectsNonZeroExitWithACleanError(): void
    {
        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, fn (array $argv, int $timeout): array => [
            'exit_code' => 1,
            'stdout'    => '',
            'stderr'    => "ERROR 4: `x.geojson' not recognized as a supported file format.",
            'timed_out' => false,
        ]);

        $path = $this->write('x.geojson', '{"type":"FeatureCollection"}');

        try {
            $adapter->probe($path, OgrAdapter::FORMAT_GEOJSON);
            $this->fail('A non-zero OGR exit must raise ApiError.');
        } catch (ApiError $e) {
            $this->assertSame('IMPORT_INVALID', $e->getErrorCode());
            $this->assertStringContainsString('GeoJSON', $e->getMessage());
            $this->assertStringContainsString('not recognized', $e->getMessage());
            // GDAL's numeric severity prefix is stripped for readability.
            $this->assertStringNotContainsString('ERROR 4:', $e->getMessage());
        }
    }

    public function testZipShapefileIsProbedThroughVsizip(): void
    {
        $captured = null;
        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, function (array $argv, int $timeout) use (&$captured): array {
            $captured = $argv;
            return ['exit_code' => 0, 'stdout' => self::OGRINFO_JSON, 'stderr' => '', 'timed_out' => false];
        });

        $path = $this->makeZip('parcels.zip', ['parcels.shp' => 'x']);
        $adapter->probe($path, OgrAdapter::FORMAT_SHAPEFILE);
        $this->assertIsArray($captured);

        $this->assertSame('/vsizip/' . $path, end($captured));
    }

    // ------------------------------------------------------------------
    // Timeout handling
    // ------------------------------------------------------------------

    public function testTimeoutTerminatesTheProcessAndDiscardsPartialOutput(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled; cannot exercise the real executor.');
        }

        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 1);
        $started = microtime(true);
        $result = $adapter->run([PHP_BINARY, '-r', 'echo "partial output"; usleep(5_000_000);'], 1);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['timed_out'], 'The process must be reported as timed out.');
        $this->assertSame('', $result['stdout'], 'Partial output must be discarded, never parsed.');
        $this->assertSame('', $result['stderr']);
        $this->assertSame(-1, $result['exit_code']);
        $this->assertLessThan(4.0, $elapsed, 'proc_terminate must stop the process promptly.');
    }

    public function testRunRejectsAnEmptyArgumentVector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OgrAdapter())->run([]);
    }

    // ------------------------------------------------------------------
    // Sandbox cleanup
    // ------------------------------------------------------------------

    public function testSandboxDirectoryIsRemovedAfterSuccess(): void
    {
        $adapter = new OgrAdapter();
        $seen = null;

        $result = $adapter->withSandbox(function (string $dir) use (&$seen): string {
            $seen = $dir;
            $this->assertDirectoryExists($dir);
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'work.txt', 'data');
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertNotNull($seen);
        $this->assertDirectoryDoesNotExist($seen);
    }

    public function testSandboxDirectoryIsRemovedAfterAnException(): void
    {
        $adapter = new OgrAdapter();
        $seen = null;
        $threw = false;

        try {
            $adapter->withSandbox(function (string $dir) use (&$seen): void {
                $seen = $dir;
                file_put_contents($dir . DIRECTORY_SEPARATOR . 'work.txt', 'data');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($threw, 'The callback exception must propagate.');
        $this->assertNotNull($seen);
        $this->assertDirectoryDoesNotExist($seen, 'The sandbox must be removed even when the callback throws.');
    }

    // ------------------------------------------------------------------
    // Transaction guard
    // ------------------------------------------------------------------

    public function testRefusesToRunInsideAnOpenDatabaseTransaction(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is unavailable; cannot simulate an open transaction.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->beginTransaction();

        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, fn (array $argv, int $timeout): array => [
            'exit_code' => 0, 'stdout' => '', 'stderr' => '', 'timed_out' => false,
        ], $pdo);

        try {
            $adapter->run(['ogrinfo', '--version']);
            $this->fail('Running OGR inside an open transaction must be refused.');
        } catch (ApiError $e) {
            $this->assertSame('INTERNAL_ERROR', $e->getErrorCode());
            $this->assertStringContainsString('transaction', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testRunsNormallyWhenNoTransactionIsOpen(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is unavailable.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new OgrAdapter('ogrinfo', 'ogr2ogr', 30, fn (array $argv, int $timeout): array => [
            'exit_code' => 0, 'stdout' => 'GDAL 3.13.3', 'stderr' => '', 'timed_out' => false,
        ], $pdo);

        $result = $adapter->run(['ogrinfo', '--version']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertTrue($adapter->isAvailable());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function write(string $name, string $content): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** @param array<string,string> $entries */
    private function makeZip(string $name, array $entries): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . $name;
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Could not create the test zip archive.');
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();
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
            is_dir($child) ? $this->removeDir($child) : unlink($child);
        }
        rmdir($dir);
    }
}
