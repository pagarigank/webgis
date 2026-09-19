<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use App\Core\Config\Config;
use App\Core\Http\Middleware\CorsMiddleware;
use App\Core\Http\Middleware\RequestIdMiddleware;
use App\Core\Http\Handlers\HttpErrorHandler;

require __DIR__ . '/../vendor/autoload.php';

// 1. Load configuration
$config = Config::load(__DIR__ . '/../.env');

// 2. Build DI Container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require __DIR__ . '/../config/dependencies.php');
$container = $containerBuilder->build();

// 3. Instantiate App
AppFactory::setContainer($container);
$app = AppFactory::create();

// 4. Register Routes
(require __DIR__ . '/../config/routes.php')($app);

// 5. Add Routing Middleware
$app->addRoutingMiddleware();

// 6. Add Custom Middleware (executed LIFO in Slim)
$app->add(new CorsMiddleware());
$app->add(new RequestIdMiddleware());

// 7. Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(
    $config->get('APP_DEBUG', false),
    true,
    true
);
$errorMiddleware->setDefaultErrorHandler(
    new HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
);

// 8. Run App
$app->run();
