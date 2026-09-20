<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config\Config;
$config = Config::load(__DIR__ . '/../.env');
$pdo = new PDO('pgsql:host=' . $config->get('DB_HOST') . ';port=' . $config->get('DB_PORT') . ';dbname=' . $config->get('DB_NAME'), $config->get('DB_USER'), $config->get('DB_PASS'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->exec("ALTER TABLE app.gis_layers ADD COLUMN extent geometry(Polygon, 4326) NULL;");
    echo "Successfully added extent column.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

try {
    $pdo->exec("INSERT INTO public.phinxlog (version, migration_name, start_time, end_time, breakpoint) VALUES ('20260920000019', 'AddExtentToGisLayers', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, false);");
    echo "Successfully recorded migration.";
} catch (Exception $e) {
    echo "Error recording migration: " . $e->getMessage();
}
