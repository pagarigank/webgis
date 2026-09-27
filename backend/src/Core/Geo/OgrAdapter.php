<?php
declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Error\ApiError;
use PDO;

/**
 * TASK-121 — OGR adapter and format detection (Phase 16 opener, ADR-11).
 *
 * A deliberately thin, replaceable wrapper around the GDAL `ogrinfo` and
 * `ogr2ogr` binaries, used (from TASK-124 on) for Shapefile, KML, GeoPackage
 * and DXF import/export. It exists so the external-binary dependency is
 * isolated in one class instead of smeared across controllers.
 *
 * Guarantees this class is responsible for:
 *
 *  - **No shell is ever involved.** Commands are passed to `proc_open()` as an
 *    *argument array* (PHP 7.4+), which executes the binary directly. There is
 *    no command string for a shell to parse, so no argument value — a filename,
 *    a layer name, anything — can be reinterpreted as shell syntax. String
 *    concatenation is never used. (Argument-array form is strictly stronger
 *    than `escapeshellarg()`, which only makes a string shell-safe.)
 *  - **Timeouts.** A hung OGR process is `proc_terminate()`d; partial stdout is
 *    discarded rather than parsed.
 *  - **Sandboxed temp dirs.** `withSandbox()` hands the caller a fresh random
 *    subdirectory under the system temp dir and removes it in a `finally`,
 *    including on exception.
 *  - **Clean failures.** A malformed archive or unreadable file raises an
 *    `ApiError` with a human-readable message; it never surfaces a PHP warning
 *    or an unhandled 500.
 *  - **Never inside a transaction.** When constructed with a PDO handle (the
 *    DI wiring from TASK-122), every subprocess call asserts the connection is
 *    *not* in an open transaction. Running an external process inside a
 *    business transaction would hold locks across an unbounded wait
 *    (`architecture.md` §19); files are written first, then imported.
 */
final class OgrAdapter
{
    public const FORMAT_GEOJSON    = 'GeoJSON';
    public const FORMAT_CSV        = 'CSV';
    public const FORMAT_SHAPEFILE  = 'Shapefile';
    public const FORMAT_GEOPACKAGE = 'GeoPackage';
    public const FORMAT_KML        = 'KML';
    public const FORMAT_DXF        = 'DXF';
    public const FORMAT_UNKNOWN    = 'Unknown';

    /** Formats GDAL must read; the native PHP importers (TASK-123) do the rest. */
    public const OGR_REQUIRED = [
        self::FORMAT_SHAPEFILE,
        self::FORMAT_GEOPACKAGE,
        self::FORMAT_KML,
        self::FORMAT_DXF,
    ];

    public const DEFAULT_TIMEOUT_SECONDS = 60;

    private const ZIP_MAGIC    = "PK\x03\x04";
    private const ZIP_EMPTY    = "PK\x05\x06";
    private const ZIP_SPANNED  = "PK\x07\x08";
    private const SQLITE_MAGIC = "SQLite format 3\x00";
    private const GPKG_APP_ID  = 0x47504B47; // 'GPKG'

    /**
     * Test seam: `fn(array $argv, int $timeout): array{exit_code:int,
     * stdout:string, stderr:string, timed_out:bool}`. When null the real
     * `proc_open` executor is used.
     *
     * @var (\Closure(array<int,string>, int): array{exit_code:int,stdout:string,stderr:string,timed_out:bool})|null
     */
    private ?\Closure $executor;

    public function __construct(
        private readonly string $ogrInfoBinary = 'ogrinfo',
        private readonly string $ogr2ogrBinary = 'ogr2ogr',
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?callable $executor = null,
        private readonly ?PDO $transactionGuard = null,
    ) {
        $this->executor = $executor === null ? null : \Closure::fromCallable($executor);
    }

    // ------------------------------------------------------------------
    // Capability / low-level execution
    // ------------------------------------------------------------------

