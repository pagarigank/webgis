<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config\Config;
use App\Auth\LoginService;
use App\Auth\MfaService;
use App\Auth\TokenService;

$config = Config::load(__DIR__ . '/../.env');
$pdo = new PDO('pgsql:host=' . $config->get('DB_HOST') . ';port=' . $config->get('DB_PORT') . ';dbname=' . $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$hasher = new \App\Auth\Hasher();
$tokenService = new TokenService($pdo, $config->get('JWT_SECRET'));
$mfaService = new MfaService($config->get('JWT_SECRET'), $config->get('MFA_ENCRYPTION_KEY'));
$loginService = new LoginService($pdo, $hasher, $tokenService, $mfaService);

try {
    $result = $loginService->attemptLogin('sample_app_admin', 'hash');
    print_r($result);
} catch (\Exception $e) {
    echo "Exception: " . get_class($e) . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getDetails')) {
        print_r($e->getDetails());
    }
}
