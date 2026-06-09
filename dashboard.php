<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$canEdit = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'editor';

$resultsDir = "results/";
$summaryFile = $resultsDir . "results.tsv";

function removeBom($content)
{
    return substr($content, 0, 3) === "\xEF\xBB\xBF" ? substr($content, 3) : $content;
}

function readTsvFile($filepath)
{
    if (!file_exists($filepath) || !is_file($filepath)) {
        return [];
    }

    $content = removeBom(file_get_contents($filepath));
    $lines = explode("\n", $content);
    $data = [];

    foreach ($lines as $line) {
        if (trim($line) !== "") {
            $data[] = explode("\t", $line);
        }
    }

    return $data;
}

function writeTsvFile($filepath, $data)
{
    $fp = fopen($filepath, "w");

    if (!$fp) {
        return false;
    }

    fwrite($fp, "\xEF\xBB\xBF");

    foreach ($data as $row) {
        $cleanRow = [];

        foreach ($row as $cell) {
            $cleanRow[] = str_replace(["\t", "\r", "\n"], " ", $cell);
        }

        fwrite($fp, implode("\t", $cleanRow) . "\n");
    }

    fclose($fp);
    return true;
}

function parseDetailFilename($file)
{
    if ($file === "results.tsv") {
        return null;
    }

    if (!preg_match('/^(\d{8}_\d{6})_(.+)\.tsv$/u', $file, $matches)) {
        return null;
    }

    return [
        'timestamp' => $matches[1],
        'unit' => $matches[2]
    ];
}

function getLatestDetailFiles($resultsDir)
{
    $latest = [];

    if (!is_dir($resultsDir)) {
        return [];
    }

    foreach (scandir($resultsDir) as $file) {
        if ($file === "." || $file === ".." || $file === "results.tsv") {
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

        $unit = $parsed['unit'];
        $timestamp = $parsed['timestamp'];

        if (
            !isset($latest[$unit]) ||
            $timestamp > $latest[$unit]['timestamp']
        ) {
            $latest[$unit] = [
                'name' => $file,
                'unit' => $unit,
                'timestamp' => $timestamp,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'modified' => date('d/m/Y H:i:s', filemtime($filepath))
            ];
        }
    }

    $files = array_values($latest);

    usort($files, function ($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    return $files;
}

function rebuildSummaryFile($resultsDir, $summaryFile, $latestDetailFiles)
{
    $summaryData = [];
    $headerSet = false;

    foreach ($latestDetailFiles as $fileInfo) {
        $filepath = $resultsDir . $fileInfo['name'];
        $data = readTsvFile($filepath);

        if (empty($data)) {
            continue;
        }

        if (!$headerSet) {
            $summaryData[] = $data[0];
            $headerSet = true;
        }

        for ($i = 1; $i < count($data); $i++) {
            if (!empty($data[$i])) {
                $summaryData[] = $data[$i];
            }
        }
    }

    if (!$headerSet) {
        $summaryData[] = [
            "Thời gian",
            "Tổ chức",
            "Chức năng",
            "Trọng số",
            "Đt1",
            "Đt2",
            "Đt3",
            "Đt4",
            "ĐT",
            "Điểm quy đổi",
            "Tổng E",
            "Xếp loại",
            "Nhóm",
            "Câu hỏi",
            "Có/Không",
            "Điểm câu hỏi",
            "Chú thích",
            "Minh chứng"
        ];
    }

    return writeTsvFile($summaryFile, $summaryData);
}

function shouldRebuildSummary($resultsDir, $summaryFile, $latestDetailFiles)
{
    if (!file_exists($summaryFile)) {
        return true;
    }

    $summaryTime = filemtime($summaryFile);

    foreach ($latestDetailFiles as $fileInfo) {
        $detailPath = $resultsDir . $fileInfo['name'];

        if (
            file_exists($detailPath) &&
            filemtime($detailPath) > $summaryTime
        ) {
            return true;
        }
    }

    $summaryData = readTsvFile($summaryFile);
    $summaryUnits = [];

    for ($i = 1; $i < count($summaryData); $i++) {
        if (
            isset($summaryData[$i][1]) &&
            trim($summaryData[$i][1]) !== ""
        ) {
            $summaryUnits[trim($summaryData[$i][1])] = true;
        }
    }

    if (count($summaryUnits) !== count($latestDetailFiles)) {
        return true;
    }

    return false;
}

if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0777, true);
}

$latestDetailFiles = getLatestDetailFiles($resultsDir);

if (shouldRebuildSummary($resultsDir, $summaryFile, $latestDetailFiles)) {
    rebuildSummaryFile($resultsDir, $summaryFile, $latestDetailFiles);
}

if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $resultsDir . $file;

    if (
        file_exists($filepath) &&
        is_file($filepath) &&
        strpos(realpath($filepath), realpath($resultsDir)) === 0
    ) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    }
}

