<?php
declare(strict_types=1);

namespace Tests\Api;

use App\ImportExport\Application\ExportFeatureReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-127 — PII handling in exports.
 *
 * The rule under test: whether PII is visible is decided once, in
 * ExportFeatureReader, from the caller's permissions and the layer's is_pii
 * field flags. Every format then inherits that decision. So these tests
 * deliberately cover all five formats rather than one, because the risk is not
 * that redaction fails but that some format quietly re-introduces the raw value.
 *
 * The two test roles isolate the two independent decisions:
 *   EXPORT_NO_PII   may export, holds none of the PII permissions
 *   EXPORT_WITH_PII may export and holds title.view_owner
 *
 * Note that export.execute is unrelated to the PII permissions, which is the
 * whole point: a legitimate export operator is not automatically entitled to
 * see owner TINs.
 *
 * The negative assertions matter as much as the positive ones. A test that only
 * checks for "[REDACTED]" would still pass if the real value were emitted too.
 */
class ExportPiiTest extends TestCase
{
    private int $layerId = 8822;
    private string $featureA = '88222222-2222-4000-8000-000000000001';
    private const SECRET_TIN = '123-456-789';
    private const SECRET_NAME = 'Dela Cruz, Juan';
    private const NO_PII_ROLE = 'EXPORT_NO_PII';
    private const WITH_PII_ROLE = 'EXPORT_WITH_PII';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();

