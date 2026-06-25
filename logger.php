<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensureLogDir()
{
    $logDir = __DIR__ . "/logs";

    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    return $logDir;
}

function getClientIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function writeLog($type, $message = "", $context = [], $level = "INFO")
{
    $logDir = ensureLogDir();
    $logFile = $logDir . "/system.log";

    $entry = [
        "time" => date("Y-m-d H:i:s"),
        "level" => $level,
        "type" => $type,
        "message" => $message,
        "user" => $_SESSION['admin_username'] ?? "guest",
        "role" => $_SESSION['admin_role'] ?? "guest",
        "ip" => getClientIp(),
        "method" => $_SERVER['REQUEST_METHOD'] ?? "",
        "uri" => $_SERVER['REQUEST_URI'] ?? "",
        "page" => basename($_SERVER['PHP_SELF'] ?? ""),
        "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? "",
        "context" => $context
    ];

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

set_error_handler(function ($severity, $message, $file, $line) {
    writeLog("SYSTEM_PHP_ERROR", $message, [
        "file" => $file,
        "line" => $line,
        "severity" => $severity
    ], "ERROR");

    return false;
});

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null) {
        writeLog("SYSTEM_SHUTDOWN_ERROR", $error['message'] ?? "", [
            "file" => $error['file'] ?? "",
            "line" => $error['line'] ?? "",
            "type" => $error['type'] ?? ""
        ], "ERROR");
    }
});