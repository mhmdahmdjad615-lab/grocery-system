<?php
require_once __DIR__ . '/../includes/store_functions.php';

header('Content-Type: application/json; charset=UTF-8');

echo json_encode(get_platform_merchant_stats($pdo), JSON_UNESCAPED_UNICODE);
