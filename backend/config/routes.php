<?php
declare(strict_types=1);

use Slim\App;
use App\Core\Http\Controllers\HealthController;

return function (App $app) {
    $app->group('/api/v1', function ($group) {
        $group->get('/health', HealthController::class);
    });
};
