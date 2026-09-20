<?php
require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/dependencies.php';
$pdoFactory = $config[\PDO::class];
$c = new \DI\Container();
$c->set(\App\Core\Config\Config::class, $config[\App\Core\Config\Config::class]());
$pdo = $pdoFactory($c);
$pdo->exec('DELETE FROM app.gis_layer_fields WHERE id = 888; DELETE FROM app.gis_layers WHERE id = 999;');
echo "Cleaned!";
