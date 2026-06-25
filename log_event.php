<?php
require_once 'logger.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    writeLog("CLIENT_LOG_INVALID", "Dữ liệu log frontend không hợp lệ", [
        "raw" => $raw
    ], "WARN");

    echo json_encode(["success" => false]);
    exit();
}

$type = $data['type'] ?? 'CLIENT_EVENT';
$message = $data['message'] ?? '';
$context = $data['context'] ?? [];
$level = $data['level'] ?? 'INFO';

writeLog($type, $message, $context, $level);

echo json_encode(["success" => true]);