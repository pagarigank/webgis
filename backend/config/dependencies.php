<?php
declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Http\Middleware\AuthenticateMiddleware;
use App\Core\Http\Middleware\CorsMiddleware;
use App\Core\Http\Middleware\CsrfMiddleware;
use App\Core\Http\Middleware\RateLimitMiddleware;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
use App\Audit\AuditWriter;
use App\Users\UserAdminService;
use App\Auth\LoginService;
use App\Auth\MfaService;
use App\Auth\TokenService;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Shared helper: parse the CORS allow-list (comma-separated env value) into an
 * array of origins. Used by CorsMiddleware and CsrfMiddleware.
 */
$allowedOrigins = function (ContainerInterface $c): array {
    $raw = (string) $c->get(Config::class)->get('CORS_ALLOWED_ORIGINS', '');
    return array_values(array_filter(
        array_map(static fn (string $o): string => trim($o), explode(',', $raw)),
        static fn (string $o): bool => $o !== ''
    ));
};

return [
    Config::class => function () {
        return Config::load(__DIR__ . '/../.env');
    },
    
    PDO::class => function (ContainerInterface $c) {
        $config = $c->get(Config::class);
        $host = $config->get('DB_HOST');
        $port = $config->get('DB_PORT');
        $name = $config->get('DB_NAME');
        $user = $config->get('DB_USER');
        $pass = $config->get('DB_PASS');

        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        return $pdo;
    },

    'jwtSecret' => function (ContainerInterface $c) {
        return $c->get(Config::class)->get('JWT_SECRET');
    },

    'mfaEncryptionKey' => function (ContainerInterface $c) {
        return $c->get(Config::class)->get('MFA_ENCRYPTION_KEY', '');
    },

    TokenService::class => \DI\autowire(TokenService::class)
        ->constructorParameter('jwtSecret', \DI\get('jwtSecret')),

    MfaService::class => \DI\autowire(MfaService::class)
        ->constructorParameter('jwtSecret', \DI\get('jwtSecret'))
        ->constructorParameter('encryptionKey', \DI\get('mfaEncryptionKey')),

    LoginService::class => \DI\autowire(LoginService::class)
        ->constructorParameter('mfaService', \DI\get(MfaService::class)),

    UserAdminService::class => \DI\autowire(UserAdminService::class)
        ->constructorParameter('mfaService', \DI\get(MfaService::class)),

    // AuthController needs the JWT secret for its token reads.
    \App\Auth\Http\AuthController::class => \DI\autowire(\App\Auth\Http\AuthController::class)
        ->constructorParameter('jwtSecret', \DI\get('jwtSecret')),

    CacheInterface::class => function () {
        return new Psr16Cache(new ArrayAdapter());
    },

    AuthenticateMiddleware::class => \DI\autowire(AuthenticateMiddleware::class)
        ->constructorParameter('jwtSecret', \DI\get('jwtSecret')),

    CorsMiddleware::class => function (ContainerInterface $c) use ($allowedOrigins) {
        return new CorsMiddleware($allowedOrigins($c));
    },

    SecurityHeadersMiddleware::class => fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(),

    CsrfMiddleware::class => function (ContainerInterface $c) use ($allowedOrigins) {
        return new CsrfMiddleware($allowedOrigins($c));
    },

    RateLimitMiddleware::class => function (ContainerInterface $c) {
        return new RateLimitMiddleware($c->get(PDO::class), $c->get('jwtSecret'));
    },
    
    \App\GIS\Domain\MigrationGeneratorService::class => function (ContainerInterface $c) {
        return new \App\GIS\Domain\MigrationGeneratorService(__DIR__ . '/../database/migrations/');
    },

    \App\RBAC\FeatureScopeResolver::class => \DI\autowire(\App\RBAC\FeatureScopeResolver::class),

    \App\GIS\Http\GisFeatureController::class => \DI\autowire(\App\GIS\Http\GisFeatureController::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class))
        ->constructorParameter('exports', \DI\get(\App\ImportExport\Application\ExportService::class)),

    \App\GIS\Domain\SpatialMeasure::class => \DI\autowire(\App\GIS\Domain\SpatialMeasure::class),
    \App\GIS\Domain\IdentifyPopup::class => \DI\autowire(\App\GIS\Domain\IdentifyPopup::class),
    \App\GIS\Http\SpatialToolController::class => \DI\autowire(\App\GIS\Http\SpatialToolController::class)
        ->constructorParameter('measure', \DI\get(\App\GIS\Domain\SpatialMeasure::class))
        ->constructorParameter('identifyPopup', \DI\get(\App\GIS\Domain\IdentifyPopup::class)),

    \App\GIS\Domain\SpatialQuery::class => \DI\autowire(\App\GIS\Domain\SpatialQuery::class),
    \App\GIS\Http\SpatialQueryController::class => \DI\autowire(\App\GIS\Http\SpatialQueryController::class)
        ->constructorParameter('query', \DI\get(\App\GIS\Domain\SpatialQuery::class)),

    \App\Survey\Http\TechnicalDescriptionController::class => \DI\autowire(\App\Survey\Http\TechnicalDescriptionController::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class)),

    // ---- Phase 11 Computation Engine & CRS Transformations ----
    \App\Core\Crs\CoordinateTransformationService::class => \DI\autowire(\App\Core\Crs\CoordinateTransformationService::class),
    \App\Core\Crs\Http\CoordinateTransformationController::class => \DI\autowire(\App\Core\Crs\Http\CoordinateTransformationController::class),

    \App\Survey\Domain\TraverseComputer::class => \DI\autowire(\App\Survey\Domain\TraverseComputer::class),
    \App\Survey\Domain\ClosureCalculator::class => \DI\autowire(\App\Survey\Domain\ClosureCalculator::class),
    \App\Survey\Domain\AreaCalculator::class => \DI\autowire(\App\Survey\Domain\AreaCalculator::class),
    \App\Survey\Domain\ComputeCrsGuard::class => \DI\autowire(\App\Survey\Domain\ComputeCrsGuard::class),

    \App\Survey\Application\SurveyComputationService::class => \DI\autowire(\App\Survey\Application\SurveyComputationService::class),
    \App\Survey\Http\ComputationController::class => \DI\autowire(\App\Survey\Http\ComputationController::class),

    // ---- TASK-126 Control point bulk import (CSV) ----
    // Shared by the single-point CRUD endpoint and the CSV import so both apply
    // the identical CRS/derivation/area-of-use rules.
    \App\Survey\Application\ControlPointCoordinateResolver::class => \DI\autowire(\App\Survey\Application\ControlPointCoordinateResolver::class),
    \App\Survey\Http\ControlPointController::class => \DI\autowire(\App\Survey\Http\ControlPointController::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class))
        ->constructorParameter('resolver', \DI\get(\App\Survey\Application\ControlPointCoordinateResolver::class)),
    \App\Survey\Application\ControlPointImportService::class => \DI\autowire(\App\Survey\Application\ControlPointImportService::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class))
        ->constructorParameter('resolver', \DI\get(\App\Survey\Application\ControlPointCoordinateResolver::class)),
    \App\Survey\Http\ControlPointImportController::class => \DI\autowire(\App\Survey\Http\ControlPointImportController::class)
        ->constructorParameter('service', \DI\get(\App\Survey\Application\ControlPointImportService::class)),

    // ---- Phase 12 Survey Validation & Submission Guards ----
    \App\Parcels\Domain\OverlapDetector::class => \DI\autowire(\App\Parcels\Domain\OverlapDetector::class),
    \App\Survey\Application\SurveyValidationService::class => \DI\autowire(\App\Survey\Application\SurveyValidationService::class),
    \App\Survey\Http\ValidationController::class => \DI\autowire(\App\Survey\Http\ValidationController::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class)),
    \App\Parcels\Http\ParcelController::class => \DI\autowire(\App\Parcels\Http\ParcelController::class)
        ->constructorParameter('validationService', \DI\get(\App\Survey\Application\SurveyValidationService::class))
        ->constructorParameter('overlapDetector', \DI\get(\App\Parcels\Domain\OverlapDetector::class))
        ->constructorParameter('workflowEngine', \DI\get(\App\Parcels\Workflow\WorkflowEngine::class)),

    // ---- Phase 13 Workflow engine (TASK-100) ----
    \App\Parcels\Workflow\WorkflowEngine::class => \DI\autowire(\App\Parcels\Workflow\WorkflowEngine::class)
        ->constructorParameter('validationService', \DI\get(\App\Survey\Application\SurveyValidationService::class))
        ->constructorParameter('audit', \DI\get(\App\Audit\AuditWriter::class)),
    \App\Parcels\Http\WorkflowController::class => \DI\autowire(\App\Parcels\Http\WorkflowController::class),

    // ---- Phase 15 Split, consolidation, lineage (TASK-111..115) ----
    \App\Parcels\Domain\SplitValidator::class => \DI\autowire(\App\Parcels\Domain\SplitValidator::class),
    \App\Parcels\Application\SplitService::class => \DI\autowire(\App\Parcels\Application\SplitService::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class)),
    \App\Parcels\Http\SplitController::class => \DI\autowire(\App\Parcels\Http\SplitController::class),
    \App\Parcels\Domain\ConsolidationValidator::class => \DI\autowire(\App\Parcels\Domain\ConsolidationValidator::class),
    \App\Parcels\Application\ConsolidationService::class => \DI\autowire(\App\Parcels\Application\ConsolidationService::class)
        ->constructorParameter('audit', \DI\get(AuditWriter::class)),
    \App\Parcels\Http\ConsolidationController::class => \DI\autowire(\App\Parcels\Http\ConsolidationController::class),

    // ---- Phase 14 History, versioning UI, documents ----
    \App\Parcels\Domain\VersionDiffService::class => \DI\autowire(\App\Parcels\Domain\VersionDiffService::class),
    \App\Parcels\Http\HistoryTimelineController::class => \DI\autowire(\App\Parcels\Http\HistoryTimelineController::class),

    // Documents storage: base dir OUTSIDE the served web root, configurable.
    // The default matches the `documents` named volume mounted by docker-compose
    // at /var/www/html/storage/documents; the previous /var/www/document-storage
    // default sat outside every mount under a root-owned parent, so uploads
    // failed with "Document storage directory is not writable".
    'documents.storage_dir' => \DI\env('DOCUMENTS_STORAGE_DIR', '/var/www/html/storage/documents'),
    \App\Documents\DocumentService::class => \DI\autowire(\App\Documents\DocumentService::class)
        ->constructorParameter('storageDir', \DI\get('documents.storage_dir'))
        ->constructorParameter('signingKey', \DI\get('jwtSecret')),
    \App\Documents\Http\DocumentController::class => \DI\autowire(\App\Documents\Http\DocumentController::class),

    // ---- Phase 16 Import/export (TASK-121 OGR adapter, TASK-122 import jobs) ----
    // The OGR adapter's PDO transaction guard is intentionally left unwired for
    // now: AuthenticateMiddleware wraps every authenticated request in a
    // transaction, and TASK-122 parses imports inline. When parsing moves to a
    // background job (TASK-124+), pass PDO here so the guard is enforced.
    \App\Core\Geo\OgrAdapter::class => \DI\autowire(\App\Core\Geo\OgrAdapter::class),
    \App\ImportExport\Domain\GeoJsonRowReader::class => \DI\autowire(\App\ImportExport\Domain\GeoJsonRowReader::class),
    \App\ImportExport\Domain\CsvRowReader::class => \DI\autowire(\App\ImportExport\Domain\CsvRowReader::class),
    // TASK-124 — OGR-backed reader for Shapefile/KML/GeoPackage/DXF.
    \App\ImportExport\Domain\OgrRowReader::class => \DI\autowire(\App\ImportExport\Domain\OgrRowReader::class),
    // TASK-125 — raw DXF scanner for the dropped-entity report (FR-250).
    \App\ImportExport\Domain\DxfEntityScanner::class => \DI\autowire(\App\ImportExport\Domain\DxfEntityScanner::class),
    \App\GIS\Domain\AttributeValidator::class => \DI\autowire(\App\GIS\Domain\AttributeValidator::class),
    \App\ImportExport\Application\ImportJobService::class => \DI\autowire(\App\ImportExport\Application\ImportJobService::class)
        ->constructorParameter('audit', \DI\get(\App\Audit\AuditWriter::class)),
    \App\ImportExport\Http\ImportController::class => \DI\autowire(\App\ImportExport\Http\ImportController::class),

    // ---- TASK-127 Export service ----
    // The scope reader is the single place PII visibility and target CRS are
    // decided, so all five writers inherit the same answer.
    \App\ImportExport\Application\ExportFeatureReader::class => \DI\autowire(\App\ImportExport\Application\ExportFeatureReader::class),
    \App\ImportExport\Domain\Writer\GeoJsonExportWriter::class => \DI\autowire(\App\ImportExport\Domain\Writer\GeoJsonExportWriter::class),
    \App\ImportExport\Domain\Writer\CsvExportWriter::class => \DI\autowire(\App\ImportExport\Domain\Writer\CsvExportWriter::class),
    \App\ImportExport\Domain\Writer\KmlExportWriter::class => \DI\autowire(\App\ImportExport\Domain\Writer\KmlExportWriter::class),

    // OGR writes the two binary formats. Deliberately built without the PDO
    // transaction guard: an export runs ogr2ogr inline, while
    // AuthenticateMiddleware still holds the request transaction open, and
    // refusing to export for that reason would be wrong.
    'export.writer.shapefile' => static function (ContainerInterface $c) {
        return new \App\ImportExport\Domain\Writer\OgrBinaryExportWriter(
            $c->get(\App\Core\Geo\OgrAdapter::class),
            'SHAPEFILE',
        );
    },
    'export.writer.geopackage' => static function (ContainerInterface $c) {
        return new \App\ImportExport\Domain\Writer\OgrBinaryExportWriter(
            $c->get(\App\Core\Geo\OgrAdapter::class),
            'GEOPACKAGE',
        );
    },

    // The writer map is keyed by format code: that map *is* the list of
    // supported formats, so adding a writer needs no change here.
    \App\ImportExport\Application\ExportService::class => static function (ContainerInterface $c) {
        return new \App\ImportExport\Application\ExportService(
            $c->get(\PDO::class),
            $c->get(\App\Audit\AuditWriter::class),
            $c->get(\App\Core\Crs\CoordinateTransformationService::class),
            $c->get(\App\ImportExport\Application\ExportFeatureReader::class),
            [
                'GEOJSON'    => $c->get(\App\ImportExport\Domain\Writer\GeoJsonExportWriter::class),
                'CSV'        => $c->get(\App\ImportExport\Domain\Writer\CsvExportWriter::class),
                'KML'        => $c->get(\App\ImportExport\Domain\Writer\KmlExportWriter::class),
                'SHAPEFILE'  => $c->get('export.writer.shapefile'),
                'GEOPACKAGE' => $c->get('export.writer.geopackage'),
            ],
        );
    },
    \App\ImportExport\Http\ExportController::class => \DI\autowire(\App\ImportExport\Http\ExportController::class),
];