    /** Whether the GDAL OGR tools answer on this host. */
    public function isAvailable(): bool
    {
        try {
            $result = $this->run([$this->ogrInfoBinary, '--version']);
            return $result['exit_code'] === 0 && !$result['timed_out'];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run an external command as an argv array and capture its output.
     *
     * @param array<int,string> $argv Non-empty; argv[0] is the binary.
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     */
    public function run(array $argv, ?int $timeoutSeconds = null): array
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('OgrAdapter::run() requires a non-empty argument vector.');
        }

        if ($this->transactionGuard !== null && $this->transactionGuard->inTransaction()) {
            throw new ApiError(
                'INTERNAL_ERROR',
                'The import adapter must not run inside an open database transaction; '
                . 'stage the file first, then import.',
                500
            );
        }

        $timeout = $timeoutSeconds ?? $this->timeoutSeconds;
        if ($this->executor !== null) {
            return ($this->executor)($argv, $timeout);
        }

        return $this->execute($argv, $timeout);
    }

    /**
     * The real executor. `proc_open` is given an array command so PHP spawns
     * the binary directly with no shell in between.
     *
     * @param array<int,string> $argv
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     */
    private function execute(array $argv, int $timeoutSeconds): array
    {
        if (!\function_exists('proc_open')) {
            throw new ApiError('INTERNAL_ERROR', 'Process execution is not available on this server.', 500);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];

        $process = @proc_open($argv, $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new ApiError('INTERNAL_ERROR', sprintf('Could not start %s.', $argv[0]), 500);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $started  = microtime(true);
        $stdout   = '';
        $stderr   = '';
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Capture the real exit code before proc_close() rewrites it.
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 9);
                usleep(50_000);
                break;
            }

