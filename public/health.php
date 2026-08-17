<?php
// Infrastructure health probe. Intentionally dependency-free: it must answer
// even when the application itself is broken.
header("Content-Type: application/json");
header("Cache-Control: no-store");
http_response_code(200);
echo json_encode([
    "status" => "ok",
    "php"    => PHP_VERSION,
    "time"   => gmdate("c"),
], JSON_UNESCAPED_SLASHES), "\n";
