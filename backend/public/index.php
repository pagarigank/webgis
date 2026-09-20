<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use App\Core\Config\Config;
use App\Core\Http\Middleware\CorsMiddleware;
use App\Core\Http\Middleware\CsrfMiddleware;
use App\Core\Http\Middleware\RateLimitMiddleware;
use App\Core\Http\Middleware\RequestIdMiddleware;
use App\Core\Http\Middleware\SecurityHeadersMiddleware;
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

// 6. Error Middleware (catches routing/handler failures)
$errorMiddleware = $app->addErrorMiddleware(
    $config->get('APP_DEBUG', false),
    true,
    true
);
$errorMiddleware->setDefaultErrorHandler(
    new HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
);

// 7. Custom Middleware (executed LIFO in Slim; outermost runs first).
//    Inward order: RequestId -> SecurityHeaders -> Cors -> Csrf -> RateLimit.
//    Decorators sit OUTSIDE the error middleware so every response — including
//    4xx/5xx produced by the error handler — carries security headers, CORS
//    rules and X-Request-Id.
$app->add($container->get(RateLimitMiddleware::class));
$app->add($container->get(CsrfMiddleware::class));
$app->add($container->get(CorsMiddleware::class));
$app->add($container->get(SecurityHeadersMiddleware::class));
$app->add($container->get(RequestIdMiddleware::class));

// 8. Run App
$app->run();
