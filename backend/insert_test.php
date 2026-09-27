<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Survey\Domain\TraverseComputer;

$pdo = new PDO('pgsql:host=postgres;port=5432;dbname=webgis', 'app_rw', 'change_me_in_production');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("DELETE FROM app.parcels WHERE parcel_code LIKE 'TEST_%'");
$pdo->exec("DELETE FROM app.parcels WHERE parcel_code = 'LOT10-BLK12'");
echo "Cleared old test parcels.\n";

$stmt = $pdo->query("SELECT id, northing, easting FROM app.survey_control_points WHERE point_name = 'BBM 24' LIMIT 1");
$tp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tp) {
    die("BBM 24 not found in database.\n");
}

$computer = new TraverseComputer();
$courses = [
    ['bearing' => 'S 68° 20\' 00" E', 'distance' => 18.66],
    ['bearing' => 'S 46° 30\' 00" W', 'distance' => 23.16],
    ['bearing' => 'S 78° 37\' 00" W', 'distance' => 3.35],
    ['bearing' => 'N 68° 20\' 00" W', 'distance' => 5.65],
    ['bearing' => 'N 20° 33\' 00" E', 'distance' => 22.84],
];
$tieLines = [
    ['bearing' => 'S 44° 01\' 00" W', 'distance' => 421.12]
];

$result = $computer->compute(
    ['easting' => (float) $tp['easting'], 'northing' => (float) $tp['northing']],
    $tieLines,
    $courses
);

$vertices = $result['vertices'];

$wkt = "POLYGON((";
foreach ($vertices as $v) {
    $wkt .= "{$v['easting']} {$v['northing']}, ";
}
$wkt .= "{$vertices[0]['easting']} {$vertices[0]['northing']}))";

$pdo->exec("SET app.user_id = '900'");

$parcelId = 'df54b021-61fe-462a-b53b-bbc67ec9baa7';

// Insert parcel
$stmt = $pdo->prepare("
    INSERT INTO app.parcels (
        id, parcel_code, lot_number, block_number, 
        location_description, psgc_barangay, 
        status, geometry_source, geom, version, created_by, created_at, updated_by, updated_at
    ) VALUES (
        :id, 'LOT10-BLK12', '10', '12', 
        '5th St, 1 Avenue, Phase 2 Citicenter', '035401017', 
        'DRAFT', 'COMPUTED_FROM_TECHNICAL_DESCRIPTION', 
        ST_Multi(ST_Transform(ST_SetSRID(ST_GeomFromText(:wkt), 3123), 4326)), 
        2, 900, NOW(), 900, NOW()
    )
");
$stmt->execute(['id' => $parcelId, 'wkt' => $wkt]);

// Insert technical description
$stmt = $pdo->prepare("
    INSERT INTO app.technical_descriptions (parcel_id, revision, source_type, parser_status)
    VALUES (:parcel_id, 1, 'MANUALLY_ENTERED', 'PARSED')
    RETURNING id
");
$stmt->execute(['parcel_id' => $parcelId]);
$tdId = $stmt->fetchColumn();

// Insert tie point
$stmt = $pdo->prepare("
    INSERT INTO app.tie_points (
        technical_description_id, control_point_id, role, sequence
    ) VALUES (
        :td_id, :cp_id, 'TIE', 1
    )
    RETURNING id
");
$stmt->execute(['td_id' => $tdId, 'cp_id' => (int)$tp['id']]);
$tiePointId = $stmt->fetchColumn();

// Insert tie line
$stmt = $pdo->prepare("
    INSERT INTO app.tie_lines (
        technical_description_id, tie_point_id, original_bearing, distance_m, extraction_method
    ) VALUES (
        :td_id, :tp_id, 'S 44° 01'' 00\" W', 421.12, 'MANUALLY_ENTERED'
    )
");
$stmt->execute([
    'td_id' => $tdId,
    'tp_id' => $tiePointId
]);

// Insert courses
foreach ($courses as $idx => $course) {
    $stmt = $pdo->prepare("
        INSERT INTO app.technical_description_courses (
            technical_description_id, seq, original_bearing, distance_m
        ) VALUES (
            :td_id, :seq, :brg, :dist
        )
    ");
    $stmt->execute([
        'td_id' => $tdId,
        'seq' => $idx + 1,
        'brg' => $course['bearing'],
        'dist' => $course['distance']
    ]);
}

// Insert computation
$stmt = $pdo->prepare("
    INSERT INTO app.parcel_computations (
        parcel_id, technical_description_id, geom, computed_area_sqm, is_current, compute_crs_id
    ) VALUES (
        :parcel_id, :td_id, 
        ST_Transform(ST_SetSRID(ST_GeomFromText(:wkt), 3123), 4326), 
        100.5, true, 5
    )
    RETURNING id
");
$stmt->execute([
    'parcel_id' => $parcelId,
    'td_id' => $tdId,
    'wkt' => $wkt
]);
$compId = $stmt->fetchColumn();

$pdo->exec("UPDATE app.parcels SET current_computation_id = {$compId} WHERE id = '{$parcelId}'");

echo "Successfully re-inserted LOT10-BLK12 with complete details.\n";
