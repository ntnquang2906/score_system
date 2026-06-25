<?php
session_start();

require_once 'logger.php';
require_once 'credentials.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    writeLog("ADMIN_LOGIN_REDIRECT", "Tài khoản đã đăng nhập, chuyển hướng về dashboard", [
        "username" => $_SESSION['admin_username'] ?? "",
        "role" => $_SESSION['admin_role'] ?? ""
    ]);

    header("Location: dashboard.php");
    exit();
}

writeLog("ADMIN_LOGIN_PAGE_ACCESS", "Truy cập trang đăng nhập quản trị");

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        isset($accounts[$username]) &&
        isset($accounts[$username]['password']) &&
        $accounts[$username]['password'] === $password
    ) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $accounts[$username]['role'] ?? 'viewer';
        $_SESSION['login_time'] = time();

        writeLog("ADMIN_LOGIN_SUCCESS", "Đăng nhập quản trị thành công", [
            "username" => $username,
            "role" => $_SESSION['admin_role']
        ]);

        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Tài khoản hoặc mật khẩu không chính xác!";

        writeLog("ADMIN_LOGIN_FAIL", "Đăng nhập quản trị thất bại", [
            "username" => $username,
            "reason" => "wrong_username_or_password"
        ], "WARN");
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Hệ thống đánh giá KH&CN</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-container h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 24px;
        }

        .login-container p {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .login-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .info-box {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #bee5eb;
            font-size: 13px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h1>🔐 Đăng nhập</h1>
        <p>Hệ thống quản lý kết quả đánh giá KH&CN</p>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="info-box">
            ℹ️ Chỉ dành cho lãnh đạo/quản trị viên. Vui lòng nhập tài khoản và mật khẩu.
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="username">Tên tài khoản:</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Mật khẩu:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="login-btn">Đăng nhập</button>
        </form>
    </div>
</body>

</html>