<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensureLogDir()
{
    $logDir = __DIR__ . "/logs";

    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    return $logDir;
}

function getClientIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function cleanupOldRotatedLogs($logDir)
{
    $files = glob($logDir . "/system_*.log");

    if (!$files || count($files) <= 10) {
        return;
    }

    usort($files, function ($a, $b) {
        return filemtime($a) <=> filemtime($b);
    });

    while (count($files) > 10) {
        @unlink(array_shift($files));
    }
}

function rotateLogIfNeeded($logFile)
{
    $maxSize = 20 * 1024 * 1024; // 20MB

    if (file_exists($logFile) && filesize($logFile) >= $maxSize) {
        $logDir = dirname($logFile);

        $backup = $logDir . "/system_" . date("Ymd_His") . ".log";

        @rename($logFile, $backup);

        cleanupOldRotatedLogs($logDir);
    }
}

function shouldWriteLog($type)
{
    $allowedTypes = [
        // Form
        "PAGE_ACCESS",
        "FORM_FUNCTION_TOGGLE",
        "AUTO_SAVE",
        "FORM_AUTO_SAVE",
        "FORM_SUBMIT",
        "FORM_SUBMIT_SUCCESS",
        "FORM_SUBMISSION_SUCCESS",
        "SUBMIT_SUCCESS",
        "RESULT_SAVED",

        // Admin login
        "ADMIN_LOGIN_PAGE_ACCESS",
        "ADMIN_LOGIN_SUCCESS",
        "ADMIN_LOGIN_FAILED",
        "ADMIN_LOGOUT",

        // Dashboard / view
        "ADMIN_DASHBOARD_ACCESS",
        "ADMIN_DASHBOARD_RENDER",
        "ADMIN_VIEW_FILE",

        // Edit file
        "ADMIN_FILE_EDIT",
        "ADMIN_FILE_EDIT_SUCCESS",
        "ADMIN_FILE_UPDATE",
        "ADMIN_FILE_UPDATE_SUCCESS",
        "ADMIN_RENAME_FILE",
        "ADMIN_RENAME_FILE_SUCCESS",

        // System errors
        "SYSTEM_PHP_ERROR",
        "SYSTEM_SHUTDOWN_ERROR",
        "SYSTEM_ERROR"
    ];

    return in_array($type, $allowedTypes, true);
}

function writeLog($type, $message = "", $context = [], $level = "INFO")
{
    if (!shouldWriteLog($type)) {
        return;
    }

    $logDir = ensureLogDir();
    $logFile = $logDir . "/system.log";

    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0664);
    }

    rotateLogIfNeeded($logFile);
    cleanupOldRotatedLogs($logDir);

    $entry = [
        "time"       => date("Y-m-d H:i:s"),
        "level"      => $level,
        "type"       => $type,
        "message"    => $message,
        "user"       => $_SESSION['admin_username'] ?? "guest",
        "role"       => $_SESSION['admin_role'] ?? "guest",
        "ip"         => getClientIp(),
        "method"     => $_SERVER['REQUEST_METHOD'] ?? "",
        "uri"        => $_SERVER['REQUEST_URI'] ?? "",
        "page"       => basename($_SERVER['PHP_SELF'] ?? ""),
        "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? "",
        "context"    => $context
    ];

    $logLine = json_encode(
        $entry,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    $result = @file_put_contents(
        $logFile,
        $logLine,
        FILE_APPEND | LOCK_EX
    );

    if ($result === false) {
        error_log("[LOGGER] Cannot write to log file: " . $logFile);
    }
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