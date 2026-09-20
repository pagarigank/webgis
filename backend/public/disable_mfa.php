<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config\Config;

$config = Config::load(__DIR__ . '/../.env');
$pdo = new PDO('pgsql:host=' . $config->get('DB_HOST') . ';port=' . $config->get('DB_PORT') . ';dbname=' . $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("UPDATE app.roles SET requires_mfa = false WHERE code = 'SYS_ADMIN'");
$stmt->execute();
echo "SYS_ADMIN MFA disabled for testing.";
