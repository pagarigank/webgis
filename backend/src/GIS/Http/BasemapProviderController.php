<?php
declare(strict_types=1);

namespace App\GIS\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Core\Http\Response\Envelope;
use App\Core\Error\ApiError;
use DateTimeImmutable;

class BasemapProviderController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get all active basemaps for the public/authenticated user map view.
     * Excludes sensitive fields like api_key_env_name.
     */
    public function listPublic(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM app.basemap_providers ORDER BY display_order ASC");
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $publicProviders = [];
        $now = new DateTimeImmutable();

        foreach ($providers as $provider) {
            if (!$provider['is_enabled']) {
                continue;
            }

            if ($provider['license_type'] === 'UNLICENSED') {
                continue;
            }

            if (!empty($provider['license_expires_on'])) {
                $expiresOn = new DateTimeImmutable($provider['license_expires_on']);
                if ($expiresOn < $now) {
                    continue; // Expired
                }
            }

            // Remove sensitive fields
            unset($provider['api_key_env_name']);

            // Parse booleans correctly from postgres strings/ints
            $provider['is_enabled'] = (bool)$provider['is_enabled'];
            $provider['is_default'] = (bool)$provider['is_default'];
            $provider['requires_api_key'] = (bool)$provider['requires_api_key'];
            $provider['proxy_required'] = (bool)$provider['proxy_required'];
            
            $provider['status'] = 'ACTIVE';

            $publicProviders[] = $provider;
        }

        return Envelope::success($response, $publicProviders);
    }

    /**
     * Admin: Get all basemaps (including disabled ones).
     */
    public function listAdmin(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM app.basemap_providers ORDER BY display_order ASC");
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($providers as &$provider) {
            $provider['is_enabled'] = (bool)$provider['is_enabled'];
            $provider['is_default'] = (bool)$provider['is_default'];
            $provider['requires_api_key'] = (bool)$provider['requires_api_key'];
            $provider['proxy_required'] = (bool)$provider['proxy_required'];
        }

        return Envelope::success($response, $providers);
    }

    /**
     * Admin: Create a new basemap provider.
     */
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        
        if (empty($data['code']) || empty($data['name']) || empty($data['provider_type']) || empty($data['license_type'])) {
            throw new ApiError('VALIDATION_FAILED', 'code, name, provider_type, and license_type are required', 400);
        }
        
        if (!empty($data['is_enabled']) && $data['license_type'] === 'UNLICENSED') {
            throw new ApiError('VALIDATION_FAILED', 'Cannot enable an unlicensed basemap', 422);
        }

        if (!empty($data['is_enabled']) && empty($data['attribution_html'])) {
            throw new ApiError('VALIDATION_FAILED', 'Attribution is required when enabling basemap', 422);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO app.basemap_providers (
                code, name, provider_type, service_url, url_template, layer_name,
                matrix_set, format, srid, attribution_html, attribution_url,
                license_type, license_reference, license_expires_on, license_notes,
                requires_api_key, api_key_env_name, proxy_required, cache_ttl_seconds,
                min_zoom, max_zoom, is_enabled, is_default, display_order, created_at, updated_at
            ) VALUES (
                :code, :name, :provider_type, :service_url, :url_template, :layer_name,
                :matrix_set, :format, :srid, :attribution_html, :attribution_url,
                :license_type, :license_reference, :license_expires_on, :license_notes,
                :requires_api_key, :api_key_env_name, :proxy_required, :cache_ttl_seconds,
                :min_zoom, :max_zoom, :is_enabled, :is_default, :display_order, NOW(), NOW()
            ) RETURNING *
        ");

        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'provider_type' => $data['provider_type'],
            'service_url' => $data['service_url'] ?? null,
            'url_template' => $data['url_template'] ?? null,
            'layer_name' => $data['layer_name'] ?? null,
            'matrix_set' => $data['matrix_set'] ?? null,
            'format' => $data['format'] ?? null,
            'srid' => $data['srid'] ?? 3857,
            'attribution_html' => $data['attribution_html'] ?? '',
            'attribution_url' => $data['attribution_url'] ?? null,
            'license_type' => $data['license_type'],
            'license_reference' => $data['license_reference'] ?? null,
            'license_expires_on' => $data['license_expires_on'] ?? null,
            'license_notes' => $data['license_notes'] ?? null,
            'requires_api_key' => $data['requires_api_key'] ?? false ? 1 : 0,
            'api_key_env_name' => $data['api_key_env_name'] ?? null,
            'proxy_required' => $data['proxy_required'] ?? false ? 1 : 0,
            'cache_ttl_seconds' => $data['cache_ttl_seconds'] ?? 0,
            'min_zoom' => $data['min_zoom'] ?? null,
            'max_zoom' => $data['max_zoom'] ?? null,
            'is_enabled' => $data['is_enabled'] ?? false ? 1 : 0,
            'is_default' => $data['is_default'] ?? false ? 1 : 0,
            'display_order' => $data['display_order'] ?? 100
        ]);

        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $provider['is_enabled'] = (bool)$provider['is_enabled'];
        $provider['is_default'] = (bool)$provider['is_default'];
        $provider['requires_api_key'] = (bool)$provider['requires_api_key'];
        $provider['proxy_required'] = (bool)$provider['proxy_required'];

        return Envelope::success($response, $provider, 201);
    }

    /**
     * Admin: Update an existing basemap provider.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $check = $this->pdo->prepare("SELECT * FROM app.basemap_providers WHERE id = :id");
        $check->execute(['id' => $id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            throw new ApiError('NOT_FOUND', 'Basemap provider not found', 404);
        }

        $isEnabled = isset($data['is_enabled']) ? (bool)$data['is_enabled'] : (bool)$existing['is_enabled'];
        $licenseType = $data['license_type'] ?? $existing['license_type'];
        $attribution = $data['attribution_html'] ?? $existing['attribution_html'];

        if ($isEnabled && $licenseType === 'UNLICENSED') {
            throw new ApiError('VALIDATION_FAILED', 'Cannot enable an unlicensed basemap', 422);
        }

        if ($isEnabled && empty($attribution)) {
            throw new ApiError('VALIDATION_FAILED', 'Attribution is required when enabling basemap', 422);
        }

        $stmt = $this->pdo->prepare("
            UPDATE app.basemap_providers SET 
                name = COALESCE(:name, name),
                provider_type = COALESCE(:provider_type, provider_type),
                service_url = :service_url,
                url_template = :url_template,
                layer_name = :layer_name,
                matrix_set = :matrix_set,
                format = :format,
                srid = COALESCE(:srid, srid),
                attribution_html = COALESCE(:attribution_html, attribution_html),
                attribution_url = :attribution_url,
                license_type = COALESCE(:license_type, license_type),
                license_reference = :license_reference,
                license_expires_on = :license_expires_on,
                license_notes = :license_notes,
                requires_api_key = COALESCE(:requires_api_key, requires_api_key),
                api_key_env_name = :api_key_env_name,
                proxy_required = COALESCE(:proxy_required, proxy_required),
                cache_ttl_seconds = COALESCE(:cache_ttl_seconds, cache_ttl_seconds),
                min_zoom = :min_zoom,
                max_zoom = :max_zoom,
                is_enabled = COALESCE(:is_enabled, is_enabled),
                is_default = COALESCE(:is_default, is_default),
                display_order = COALESCE(:display_order, display_order),
                updated_at = NOW()
            WHERE id = :id
            RETURNING *
        ");

        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? null,
            'provider_type' => $data['provider_type'] ?? null,
            'service_url' => array_key_exists('service_url', $data) ? $data['service_url'] : $existing['service_url'],
            'url_template' => array_key_exists('url_template', $data) ? $data['url_template'] : $existing['url_template'],
            'layer_name' => array_key_exists('layer_name', $data) ? $data['layer_name'] : $existing['layer_name'],
            'matrix_set' => array_key_exists('matrix_set', $data) ? $data['matrix_set'] : $existing['matrix_set'],
            'format' => array_key_exists('format', $data) ? $data['format'] : $existing['format'],
            'srid' => $data['srid'] ?? null,
            'attribution_html' => $data['attribution_html'] ?? null,
            'attribution_url' => array_key_exists('attribution_url', $data) ? $data['attribution_url'] : $existing['attribution_url'],
            'license_type' => $data['license_type'] ?? null,
            'license_reference' => array_key_exists('license_reference', $data) ? $data['license_reference'] : $existing['license_reference'],
            'license_expires_on' => array_key_exists('license_expires_on', $data) ? $data['license_expires_on'] : $existing['license_expires_on'],
            'license_notes' => array_key_exists('license_notes', $data) ? $data['license_notes'] : $existing['license_notes'],
            'requires_api_key' => isset($data['requires_api_key']) ? ($data['requires_api_key'] ? 1 : 0) : null,
            'api_key_env_name' => array_key_exists('api_key_env_name', $data) ? $data['api_key_env_name'] : $existing['api_key_env_name'],
            'proxy_required' => isset($data['proxy_required']) ? ($data['proxy_required'] ? 1 : 0) : null,
            'cache_ttl_seconds' => $data['cache_ttl_seconds'] ?? null,
            'min_zoom' => array_key_exists('min_zoom', $data) ? $data['min_zoom'] : $existing['min_zoom'],
            'max_zoom' => array_key_exists('max_zoom', $data) ? $data['max_zoom'] : $existing['max_zoom'],
            'is_enabled' => isset($data['is_enabled']) ? ($data['is_enabled'] ? 1 : 0) : null,
            'is_default' => isset($data['is_default']) ? ($data['is_default'] ? 1 : 0) : null,
            'display_order' => $data['display_order'] ?? null
        ]);

        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        $provider['is_enabled'] = (bool)$provider['is_enabled'];
        $provider['is_default'] = (bool)$provider['is_default'];
        $provider['requires_api_key'] = (bool)$provider['requires_api_key'];
        $provider['proxy_required'] = (bool)$provider['proxy_required'];

        return Envelope::success($response, $provider);
    }

    /**
     * Admin: Delete a basemap provider.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        
        $stmt = $this->pdo->prepare("DELETE FROM app.basemap_providers WHERE id = :id");
        $stmt->execute(['id' => $id]);
        
        if ($stmt->rowCount() === 0) {
            throw new ApiError('NOT_FOUND', 'Basemap provider not found', 404);
        }

        return Envelope::success($response, null, 204);
    }
}
