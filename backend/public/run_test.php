<?php
$root = realpath(__DIR__ . '/../');
$output = shell_exec("cd \"$root\" && vendor/bin/phpunit tests/Api/StyleTest.php 2>&1");
echo nl2br(htmlspecialchars($output));
