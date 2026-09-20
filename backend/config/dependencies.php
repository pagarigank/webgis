<?php
declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Http\Middleware\AuthenticateMiddleware;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

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
];
