<?php
session_start();

require_once 'includes/logger.php';
require_once 'includes/credentials.php';
require_once 'includes/users.php';
require_once 'includes/lang.php';

initLang();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit();
}

if (isset($_SESSION['unit_logged_in']) && $_SESSION['unit_logged_in'] === true) {
    header("Location: unit_dashboard.php");
    exit();
}

writeLog("REGISTER_PAGE_ACCESS", "Truy cập trang đăng ký tài khoản đơn vị");

$error = "";
$username = "";
$organizationName = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $organizationName = trim($_POST['organization_name'] ?? '');

    $users = loadUsers();

    if ($username === '' || $password === '' || $organizationName === '') {
        $error = t('register.error_missing_fields');
    } elseif (strlen($password) < 6) {
        $error = t('register.error_password_too_short');
    } elseif ($password !== $passwordConfirm) {
        $error = t('register.error_password_mismatch');
    } elseif (usernameTaken($username, $users, $accounts)) {
        $error = t('register.error_username_taken');
    } else {
        $unitKey = normalizeUnitKey($organizationName);
        $unitDisplay = cleanUnitDisplayName($organizationName);

        if ($unitKey === '') {
            $error = t('register.error_invalid_org');
        } elseif (unitKeyExists($unitKey, $users)) {
            $error = t('register.error_unit_taken');

            writeLog("REGISTER_BLOCKED", "Đăng ký bị chặn do đơn vị đã có tài khoản", [
                "username" => $username,
                "organization_name" => $organizationName,
                "unit_key" => $unitKey
            ], "WARN");
        } else {
            $users[$username] = [
                "password_hash" => password_hash($password, PASSWORD_DEFAULT),
                "unit_display" => $unitDisplay,
                "unit_key" => $unitKey,
                "created_at" => date("Y-m-d H:i:s")
            ];

            if (saveUsers($users)) {
                writeLog("REGISTER_SUCCESS", "Đăng ký tài khoản đơn vị thành công", [
                    "username" => $username,
                    "organization_name" => $organizationName,
                    "unit_key" => $unitKey
                ]);

                header("Location: login.php?registered=1");
                exit();
            }

            $error = t('register.error_save_failed');

            writeLog("REGISTER_BLOCKED", "Không thể lưu tài khoản đơn vị mới", [
                "username" => $username,
                "unit_key" => $unitKey
            ], "ERROR");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $LANG; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#667eea">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?php echo t('register.title'); ?></title>
    <link rel="stylesheet" href="i18n.css">
    <style>
        .lang-switch {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            padding: 24px 16px;
        }

        .register-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 440px;
        }

        .register-container h1 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }

        .back-to-form-link {
            display: inline-block;
            margin-bottom: 16px;
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }

        .back-to-form-link:hover {
            text-decoration: underline;
        }

        .register-container p.subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .form-group { margin-bottom: 18px; }

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

        .form-group .field-help {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #888;
        }

        .register-btn {
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
            line-height: 1.5;
        }

        .login-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
        }

        .login-link a {
            color: #667eea;
            font-weight: bold;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <?php echo langSwitchLinks(); ?>

    <div class="register-container">
        <a href="index.php" class="back-to-form-link"><?php echo t('login.back_to_form'); ?></a>
        <h1><?php echo t('register.h1'); ?></h1>
        <p class="subtitle"><?php echo t('register.subtitle'); ?></p>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="info-box">
            <?php echo t('register.info_box'); ?>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="organization_name"><?php echo t('register.org_label'); ?></label>
                <input type="text" id="organization_name" name="organization_name" required autofocus
                    value="<?php echo htmlspecialchars($organizationName); ?>"
                    placeholder="<?php echo htmlspecialchars(t('register.org_placeholder')); ?>">
                <small class="field-help"><?php echo t('register.org_help'); ?></small>
            </div>

            <div class="form-group">
                <label for="username"><?php echo t('register.username_label'); ?></label>
                <input type="text" id="username" name="username" required
                    value="<?php echo htmlspecialchars($username); ?>">
            </div>

            <div class="form-group">
                <label for="password"><?php echo t('register.password_label'); ?></label>
                <input type="password" id="password" name="password" required minlength="6">
            </div>

            <div class="form-group">
                <label for="password_confirm"><?php echo t('register.password_confirm_label'); ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="6">
            </div>

            <button type="submit" class="register-btn"><?php echo t('register.submit_btn'); ?></button>
        </form>

        <div class="login-link">
            <?php echo t('register.has_account_prefix'); ?> <a href="login.php"><?php echo t('register.login_link'); ?></a>
        </div>
    </div>
    <script src="pwa-register.js"></script>
</body>

</html>
