<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Survey\Domain\TraverseComputer;

$pdo = new PDO('pgsql:host=postgres;port=5432;dbname=webgis', 'app_rw', 'change_me_in_production');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get Tie point BBM 24
$stmt = $pdo->query("SELECT id, northing, easting FROM app.survey_control_points WHERE point_name = 'BBM 24' LIMIT 1");
$tp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tp) {
    die("BBM 24 not found in database.\n");
}

$computer = new TraverseComputer();
$result = $computer->compute(
    ['easting' => (float) $tp['easting'], 'northing' => (float) $tp['northing']],
    [
        ['bearing' => 'S 44° 01\' 00" W', 'distance' => 421.12]
    ],
    [
        ['bearing' => 'S 68° 20\' 00" E', 'distance' => 18.66],
        ['bearing' => 'S 46° 30\' 00" W', 'distance' => 23.16],
        ['bearing' => 'S 78° 37\' 00" W', 'distance' => 3.35],
        ['bearing' => 'N 68° 20\' 00" W', 'distance' => 5.65],
        ['bearing' => 'N 20° 33\' 00" E', 'distance' => 22.84],
    ]
);

$vertices = $result['vertices'];
$pob = $result['pob'];

// Build WKT for the polygon in local coordinates (PPCS Zone III)
// TraverseComputer vertices are the FROM points of each course
$wkt = "POLYGON((";
foreach ($vertices as $v) {
    $wkt .= "{$v['easting']} {$v['northing']}, ";
}
// Close the polygon by repeating the first vertex
$wkt .= "{$vertices[0]['easting']} {$vertices[0]['northing']}))";

echo "Constructed WKT: $wkt\n";

// Set user context for RLS
$pdo->query("SET app.user_id = '1001'");

$stmt = $pdo->prepare("
    INSERT INTO app.parcels (id, parcel_code, geometry_source, psgc_barangay, geom)
    VALUES (gen_random_uuid(), 'LOT10-BLK12', 'COMPUTED_FROM_TECHNICAL_DESCRIPTION', '035401017', ST_Multi(ST_Transform(ST_SetSRID(ST_GeomFromText(:wkt), 990103), 4326)))
    RETURNING id
");
$stmt->execute(['wkt' => $wkt]);
$parcelId = $stmt->fetchColumn();



echo "Successfully inserted parcel with ID: $parcelId\n";
echo "You can now check the map to see if it overlays perfectly!\n";