        $this->pdo()->exec("
            INSERT INTO app.gis_layers (id, code, name, geometry_type)
            VALUES ({$this->layerId}, 'TEST_EXPORT_PII', 'PII Layer', 'POLYGON')
            ON CONFLICT (id) DO UPDATE SET name = 'PII Layer'
        ");

        $this->pdo()->exec("
            INSERT INTO app.gis_layer_fields (layer_id, field_name, field_label, field_type, is_pii, sort_order)
            VALUES
                ({$this->layerId}, 'lot_label',  'Lot Label',  'text', false, 1),
                ({$this->layerId}, 'owner_tin',  'Owner TIN',  'text', true,  2),
                ({$this->layerId}, 'owner_name', 'Owner Name', 'text', true,  3)
            ON CONFLICT (layer_id, field_name) DO UPDATE SET is_pii = EXCLUDED.is_pii
        ");

        $this->ensureExportRoles();
        $this->grantLayerView([self::NO_PII_ROLE, self::WITH_PII_ROLE, 'ROLE_VIEWER']);

        $attributes = json_encode([
            'lot_label'  => 'Lot Manila',
            'owner_tin'  => self::SECRET_TIN,
            'owner_name' => self::SECRET_NAME,
        ], JSON_UNESCAPED_SLASHES);

        $this->pdo()->exec("
            INSERT INTO app.gis_features (id, layer_id, geom, attributes, status, version, created_by)
            VALUES (
                '{$this->featureA}', {$this->layerId},
                ST_GeomFromText('POLYGON((120.97 14.58, 120.99 14.58, 120.99 14.60, 120.97 14.60, 120.97 14.58))', 4326),
                '" . str_replace("'", "''", (string) $attributes) . "', 'ACTIVE', 1, 1
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->pdo()->exec("DELETE FROM app.users WHERE username LIKE 'pii%'");
        $this->pdo()->exec(
            "DELETE FROM app.roles WHERE code IN ('" . self::NO_PII_ROLE . "', '" . self::WITH_PII_ROLE . "')"
        );
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo()->exec("DELETE FROM audit.audit_logs WHERE entity_type = 'app.gis_features' AND entity_id IN ('{$this->featureA}', '{$this->layerId}')");
        $this->pdo()->exec("DELETE FROM audit.gis_feature_versions WHERE feature_id = '{$this->featureA}'");
        $this->pdo()->exec("DELETE FROM app.gis_features WHERE id = '{$this->featureA}'");
        $this->pdo()->exec("DELETE FROM app.export_jobs WHERE query_spec->>'layer_id' = '{$this->layerId}'");
        $this->pdo()->exec("DELETE FROM app.layer_permissions WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layer_fields WHERE layer_id = {$this->layerId}");
        $this->pdo()->exec("DELETE FROM app.gis_layers WHERE id = {$this->layerId}");
    }

    /**
     * Both roles get export.execute; only the second gets a PII permission.
     * A role can be missing here only if the permission catalogue changed, which
     * is worth failing loudly on rather than quietly skipping the test.
     */
    private function ensureExportRoles(): void
    {
        $this->authToken('piiuser', self::NO_PII_ROLE, 'Export Operator');
        $this->authToken('piiroot', self::WITH_PII_ROLE, 'Export Supervisor');

        $this->grantPermissions(self::NO_PII_ROLE, ['export.execute']);
        $this->grantPermissions(self::WITH_PII_ROLE, ['export.execute', 'title.view_owner']);

        $this->assertTrue(
            $this->hasPermission(self::NO_PII_ROLE, 'export.execute'),
            'the redaction role must be able to reach the export endpoint at all'
        );
        $this->assertFalse(
            $this->hasPermission(self::NO_PII_ROLE, 'title.view_owner'),
            'the redaction role must not hold a PII permission'
        );
    }

    /** @param list<string> $codes */
    private function grantPermissions(string $roleCode, array $codes): void
    {
        foreach ($codes as $code) {
            $this->pdo()->exec("
                INSERT INTO app.role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM app.roles r, app.permissions p
                WHERE r.code = '{$roleCode}' AND p.code = '{$code}'
                ON CONFLICT DO NOTHING
            ");
        }
    }

    private function hasPermission(string $roleCode, string $code): bool
    {
        return (bool) $this->stmt("
            SELECT 1 FROM app.role_permissions rp
            JOIN app.roles r ON r.id = rp.role_id
            JOIN app.permissions p ON p.id = rp.permission_id
            WHERE r.code = '{$roleCode}' AND p.code = '{$code}'
        ")->fetchColumn();
    }

    /** @param list<string> $roleCodes */
    private function grantLayerView(array $roleCodes): void
    {
        foreach ($roleCodes as $roleCode) {
            $this->pdo()->exec("
                INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
                SELECT {$this->layerId}, id, true, false, false, false, false
                FROM app.roles WHERE code = '{$roleCode}'
                ON CONFLICT (layer_id, role_id) DO UPDATE SET can_view = true
            ");
        }
    }

    // ------------------------------------------------------------------
    // Redaction applies to every format
    // ------------------------------------------------------------------

    /**
     * The bytes a caller would actually inspect.
     *
     * A Shapefile is a zip, so the attribute values live inside a deflated .dbf
     * and are invisible in the raw response. Reading the archive is what makes
     * the leak assertions meaningful for that format instead of vacuously true.
     *
     * @return array{payload: string, zipEntries: array<string, string>}
     */
    private function payloadOf(string $format, string $token): array
    {
        $raw = (string) $this->export($format, $token)->getBody();
        if ($format !== 'SHAPEFILE') {
            return ['payload' => $raw, 'zipEntries' => []];
        }

        $path = tempnam(sys_get_temp_dir(), 'piis');
        file_put_contents($path, $raw);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the shapefile export must be a readable zip');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return ['payload' => implode("\n", $entries), 'zipEntries' => $entries];
    }

    #[DataProvider('allFormats')]
    public function testExportWithoutPiiPermissionNeverReceivesTheValue(string $format): void
    {
        $token = $this->authToken('piiuser', self::NO_PII_ROLE, 'Export Operator')['token'];
        $res = $this->export($format, $token);
        $this->assertSame(200, $res->getStatusCode(), "{$format} should have been exported");

        $body = $this->payloadOf($format, $token)['payload'];

        // The negative assertion is the important one.
        $this->assertStringNotContainsString(self::SECRET_TIN, $body, "{$format} leaked the owner TIN");
        $this->assertStringNotContainsString(self::SECRET_NAME, $body, "{$format} leaked the owner name");

        $this->assertStringContainsString(ExportFeatureReader::REDACTED, $body, "{$format} must show the redaction sentinel");
        $this->assertStringContainsString('Lot Manila', $body, "{$format} must keep non-PII attributes");

        $this->assertSame('false', $res->getHeaderLine('X-Export-Pii-Included'));
        $this->assertSame('2', $res->getHeaderLine('X-Export-Redacted-Field-Count'));
    }

    #[DataProvider('allFormats')]
    public function testExportWithPiiPermissionReceivesTheValue(string $format): void
    {
        $token = $this->authToken('piiroot', self::WITH_PII_ROLE, 'Export Supervisor')['token'];
        $res = $this->export($format, $token);
        $this->assertSame(200, $res->getStatusCode());

        $this->assertStringContainsString(
            self::SECRET_TIN,
            $this->payloadOf($format, $token)['payload'],
            "{$format} should carry the owner TIN for a permitted caller"
        );
        $this->assertSame('true', $res->getHeaderLine('X-Export-Pii-Included'));
        $this->assertSame('0', $res->getHeaderLine('X-Export-Redacted-Field-Count'));
    }

    public static function allFormats(): array
    {
        return [
            'geojson'    => ['GEOJSON'],
            'csv'        => ['CSV'],
            'kml'        => ['KML'],
            'shapefile'  => ['SHAPEFILE'],
            'geopackage' => ['GEOPACKAGE'],
        ];
    }

    // ------------------------------------------------------------------
    // The permission decision itself
    // ------------------------------------------------------------------

    public function testRedactionFollowsTheIsPiiFlagNotTheColumnName(): void
    {
        $token = $this->authToken('piiuser', self::NO_PII_ROLE, 'Export Operator')['token'];

        $attributes = $this->attributes($token);
        $this->assertSame('Lot Manila', $attributes['lot_label'], 'an unflagged field must not be redacted');
        $this->assertSame(ExportFeatureReader::REDACTED, $attributes['owner_tin']);
        $this->assertSame(ExportFeatureReader::REDACTED, $attributes['owner_name']);

        // Clearing the flag must stop redacting that field, which is what proves
        // the decision is driven by the flag rather than by the field name.
        $this->pdo()->exec(
            "UPDATE app.gis_layer_fields SET is_pii = false
             WHERE layer_id = {$this->layerId} AND field_name = 'owner_tin'"
        );

        $this->assertSame(self::SECRET_TIN, $this->attributes($token)['owner_tin']);
        $this->assertSame('1', $this->export('GEOJSON', $token)->getHeaderLine('X-Export-Redacted-Field-Count'));
    }

    public function testProvenanceStatesWhetherPiiWasIncluded(): void
    {
        $operator = $this->authToken('piiuser', self::NO_PII_ROLE, 'Export Operator')['token'];

        $provenance = json_decode(
            (string) $this->export('GEOJSON', $operator)->getBody(),
            true
        )['x_webgis_export'];

        $this->assertFalse($provenance['pii_included']);
        $this->assertSame(2, $provenance['redacted_field_count']);

        // The human-readable form, used where there is nowhere to put structured
        // metadata, must say the same thing and must say which way it went.
        $this->assertStringContainsString(
            'PII: excluded, 2 field(s) redacted',
            (string) $this->export('CSV', $operator)->getBody()
        );

        $supervisor = $this->authToken('piiroot', self::WITH_PII_ROLE, 'Export Supervisor')['token'];
        $this->assertStringContainsString(
            'PII: included (caller is permitted)',
            (string) $this->export('CSV', $supervisor)->getBody()
        );
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    public function testRedactionIsRecordedInTheLedgerAndTheAuditTrail(): void
    {
        $operator = $this->authToken('piiuser', self::NO_PII_ROLE, 'Export Operator');
        $jobId = (int) $this->export('GEOJSON', $operator['token'])->getHeaderLine('X-Export-Job-Id');

        $job = $this->stmt("SELECT * FROM app.export_jobs WHERE id = {$jobId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($job, 'the export must leave a ledger row');
        $this->assertFalse((bool) $job['pii_included'], 'the ledger must record that PII was withheld');
        $this->assertSame(2, (int) $job['redacted_field_count']);
        $this->assertSame($operator['id'], (int) $job['requested_by']);

        $audit = $this->stmt(
            "SELECT new_values FROM audit.audit_logs
             WHERE action = 'EXPORT' AND entity_id = '{$this->layerId}'
             ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        $values = json_decode((string) $audit['new_values'], true);
        $this->assertFalse($values['pii_included']);
        $this->assertSame(2, $values['redacted_field_count']);
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    public function testExportIsRefusedWhenTheCallerCannotViewTheLayer(): void
    {
        // This caller holds export.execute, so the refusal can only come from the
        // layer check. getLayerCapabilities is row-based with no admin bypass,
        // so removing the grant is what takes can_view away.
        $supervisor = $this->authToken('piiroot', self::WITH_PII_ROLE, 'Export Supervisor');
        $this->revokeLayerView(self::WITH_PII_ROLE);

        $res = $this->export('GEOJSON', $supervisor['token']);
        $this->assertSame(403, $res->getStatusCode());

        $body = (string) $res->getBody();
        $this->assertStringNotContainsString(self::SECRET_TIN, $body, 'a refused export must not leak PII');
        $this->assertStringNotContainsString(self::SECRET_NAME, $body);
        $this->assertStringNotContainsString('Lot Manila', $body, 'a refused export must not leak any feature data');
    }

    public function testExportIsRefusedWithoutTheExportPermission(): void
    {
        // ROLE_VIEWER can view the layer but holds no export.execute, so this
        // refusal is about the export permission rather than the layer grant.
        $this->assertFalse($this->hasPermission('ROLE_VIEWER', 'export.execute'));

        $viewer = $this->authToken('piiview', 'ROLE_VIEWER', 'Viewer');
        $this->assertSame(403, $this->export('GEOJSON', $viewer['token'])->getStatusCode());
    }

    // ------------------------------------------------------------------

    /**
     * PDO::query() is typed as PDOStatement|false. On ERRMODE_EXCEPTION a false
     * return is unreachable, so asserting it keeps the assertions below on the
     * statement type rather than on a union.
     */
    private function stmt(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function revokeLayerView(string $roleCode): void
    {
        $this->pdo()->exec(
            "DELETE FROM app.layer_permissions
             WHERE layer_id = {$this->layerId}
               AND role_id = (SELECT id FROM app.roles WHERE code = '{$roleCode}')"
        );
    }

    /** @return array<string, mixed> */
    private function attributes(string $token): array
    {
        $decoded = json_decode((string) $this->export('GEOJSON', $token)->getBody(), true);
        $this->assertIsArray($decoded, 'expected a GeoJSON body');
        return $decoded['features'][0]['properties']['attributes'];
    }

    private function export(string $format, string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->handle(
            $this->createJsonRequest(
                'POST',
                "/api/v1/layers/{$this->layerId}/exports",
                ['format' => $format],
            )->withHeader('Authorization', 'Bearer ' . $token)
        );
    }
}