            usleep(20_000);
        }

        // Drain whatever remains, then close the pipes.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);

        if ($timedOut) {
            // Partial output is discarded: a killed process has no valid result.
            return ['exit_code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => true];
        }

        return [
            'exit_code' => $exitCode ?? $closeCode,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'timed_out' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Sandbox
    // ------------------------------------------------------------------

    /**
     * Run $callback with a private, randomly named working directory and
     * remove it afterwards — on success or on exception.
     *
     * @template T
     * @param callable(string): T $callback
     * @return T
     */
    public function withSandbox(callable $callback): mixed
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'ogr_' . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ApiError('INTERNAL_ERROR', 'Could not create a sandbox directory for the import.', 500);
        }

        try {
            return $callback($dir);
        } finally {
            $this->removeDirectory($dir);
        }
    }

    // ------------------------------------------------------------------
    // Format detection
    // ------------------------------------------------------------------

    /**
     * Detect the source format from magic bytes first (authoritative), then
     * extension, then content sniffing. Throws a clean ApiError for a file
     * that is claimed to be an archive but is not one.
     *
     * @throws ApiError IMPORT_INVALID on an unreadable or corrupt file
     */
    public function detectFormat(string $path, ?string $originalName = null): string
    {
        $head = $this->readHead($path, 4096);
        $name = $originalName ?? $path;
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // --- Binary containers (magic wins over any extension) ---
        if ($this->hasZipMagic($head)) {
            return $this->inspectArchive($path);
        }

        if (str_starts_with($head, self::SQLITE_MAGIC)) {
            return $this->isGeoPackageHeader($head) ? self::FORMAT_GEOPACKAGE : self::FORMAT_UNKNOWN;
        }

        // A .zip that does not carry the ZIP signature is reported as such
        // rather than being handed to OGR to fail obscurely.
        if ($ext === 'zip') {
            throw new ApiError(
                'IMPORT_INVALID',
                'The uploaded file has a .zip extension but is not a valid zip archive.',
                422,
                ['fields' => [['field' => 'file', 'rule' => 'ARCHIVE']]],
            );
        }
        if ($ext === 'gpkg') {
            throw new ApiError(
                'IMPORT_INVALID',
                'The uploaded file has a .gpkg extension but is not a valid GeoPackage.',
                422,
                ['fields' => [['field' => 'file', 'rule' => 'GEOPACKAGE']]],
            );
        }

        // --- Text formats (content sniff, extension as tie-breaker) ---
        $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head, " \t\n\r\0\x0B");

        if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
            return self::FORMAT_GEOJSON;
        }
        if (stripos($head, '<kml') !== false || $ext === 'kml') {
            return self::FORMAT_KML;
        }
        if ($ext === 'dxf' || (preg_match('/^\s*0\s*\r?\nSECTION/', $head) === 1 && stripos($head, 'ENTITIES') !== false)) {
            return self::FORMAT_DXF;
        }
        if ($ext === 'csv') {
            return self::FORMAT_CSV;
        }
        if (in_array($ext, ['geojson', 'json'], true)) {
            return self::FORMAT_GEOJSON;
        }
        if ($ext === 'shp') {
            return self::FORMAT_SHAPEFILE;
        }

        return self::FORMAT_UNKNOWN;
    }

    /** True when the path is a zip container (used to pick the /vsizip/ source). */
    public function isArchive(string $path): bool
    {
        return $this->hasZipMagic($this->readHead($path, 4));
    }

    // ------------------------------------------------------------------
    // OGR probing
    // ------------------------------------------------------------------

    /**
     * Ask OGR to describe a dataset: driver, layers, feature counts and CRS.
     *
     * @return array{format:string,driver:?string,layers:array<int,array<string,mixed>>,
     *               feature_count:int,crs:?string,error:null}
     * @throws ApiError IMPORT_INVALID when OGR rejects the file
     */
    public function probe(string $path, ?string $format = null): array
    {
        $format ??= $this->detectFormat($path);

        $target = $path;
        if ($format === self::FORMAT_SHAPEFILE && $this->isArchive($path)) {
            // GDAL exposes a zipped dataset through its /vsizip/ virtual path.
            $target = '/vsizip/' . $path;
        }

        $result = $this->run([$this->ogrInfoBinary, '-ro', '-so', '-al', '-json', $target]);

        if ($result['timed_out']) {
            throw new ApiError(
                'INTERNAL_ERROR',
                sprintf('Reading the %s file timed out after %d seconds.', $format, $this->timeoutSeconds),
                500,
            );
        }

        if ($result['exit_code'] !== 0) {
            throw new ApiError(
                'IMPORT_INVALID',
                sprintf('The file could not be read as %s: %s', $format, $this->diagnostic($result['stderr'], $result['stdout'])),
                422,
                ['fields' => [['field' => 'file', 'rule' => 'FORMAT']]],
            );
        }

        $decoded = json_decode($result['stdout'], true);
        if (!\is_array($decoded)) {
            throw new ApiError('IMPORT_INVALID', 'OGR returned output that could not be parsed.', 422);
        }

        $normalised = $this->normaliseProbe($decoded);

        return [
            'format'        => $format,
            'driver'        => $normalised['driver'],
            'layers'        => $normalised['layers'],
            'feature_count' => $normalised['feature_count'],
            'crs'           => $normalised['crs'],
            'error'         => $normalised['error'],
        ];
    }

    /**
     * Detect the format and, when GDAL is available, describe the dataset in
     * one call. A corrupt archive fails at detection with a useful message.
     *
     * @return array{format:string,driver:?string,layers:array<int,array<string,mixed>>,
     *               feature_count:int,crs:?string,error:null}
     */
    public function inspect(string $path, ?string $originalName = null): array
    {
        $format = $this->detectFormat($path, $originalName);

        if (!$this->isAvailable()) {
            if (\in_array($format, self::OGR_REQUIRED, true)) {
                throw new ApiError(
                    'INTERNAL_ERROR',
                    sprintf('GDAL/OGR is not available on this server, so %s files cannot be inspected.', $format),
                    500,
                );
            }
            return [
                'format'        => $format,
                'driver'        => null,
                'layers'        => [],
                'feature_count' => 0,
                'crs'           => null,
                'error'         => null,
            ];
        }

        return $this->probe($path, $format);
    }

    /**
     * Translate a source dataset with `ogr2ogr`. Used by the importers
     * (TASK-124 on) once the source has been staged into a sandbox.
     *
     * @param array<int,string|int|float> $extraArgs extra ogr2ogr options (already split)
     * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
     * @throws ApiError IMPORT_INVALID when the conversion fails
     */
    public function convert(string $source, string $target, array $extraArgs = [], string $format = ''): array
    {
        $argv = [$this->ogr2ogrBinary];
        if ($format !== '') {
            $argv[] = '-f';
            $argv[] = $format;
        }
        foreach ($extraArgs as $arg) {
            $argv[] = (string) $arg;
        }
        $argv[] = $target;
        $argv[] = $source;

        $result = $this->run($argv);

        if ($result['timed_out']) {
            throw new ApiError('INTERNAL_ERROR', sprintf('The OGR conversion timed out after %d seconds.', $this->timeoutSeconds), 500);
        }
        if ($result['exit_code'] !== 0) {
            throw new ApiError(
                'IMPORT_INVALID',
                'OGR could not convert the file: ' . $this->diagnostic($result['stderr'], $result['stdout']),
                422,
                ['fields' => [['field' => 'file', 'rule' => 'CONVERT']]],
            );
        }

        return $result;
    }

    /** OGR driver name expected for a detected format. */
    public function driverFor(string $format): ?string
    {
        return match ($format) {
            self::FORMAT_GEOJSON    => 'GeoJSON',
            self::FORMAT_CSV        => 'CSV',
            self::FORMAT_SHAPEFILE  => 'ESRI Shapefile',
            self::FORMAT_GEOPACKAGE => 'GPKG',
            self::FORMAT_KML        => 'KML',
            self::FORMAT_DXF        => 'DXF',
            default                 => null,
        };
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $json
     * @return array{driver:?string,layers:array<int,array<string,mixed>>,feature_count:int,crs:?string,error:null}
     */
    private function normaliseProbe(array $json): array
    {
        $layers = [];
        $total  = 0;
        $crs    = null;

        foreach (($json['layers'] ?? []) as $layer) {
            if (!\is_array($layer)) {
                continue;
            }

            $geometryField = \is_array($layer['geometryFields'][0] ?? null) ? $layer['geometryFields'][0] : [];

            $geometryType = null;
            if (isset($geometryField['type'])) {
                $geometryType = (string) $geometryField['type'];
            } elseif (isset($layer['geometryType'])) {
                $geometryType = (string) $layer['geometryType'];
            }

            $layerCrs = $this->crsFromLayer($geometryField, $layer);
            $crs    ??= $layerCrs;
            $count    = isset($layer['featureCount']) ? (int) $layer['featureCount'] : null;
            if ($count !== null) {
                $total += $count;
            }

            $fields = [];
            foreach (($layer['fields'] ?? []) as $field) {
                if (\is_array($field) && isset($field['name'])) {
                    $fields[] = [
                        'name' => (string) $field['name'],
                        'type' => (string) ($field['type'] ?? 'String'),
                    ];
                }
            }

            $layers[] = [
                'name'          => (string) ($layer['name'] ?? ''),
                'geometry_type' => $geometryType,
                'feature_count' => $count,
                'crs'           => $layerCrs,
                'fields'        => $fields,
            ];
        }

        return [
            'driver'        => isset($json['driverShortName']) ? (string) $json['driverShortName'] : null,
            'layers'        => $layers,
            'feature_count' => $total,
            'crs'           => $crs,
            'error'         => null,
        ];
    }

    /**
     * GDAL nests the coordinate system under the geometry field; older/other
     * drivers may place it on the layer, so both are accepted.
     *
     * @param array<string,mixed> $geometryField
     * @param array<string,mixed> $layer
     */
    private function crsFromLayer(array $geometryField, array $layer): ?string
    {
        $cs = $geometryField['coordinateSystem'] ?? $layer['coordinateSystem'] ?? null;
        if (!\is_array($cs)) {
            return null;
        }

        $id = $cs['projjson']['id'] ?? null;
        if (\is_array($id) && isset($id['authority'], $id['code'])) {
            return $id['authority'] . ':' . $id['code'];
        }

        if (isset($cs['wkt']) && preg_match('/ID\[\s*"EPSG"\s*,\s*(\d+)\s*\]/', (string) $cs['wkt'], $m) === 1) {
            return 'EPSG:' . $m[1];
        }

        return null;
    }

    /** Validate a zip archive and classify whether it holds a shapefile or GeoPackage. */
    private function inspectArchive(string $path): string
    {
        $zip = $this->openArchive($path);

        try {
            $entries = [];
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false) {
                    $entries[] = strtolower((string) $name);
                }
            }

            if ($entries === []) {
                throw new ApiError(
                    'IMPORT_INVALID',
                    'The uploaded .zip archive is empty.',
                    422,
                    ['fields' => [['field' => 'file', 'rule' => 'ARCHIVE']]],
                );
            }

            foreach ($entries as $entry) {
                if (str_ends_with($entry, '.shp')) {
                    return self::FORMAT_SHAPEFILE;
                }
            }
            foreach ($entries as $entry) {
                if (str_ends_with($entry, '.gpkg')) {
                    return self::FORMAT_GEOPACKAGE;
                }
            }

            throw new ApiError(
                'IMPORT_INVALID',
                'The .zip archive does not contain a shapefile (.shp) or GeoPackage (.gpkg).',
                422,
                ['fields' => [['field' => 'file', 'rule' => 'ARCHIVE']], 'archive_entries' => $entries],
            );
        } finally {
            $zip->close();
        }
    }

    private function openArchive(string $path): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ApiError('INTERNAL_ERROR', 'Zip support is not available on this server.', 500);
        }

        $zip = new \ZipArchive();
        // Convert any archive warning into an exception we can translate, so a
        // truncated download never leaks a PHP warning.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException($message);
        });

        try {
            $code = $zip->open($path, \ZipArchive::CHECKCONS);
        } catch (\Throwable) {
            throw new ApiError(
                'IMPORT_INVALID',
                'The uploaded .zip archive is corrupt or truncated and could not be opened.',
                422,
                ['fields' => [['field' => 'file', 'rule' => 'ARCHIVE']]],
            );
        } finally {
            restore_error_handler();
        }

        if ($code !== true) {
            throw new ApiError(
                'IMPORT_INVALID',
                sprintf('The uploaded .zip archive is corrupt or truncated and could not be opened (ZipArchive: %d).', $code),
                422,
                ['fields' => [['field' => 'file', 'rule' => 'ARCHIVE']]],
            );
        }

        return $zip;
    }

    private function readHead(string $path, int $bytes): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiError(
                'IMPORT_INVALID',
                'The uploaded file could not be read.',
                422,
                ['fields' => [['field' => 'file', 'rule' => 'READABLE']]],
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ApiError('IMPORT_INVALID', 'The uploaded file could not be opened.', 422);
        }

        try {
            $head = fread($handle, max(1, $bytes));
        } finally {
            fclose($handle);
        }

        return $head === false ? '' : $head;
    }

    private function hasZipMagic(string $head): bool
    {
        return str_starts_with($head, self::ZIP_MAGIC)
            || str_starts_with($head, self::ZIP_EMPTY)
            || str_starts_with($head, self::ZIP_SPANNED);
    }

    private function isGeoPackageHeader(string $head): bool
    {
        if (\strlen($head) < 72) {
            return false;
        }
        /** @var array{1:int}|false $unpacked */
        $unpacked = unpack('N', substr($head, 68, 4));
        return $unpacked !== false && $unpacked[1] === self::GPKG_APP_ID;
    }

    /** First meaningful diagnostic line, trimmed and capped for a response body. */
    private function diagnostic(string $stderr, string $stdout): string
    {
        $source = trim($stderr) !== '' ? $stderr : $stdout;
        $lines  = preg_split('/\r?\n/', trim($source)) ?: [];
        $line   = '';
        foreach ($lines as $candidate) {
            if (trim($candidate) !== '') {
                $line = trim($candidate);
            }
        }
        // Drop GDAL's numeric severity prefixes such as "ERROR 4: ".
        $line = preg_replace('/^(ERROR|WARNING|FAILURE)\s+\d+:\s*/i', '', $line) ?? $line;

        return $line === '' ? 'no diagnostic output' : mb_substr($line, 0, 300);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $child = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($child) && !is_link($child)) {
                    $this->removeDirectory($child);
                } elseif (file_exists($child) || is_link($child)) {
                    unlink($child);
                }
            }
        }

        rmdir($dir);
    }
}
