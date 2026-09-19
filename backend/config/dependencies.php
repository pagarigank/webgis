<?php
declare(strict_types=1);

use App\Core\Config\Config;

return [
    Config::class => function () {
        return Config::load(__DIR__ . '/../.env');
    }
];
