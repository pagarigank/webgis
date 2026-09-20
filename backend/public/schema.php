<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config\Config;
$config = Config::load(__DIR__ . '/../.env');
$pdo = new PDO('pgsql:host=' . $config->get('DB_HOST') . ';port=' . $config->get('DB_PORT') . ';dbname=' . $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'app' AND table_name = 'gis_layers'");
print_r($stmt->fetchAll());
