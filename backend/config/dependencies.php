<?php
declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Http\Middleware\AuthenticateMiddleware;
use App\Core\Http\Middleware\CorsMiddleware;
use App\Core\Http\Middleware\CsrfMiddleware;
use App\Core\Http\Middleware\RateLimitMiddleware;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
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
];
