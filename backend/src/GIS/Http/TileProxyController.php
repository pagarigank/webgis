<?php
declare(strict_types=1);

namespace App\GIS\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Core\Error\ApiError;
use DateTimeImmutable;
use Slim\Psr7\Stream;

class TileProxyController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function proxy(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $z   = (int) $args['z'];
        $x   = (int) $args['x'];
        $y   = (int) $args['y'];

        // Retrieve provider details
        $stmt = $this->pdo->prepare("SELECT * FROM app.basemap_providers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$provider) {
            throw new ApiError('NOT_FOUND', 'Basemap provider not found.', 404);
        }

        if (!$provider['is_enabled']) {
            throw new ApiError('FORBIDDEN', 'Basemap provider is disabled.', 403);
        }

        if ($provider['license_type'] === 'UNLICENSED') {
            throw new ApiError('FORBIDDEN', 'Basemap provider is unlicensed.', 403);
        }

        if (!empty($provider['license_expires_on'])) {
            $expiresOn = new DateTimeImmutable($provider['license_expires_on']);
            $now       = new DateTimeImmutable();
            if ($expiresOn < $now) {
                throw new ApiError('FORBIDDEN', 'Basemap provider license has expired.', 403);
            }
        }

        // Construct target URL from the provider's template
        $url = $provider['url_template'];

        // Inject API Key if required
        if ($provider['requires_api_key']) {
            $envName = $provider['api_key_env_name'];
            $apiKey  = getenv($envName) ?: ($_ENV[$envName] ?? null);
            if (!$apiKey) {
                throw new ApiError('INTERNAL_ERROR', 'API key not configured for provider.', 500);
            }
            $url = str_replace('{key}', $apiKey, $url);
        }

        // Replace XYZ tile coordinates in the upstream URL template
        $url = str_replace(['{z}', '{x}', '{y}'], [$z, $x, $y], $url);

        // Fetch the tile from the upstream provider
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: WebGIS-TileProxy/1.0\r\n",
            ],
        ]);

        $image = @file_get_contents($url, false, $context);

        if ($image === false) {
            $headers = $http_response_header ?? [];
            $status  = $headers[0] ?? '';
            if (strpos($status, '404') !== false) {
                $response = $response->withStatus(404);
                return $response;
            }
            throw new ApiError('BAD_GATEWAY', 'Failed to fetch tile from upstream provider.', 502);
        }

        // Determine content type from response headers
        $contentType = 'image/png'; // default
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, 13));
                    break;
                }
            }
        }

        // Write to a temp stream and return it
        $tempStream = fopen('php://temp', 'r+');
        fwrite($tempStream, $image);
        rewind($tempStream);

        $response = $response
            ->withBody(new Stream($tempStream))
            ->withHeader('Content-Type', $contentType);

        // Cache headers — only cacheable for open licences that permit it
        $cacheableTypes = ['OPEN_ODBL', 'GOVERNMENT_GRANT'];
        if (in_array($provider['license_type'], $cacheableTypes, true)
            && (int) $provider['cache_ttl_seconds'] > 0) {
            $response = $response->withHeader(
                'Cache-Control',
                'public, max-age=' . (int) $provider['cache_ttl_seconds']
            );
        } else {
            $response = $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        }

        return $response;
    }
}