$files = [];

if (file_exists($summaryFile)) {
    $files[] = [
        'name' => 'results.tsv',
        'unit' => 'File tổng hợp',
        'timestamp' => date('Ymd_His', filemtime($summaryFile)),
        'size' => filesize($summaryFile),
        'time' => filemtime($summaryFile),
        'modified' => date('d/m/Y H:i:s', filemtime($summaryFile))
    ];
}

foreach ($latestDetailFiles as $fileInfo) {
    $files[] = $fileInfo;
}

$uniqueOrganizations = count($latestDetailFiles);
$detailFilesCount = count($latestDetailFiles);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Kết quả đánh giá KH&CN</title>
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
        }

        .header .container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 24px;
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header .logout-btn {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        .container {
            max-width: 1200px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
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
        .download-btn,
        .edit-btn {
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

        .edit-btn {
            background-color: #ffc107;
            color: #333;
        }

        .role-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        .unit-name {
            color: #555;
            font-size: 12px;
            margin-top: 4px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container">
            <h1>📊 Dashboard - Kết quả đánh giá KH&CN</h1>
            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <span class="role-badge">
                    <?php echo $canEdit ? 'Quyền sửa' : 'Chỉ xem'; ?>
                </span>
                <form method="POST" action="logout.php" style="margin: 0;">
                    <button type="submit" class="logout-btn">Đăng xuất</button>
                </form>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="section">
            <h2>📈 Thông tin tổng hợp</h2>
            <div class="stats">
                <div class="stat-box">
                    <div class="label">Tổng số đơn vị đã điền</div>
                    <div class="value"><?php echo $uniqueOrganizations; ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Tổng số file chi tiết đang hiển thị</div>
                    <div class="value"><?php echo $detailFilesCount; ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">File tổng hợp</div>
                    <div class="value"><?php echo file_exists($summaryFile) ? '✓' : '✗'; ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <h2>📁 Danh sách file kết quả</h2>
            <div class="info-box">
                💡 Dashboard chỉ tạo lại <strong>results.tsv</strong> khi cần: khi có file chi tiết mới, file chi tiết được sửa, số đơn vị thay đổi hoặc file tổng hợp bị mất.<br>
                Danh sách bên dưới hiển thị <strong>file tổng hợp</strong> ở đầu và <strong>file chi tiết mới nhất của mỗi đơn vị</strong>.
            </div>

            <?php if (empty($files)): ?>
                <div class="empty-message">Chưa có file kết quả nào được lưu.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Tên file</th>
                            <th>Dung lượng</th>
                            <th>Thời gian chỉnh sửa</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($file['name']); ?>
                                    <?php if ($file['name'] !== 'results.tsv'): ?>
                                        <div class="unit-name">
                                            Đơn vị: <?php echo htmlspecialchars($file['unit']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="file-size"><?php echo number_format($file['size'], 0) . ' bytes'; ?></td>
                                <td><?php echo $file['modified']; ?></td>
                                <td>
                                    <a href="view_file.php?file=<?php echo urlencode($file['name']); ?>" class="view-btn">Xem</a>
                                    <a href="?download=<?php echo urlencode($file['name']); ?>" class="download-btn">Tải xuống</a>

                                    <?php if ($canEdit && $file['name'] !== 'results.tsv'): ?>
                                        <a href="edit_file.php?file=<?php echo urlencode($file['name']); ?>" class="edit-btn">Sửa</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>