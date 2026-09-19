<?php
declare(strict_types=1);

namespace Tests;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use App\Core\Config\Config;

class TestCase extends PHPUnitTestCase
{
    protected function getAppInstance(): App
    {
        $containerBuilder = new ContainerBuilder();
        
        $containerBuilder->addDefinitions(__DIR__ . '/../config/dependencies.php');
        
        $container = $containerBuilder->build();
        
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        
        (require __DIR__ . '/../config/routes.php')($app);
        
        $app->addRoutingMiddleware();
        $app->add(new \App\Core\Http\Middleware\CorsMiddleware());
        $app->add(new \App\Core\Http\Middleware\RequestIdMiddleware());
        
        $errorMiddleware = $app->addErrorMiddleware(true, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            new \App\Core\Http\Handlers\HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory())
        );
        
        return $app;
    }

    protected function createRequest(string $method, string $path): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        return $factory->createServerRequest($method, $path);
    }
}
