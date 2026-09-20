<?php
$root = realpath(__DIR__ . '/../');
$root = realpath(__DIR__ . '/../');
$output = shell_exec("cd " . escapeshellarg($root) . " && php vendor/bin/phpunit tests/Api/FieldRetypeTest.php 2>&1");
echo nl2br(htmlspecialchars($output));
