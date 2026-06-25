<?php
session_start();

require_once 'logger.php';

writeLog("ADMIN_LOGOUT", "Tài khoản đăng xuất khỏi hệ thống", [
    "username" => $_SESSION['admin_username'] ?? "",
    "role" => $_SESSION['admin_role'] ?? "",
    "login_time" => $_SESSION['login_time'] ?? ""
]);

session_unset();
session_destroy();

header("Location: login.php");
exit();