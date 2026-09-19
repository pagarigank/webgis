<?php

require __DIR__ . '/vendor/autoload.php';

use App\Core\Config\Config;

$config = Config::load(__DIR__ . '/.env');

return
[
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/../database/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/../database/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'pgsql',
            'host' => $config->get('DB_HOST'),
            'name' => $config->get('DB_NAME'),
            'user' => $config->get('DB_USER'),
            'pass' => $config->get('DB_PASS'),
            'port' => $config->get('DB_PORT'),
            'charset' => 'utf8',
        ],
        'testing' => [
            'adapter' => 'pgsql',
            'host' => $config->get('DB_HOST'),
            'name' => $config->get('DB_NAME') . '_test',
            'user' => $config->get('DB_USER'),
            'pass' => $config->get('DB_PASS'),
            'port' => $config->get('DB_PORT'),
            'charset' => 'utf8',
        ]
    ],
    'version_order' => 'creation'
];
