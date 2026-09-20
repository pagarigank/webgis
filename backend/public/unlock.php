<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config\Config;
$config = Config::load(__DIR__ . '/../.env');
$pdo = new PDO('pgsql:host=' . $config->get('DB_HOST') . ';port=' . $config->get('DB_PORT') . ';dbname=' . $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("UPDATE app.users SET locked_until = NULL, failed_login_attempts = 0 WHERE username = 'sample_app_admin'");
$stmt->execute();

$stmt = $pdo->prepare("SELECT id FROM app.users WHERE username = 'sample_app_admin'");
$stmt->execute();
$user = $stmt->fetch();

if ($user) {
    $stmt = $pdo->prepare("SELECT id FROM app.roles WHERE code = 'SYS_ADMIN'");
    $stmt->execute();
    $role = $stmt->fetch();
    if ($role) {
        $pdo->prepare("INSERT INTO app.user_roles (user_id, role_id) VALUES (?, ?) ON CONFLICT DO NOTHING")->execute([$user['id'], $role['id']]);
        echo "Unlocked and granted SYS_ADMIN";
    }
}
