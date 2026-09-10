<?php
session_start();

require_once 'includes/logger.php';
require_once 'includes/users.php';
require_once 'includes/lang.php';
require_once 'includes/tsv.php';

initLang();

if (!isset($_SESSION['unit_logged_in']) || $_SESSION['unit_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$unitKey = $_SESSION['unit_key'] ?? '';
$unitDisplay = $_SESSION['unit_display'] ?? $unitKey;

$resultsDir = "results/";

function getUnitSubmissions($resultsDir, $unitKey)
{
    $files = [];

    if (!is_dir($resultsDir)) {
        return [];
    }

    foreach (scandir($resultsDir) as $file) {
        if ($file === "." || $file === ".." || $file === "results.tsv" || $file === ".summary_state.json") {
            continue;
        }

        $filepath = $resultsDir . $file;

        if (!is_file($filepath)) {
            continue;
        }

        $parsed = parseDetailFilename($file);

        if ($parsed === null) {
            continue;
        }

        if (normalizeUnitKey($parsed['unit']) !== $unitKey) {
            continue;
        }

        $files[] = [
            'name' => $file,
            'timestamp' => $parsed['timestamp'],
            'size' => filesize($filepath),
            'time' => filemtime($filepath),
            'modified' => date('d/m/Y H:i:s', filemtime($filepath))
        ];
    }

    usort($files, function ($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    return $files;
}

$submissions = getUnitSubmissions($resultsDir, $unitKey);

writeLog("UNIT_DASHBOARD_ACCESS", "Tài khoản đơn vị truy cập dashboard riêng", [
    "username" => $_SESSION['unit_username'] ?? "",
    "unit_key" => $unitKey,
    "submission_count" => count($submissions)
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
    <title><?php echo t('unit_dashboard.title'); ?></title>
    <link rel="stylesheet" href="i18n.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header .container {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .header h1 {
            font-size: 22px;
        }

        .header .unit-name {
            font-size: 13px;
            opacity: 0.9;
            margin-top: 4px;
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .header .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        .header .form-link {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            white-space: nowrap;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .section h2 {
            color: #667eea;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
            margin-bottom: 20px;
        }

        .stat-box .label {
            color: #666;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            min-width: 500px;
        }

        table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #ddd;
        }

        table th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
            color: #333;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        table tr:hover {
            background-color: #f8f9fa;
        }

        .file-size {
            color: #666;
            font-size: 13px;
        }

        .empty-message {
            color: #999;
            padding: 20px;
            text-align: center;
        }

        .info-box {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #bee5eb;
            line-height: 1.6;
        }

        .view-btn,
        .download-btn {
            display: inline-block;
            padding: 6px 10px;
            margin-right: 5px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            color: white;
        }

        .view-btn {
            background-color: #17a2b8;
        }

        .download-btn {
            background-color: #28a745;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container">
            <div>
                <h1><?php echo t('unit_dashboard.h1'); ?></h1>
                <div class="unit-name"><?php echo htmlspecialchars($unitDisplay); ?></div>
            </div>
            <div class="user-info">
                <?php echo langSwitchLinks(true); ?>
                <a href="index.php" class="form-link"><?php echo t('dashboard.back_to_form'); ?></a>
                <span>👤 <?php echo htmlspecialchars($_SESSION['unit_username']); ?></span>
                <form method="POST" action="logout.php" style="margin: 0;">
                    <button type="submit" class="logout-btn"><?php echo t('dashboard.logout_btn'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="section">
            <h2><?php echo t('unit_dashboard.summary_heading'); ?></h2>
            <div class="stat-box">
                <div class="label"><?php echo t('unit_dashboard.stat_submission_count'); ?></div>
                <div class="value"><?php echo count($submissions); ?></div>
            </div>
        </div>

        <div class="section">
            <h2><?php echo t('unit_dashboard.files_heading'); ?></h2>
            <div class="info-box">
                <?php echo t('unit_dashboard.info_box'); ?>
            </div>

            <?php if (empty($submissions)): ?>
                <div class="empty-message"><?php echo t('unit_dashboard.empty_message'); ?></div>
            <?php else: ?>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('dashboard.th_filename'); ?></th>
                            <th><?php echo t('dashboard.th_size'); ?></th>
                            <th><?php echo t('dashboard.th_modified'); ?></th>
                            <th><?php echo t('dashboard.th_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $file): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($file['name']); ?></td>
                                <td class="file-size"><?php echo number_format($file['size'], 0) . t('dashboard.bytes_suffix'); ?></td>
                                <td><?php echo $file['modified']; ?></td>
                                <td>
                                    <a href="view_file.php?file=<?php echo urlencode($file['name']); ?>" class="view-btn"><?php echo t('dashboard.view_link'); ?></a>
                                    <a href="view_file.php?file=<?php echo urlencode($file['name']); ?>&export=1" class="download-btn"><?php echo t('dashboard.download_link'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="pwa-register.js"></script>
</body>

</html>
