<?php
session_start();

require_once 'logger.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    writeLog("ADMIN_BLOCKED_ACCESS", "Truy cập dashboard bị chặn do chưa đăng nhập", [
        "target" => "dashboard.php"
    ], "WARN");

    header("Location: login.php");
    exit();
}

$canEdit = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'editor';

writeLog("ADMIN_DASHBOARD_ACCESS", "Admin/lãnh đạo truy cập dashboard", [
    "username" => $_SESSION['admin_username'] ?? "",
    "role" => $_SESSION['admin_role'] ?? "",
    "can_edit" => $canEdit
]);

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
        writeLog("SYSTEM_FILE_WRITE_ERROR", "Không thể mở file để ghi", [
            "file" => $filepath
        ], "ERROR");

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
        if ($file === "." || $file === ".." || $file === "results.tsv" || $file === ".summary_state.json") {
            continue;
        }

        $filepath = $resultsDir . $file;

        if (!is_file($filepath)) {
            continue;
        }

        $parsed = parseDetailFilename($file);

        if ($parsed === null) {
            writeLog("SYSTEM_RESULT_FILE_SKIPPED", "Bỏ qua file không đúng định dạng", [
                "file" => $file
            ], "WARN");

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

function getSummaryStateFile($resultsDir)
{
    return rtrim($resultsDir, "/") . "/.summary_state.json";
}

function readSummaryState($resultsDir)
{
    $stateFile = getSummaryStateFile($resultsDir);

    if (!file_exists($stateFile)) {
        return null;
    }

    $data = json_decode(file_get_contents($stateFile), true);

    return is_array($data) ? $data : null;
}

function writeSummaryState($resultsDir, $latestDetailFiles)
{
    $stateFile = getSummaryStateFile($resultsDir);

    $latestTimestamp = "";
    $latestMtime = 0;

    foreach ($latestDetailFiles as $fileInfo) {
        $latestTimestamp = max($latestTimestamp, $fileInfo['timestamp'] ?? "");
        $latestMtime = max($latestMtime, $fileInfo['time'] ?? 0);
    }

    $state = [
        "last_build" => date("Y-m-d H:i:s"),
        "detail_file_count" => count($latestDetailFiles),
        "latest_timestamp" => $latestTimestamp,
        "latest_mtime" => $latestMtime,
        "files" => array_map(function ($fileInfo) {
            return [
                "name" => $fileInfo["name"],
                "unit" => $fileInfo["unit"],
                "timestamp" => $fileInfo["timestamp"],
                "mtime" => $fileInfo["time"]
            ];
        }, $latestDetailFiles)
    ];

    $saved = file_put_contents(
        $stateFile,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    if ($saved === false) {
        writeLog("SYSTEM_SUMMARY_STATE_WRITE_ERROR", "Không thể ghi file trạng thái tổng hợp", [
            "state_file" => $stateFile
        ], "ERROR");

        return false;
    }

    writeLog("SYSTEM_SUMMARY_STATE_UPDATED", "Đã cập nhật file trạng thái tổng hợp", [
        "state_file" => $stateFile,
        "detail_file_count" => count($latestDetailFiles),
        "latest_timestamp" => $latestTimestamp
    ]);

    return true;
}

function rebuildSummaryFile($resultsDir, $summaryFile, $latestDetailFiles)
{
    $summaryData = [];
    $headerSet = false;

    foreach ($latestDetailFiles as $fileInfo) {
        $filepath = $resultsDir . $fileInfo['name'];
        $data = readTsvFile($filepath);

        if (empty($data)) {
            writeLog("SYSTEM_SUMMARY_SKIP_EMPTY_DETAIL", "Bỏ qua file chi tiết rỗng khi tổng hợp", [
                "file" => $filepath
            ], "WARN");

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

    $result = writeTsvFile($summaryFile, $summaryData);

    if ($result) {
        writeLog("SYSTEM_REBUILD_RESULTS", "Đã tạo/cập nhật file results.tsv", [
            "summary_file" => $summaryFile,
            "detail_file_count" => count($latestDetailFiles),
            "row_count" => max(count($summaryData) - 1, 0)
        ]);
    }

    return $result;
}

function shouldRebuildSummary($resultsDir, $summaryFile, $latestDetailFiles)
{
    if (!file_exists($summaryFile)) {
        writeLog("SYSTEM_REBUILD_NEEDED", "File tổng hợp chưa tồn tại", [
            "summary_file" => $summaryFile
        ]);

        return true;
    }

    $state = readSummaryState($resultsDir);

    if ($state === null) {
        writeLog("SYSTEM_REBUILD_NEEDED", "Chưa có file trạng thái tổng hợp", [
            "state_file" => getSummaryStateFile($resultsDir)
        ]);

        return true;
    }

    if (($state["detail_file_count"] ?? -1) !== count($latestDetailFiles)) {
        writeLog("SYSTEM_REBUILD_NEEDED", "Số file chi tiết mới nhất thay đổi", [
            "old_count" => $state["detail_file_count"] ?? null,
            "new_count" => count($latestDetailFiles)
        ]);

        return true;
    }

    $currentFiles = [];

    foreach ($latestDetailFiles as $fileInfo) {
        $currentFiles[$fileInfo["name"]] = [
            "unit" => $fileInfo["unit"],
            "timestamp" => $fileInfo["timestamp"],
            "mtime" => $fileInfo["time"]
        ];
    }

    $stateFiles = [];

    foreach (($state["files"] ?? []) as $fileInfo) {
        if (!isset($fileInfo["name"])) {
            continue;
        }

        $stateFiles[$fileInfo["name"]] = [
            "unit" => $fileInfo["unit"] ?? "",
            "timestamp" => $fileInfo["timestamp"] ?? "",
            "mtime" => $fileInfo["mtime"] ?? 0
        ];
    }

    if ($currentFiles !== $stateFiles) {
        writeLog("SYSTEM_REBUILD_NEEDED", "Danh sách file chi tiết mới nhất đã thay đổi", [
            "current_file_count" => count($currentFiles),
            "state_file_count" => count($stateFiles)
        ]);

        return true;
    }

    return false;
}

if (!is_dir($resultsDir)) {
    if (mkdir($resultsDir, 0777, true)) {
        writeLog("SYSTEM_RESULTS_DIR_CREATED", "Đã tạo thư mục results", [
            "dir" => $resultsDir
        ]);
    } else {
        writeLog("SYSTEM_RESULTS_DIR_CREATE_ERROR", "Không thể tạo thư mục results", [
            "dir" => $resultsDir
        ], "ERROR");
    }
}

$latestDetailFiles = getLatestDetailFiles($resultsDir);

if (shouldRebuildSummary($resultsDir, $summaryFile, $latestDetailFiles)) {
    if (rebuildSummaryFile($resultsDir, $summaryFile, $latestDetailFiles)) {
        writeSummaryState($resultsDir, $latestDetailFiles);
    }
}

if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $resultsDir . $file;

    if (
        file_exists($filepath) &&
        is_file($filepath) &&
        strpos(realpath($filepath), realpath($resultsDir)) === 0
    ) {
        writeLog("ADMIN_DOWNLOAD_FILE", "Admin/lãnh đạo tải file kết quả", [
            "file" => $file,
            "path" => $filepath
        ]);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    } else {
        writeLog("ADMIN_DOWNLOAD_FILE_BLOCKED", "Tải file bị chặn hoặc file không tồn tại", [
            "file" => $file,
            "path" => $filepath
        ], "WARN");
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

writeLog("ADMIN_DASHBOARD_RENDER", "Dashboard được render", [
    "unique_organizations" => $uniqueOrganizations,
    "detail_files_count" => $detailFilesCount,
    "has_summary_file" => file_exists($summaryFile),
    "has_summary_state" => file_exists(getSummaryStateFile($resultsDir))
]);
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
                💡 Dashboard bao gồm <strong>results.tsv</strong> là <strong>file tổng hợp</strong> được xếp ở đầu và <strong>file chi tiết mới nhất của mỗi đơn vị</strong> theo thứ tự thời gian điền.
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