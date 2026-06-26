<?php
header('Content-Type: application/json; charset=utf-8');

require_once 'logger.php';

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    writeLog("CLIENT_LOG_INVALID", "Dữ liệu log frontend không hợp lệ", [
        "raw" => $raw
    ], "WARN");

    echo json_encode(["success" => false]);
    exit();
}

$allowedClientEventTypes = [
    "FORM_PAGE_READY",
    "FORM_FUNCTION_TOGGLE",
    "AUTO_SAVE",
    "FORM_AUTO_SAVE",
    "FORM_SUBMIT",
    "FORM_SUBMIT_SUCCESS",
    "FORM_SUBMISSION_SUCCESS",
    "SUBMIT_SUCCESS",
    "RESULT_SAVED"
];

$type = $data['type'] ?? 'CLIENT_EVENT';
$message = $data['message'] ?? '';
$context = $data['context'] ?? [];
$level = $data['level'] ?? 'INFO';

if ($type === "CLIENT_LOG_BATCH") {
    $events = $context['events'] ?? [];

    if (!is_array($events)) {
        echo json_encode(["success" => true, "skipped" => true]);
        exit();
    }

    $filteredEvents = [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventType = $event['type'] ?? '';

        if (in_array($eventType, $allowedClientEventTypes, true)) {
            $filteredEvents[] = $event;
        }
    }

    if (count($filteredEvents) > 0) {
        writeLog("CLIENT_LOG_BATCH", "Ghi nhận hoạt động quan trọng trên form", [
            "events" => $filteredEvents
        ], $level);
    }

    echo json_encode([
        "success" => true,
        "kept" => count($filteredEvents),
        "skipped" => count($events) - count($filteredEvents)
    ]);
    exit();
}

if (in_array($type, $allowedClientEventTypes, true)) {
    writeLog($type, $message, $context, $level);
}

echo json_encode(["success" => true]);