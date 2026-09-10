<?php
session_start();

require_once 'includes/logger.php';

writeLog("ADMIN_LOGOUT", "Tài khoản đăng xuất khỏi hệ thống", [
    "username" => $_SESSION['admin_username'] ?? $_SESSION['unit_username'] ?? "",
    "role" => $_SESSION['admin_role'] ?? ($_SESSION['unit_logged_in'] ?? false ? 'unit' : ""),
    "login_time" => $_SESSION['login_time'] ?? ""
]);

session_unset();
session_destroy();

header("Location: login.php");
exit();