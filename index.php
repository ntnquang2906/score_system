<?php
require_once 'logger.php';
require_once 'lang.php';

initLang();

writeLog("PAGE_ACCESS", "Người dùng truy cập form đánh giá", [
    "page" => "index.php"
]);
?>
<!DOCTYPE html>
<html lang="<?php echo $LANG; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Đánh giá KH&CN">
    <title>Hệ thống đánh giá KH&CN</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="i18n.css">
    <style>
        .admin-login-link {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
            background-color: #667eea;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            transition: background-color 0.3s;
        }

        .admin-login-link:hover {
            background-color: #764ba2;
        }

        body {
            position: relative;
        }

        h1 {
            margin-top: 70px;
        }

        @media(max-width:480px) {
            .admin-login-link {
                position: static;
                display: inline-block;
                margin-bottom: 12px;
            }

            h1 {
                margin-top: 0;
            }
        }
    </style>
</head>

<body>
    <div class="admin-login-link" style="display:flex; gap:10px; align-items:center;">
        <?php echo langSwitchLinks(true); ?>
        <a href="login.php"><?php echo t('index.admin_link'); ?></a>
    </div>

    <h1><?php echo t('index.h1'); ?></h1>

    <form
        action="process.php"
        method="POST"
        enctype="multipart/form-data"
        onsubmit="return validateForm()">

        <label><?php echo t('index.org_label'); ?> <span style="color:red">*</span></label>
        <input
            type="text"
            name="organization_name"
            id="organization_name"
            required
            autocomplete="organization"
            placeholder="<?php echo htmlspecialchars(t('index.org_placeholder')); ?>">

        <h3><?php echo t('index.function_heading'); ?></h3>

        <div id="function-checkboxes">
            <label><input type="checkbox" value="basic"> <?php echo t('index.func_basic'); ?></label>
            <label><input type="checkbox" value="applied"> <?php echo t('index.func_applied'); ?></label>
            <label><input type="checkbox" value="tech"> <?php echo t('index.func_tech'); ?></label>
            <label><input type="checkbox" value="policy"> <?php echo t('index.func_policy'); ?></label>
        </div>

        <div id="hidden-inputs"></div>
        <div id="form-area"></div>

        <button type="submit"><?php echo t('index.submit_btn'); ?></button>
    </form>

    <script>
        window.APP_LANG = <?php echo json_encode($LANG); ?>;
        window.I18N = <?php
            $jsTranslations = [];
            foreach ($TRANSLATIONS as $key => $value) {
                if (strpos($key, 'js.') === 0) {
                    $jsTranslations[substr($key, 3)] = $value;
                }
            }
            echo json_encode($jsTranslations, JSON_UNESCAPED_UNICODE);
        ?>;
    </script>
    <script src="script.js?v=8"></script>
    <script src="pwa-register.js"></script>
</body>

</html>