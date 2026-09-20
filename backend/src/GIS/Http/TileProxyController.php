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
    private PDO ;

    public function __construct(PDO )
    {
        ->pdo = ;
    }

    public function proxy(Request , Response , array ): Response
    {
         = (int) ['id'];
         = ['z'];
         = ['x'];
         = ['y'];

        // Retrieve provider details
         = ->pdo->prepare("SELECT * FROM app.basemap_providers WHERE id = :id");
        ->execute([':id' => ]);
         = ->fetch(PDO::FETCH_ASSOC);

        if (!) {
            throw new ApiError('NOT_FOUND', 'Basemap provider not found.', 404);
        }

        if (!['is_enabled']) {
            throw new ApiError('FORBIDDEN', 'Basemap provider is disabled.', 403);
        }

        if (['license_type'] === 'UNLICENSED') {
            throw new ApiError('FORBIDDEN', 'Basemap provider is unlicensed.', 403);
        }

        if (!empty(['license_expires_on'])) {
             = new DateTimeImmutable(['license_expires_on']);
             = new DateTimeImmutable();
            if ( < ) {
                throw new ApiError('FORBIDDEN', 'Basemap provider license has expired.', 403);
            }
        }

        // Construct target URL
         = ['url_template'];
        
        // Inject API Key if required
        if (['requires_api_key']) {
             = ['api_key_env_name'];
             = getenv() ?: [] ?? null;
            if (!) {
                throw new ApiError('INTERNAL_ERROR', 'API key not configured for provider.', 500);
            }
             = str_replace('{key}', , );
        }

        // Replace XYZ
         = str_replace(['{z}', '{x}', '{y}'], [, , ], );

        // Fetch image
        // Suppress warnings from file_get_contents to handle 404s gracefully
         = stream_context_create([
            'http' => [
                'timeout' => 5, // 5 seconds timeout
                'header' => 'User-Agent: WebGIS-TileProxy/1.0\r\n'
            ]
        ]);
        
         = @file_get_contents(, false, );
        
        if ( === false) {
            // Check headers to see if it was a 404 or something else
             = [0] ?? '';
            if (strpos(, '404') !== false) {
                // Return a transparent 1x1 png or 404
                 = ->withStatus(404);
                return ;
            }
            throw new ApiError('BAD_GATEWAY', 'Failed to fetch tile from upstream provider.', 502);
        }

        // Determine content type from headers
         = 'image/png'; // default
        if (!empty()) {
            foreach ( as ) {
                if (stripos(, 'Content-Type:') === 0) {
                     = trim(substr(, 13));
                    break;
                }
            }
        }

        // Write to stream
         = fopen('php://temp', 'r+');
        fwrite(, );
        rewind();
        
         = ->withBody(new Stream())
            ->withHeader('Content-Type', );

        // Cache headers
        // Only allow caching for specific open licenses, otherwise force no-cache
         = ['OPEN_ODBL', 'GOVERNMENT_GRANT'];
        if (in_array(['license_type'], ) && ['cache_ttl_seconds'] > 0) {
             = ->withHeader('Cache-Control', 'public, max-age=' . ['cache_ttl_seconds']);
        } else {
             = ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        }

        return ;
    }
}
