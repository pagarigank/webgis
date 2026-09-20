<?php
require 'vendor/autoload.php';
 = new PDO('pgsql:host=localhost;dbname=webgis', 'postgres', 'postgres', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
 = "SELECT id, occurred_at, user_id, username_snapshot, action, entity_type, entity_id, changed_fields, reason, ip FROM audit.audit_logs WHERE 1=1 ORDER BY occurred_at DESC LIMIT 100";
try {
     = ->prepare();
    ->execute([]);
    print_r(->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception ) {
    echo ->getMessage();
}
