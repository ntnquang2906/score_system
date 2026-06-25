<?php
session_start();

require_once 'logger.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    writeLog("ADMIN_BLOCKED_ACCESS", "Truy cập trang xem file bị chặn do chưa đăng nhập", [
        "target" => "view_file.php",
        "file" => $_GET['file'] ?? ""
    ], "WARN");

    header("Location: login.php");
    exit();
}

$canEdit = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'editor';

$resultsDir = "results/";

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

$file = $_GET['file'] ?? '';
$file = basename($file);
$filepath = $resultsDir . $file;

if (!file_exists($filepath) || !is_file($filepath)) {
    writeLog("ADMIN_VIEW_FILE_NOT_FOUND", "File cần xem không tồn tại", [
        "file" => $file,
        "path" => $filepath
    ], "WARN");

    die("File không tồn tại!");
}

if (strpos(realpath($filepath), realpath($resultsDir)) !== 0) {
    writeLog("ADMIN_VIEW_FILE_BLOCKED", "Truy cập file bị chặn do không nằm trong thư mục results", [
        "file" => $file,
        "path" => $filepath
    ], "WARN");

    die("Quyền truy cập bị từ chối!");
}

$content = removeBom(file_get_contents($filepath));
$data = readTsvFile($filepath);

writeLog("ADMIN_VIEW_FILE", "Admin/lãnh đạo xem file kết quả", [
    "file" => $file,
    "row_count" => max(count($data) - 1, 0),
    "can_edit" => $canEdit
]);

if (isset($_GET['export']) && $_GET['export'] === '1') {
    writeLog("ADMIN_EXPORT_FILE", "Admin/lãnh đạo xuất/tải file từ trang xem", [
        "file" => $file,
        "row_count" => max(count($data) - 1, 0)
    ]);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: must-revalidate, max-age=0');

    echo "\xEF\xBB\xBF";
    echo $content;
    exit();
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($file); ?> - Dashboard</title>
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
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .header h1 {
            font-size: 20px;
            word-break: break-word;
        }

        .header a {
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            white-space: nowrap;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .toolbar a,
        .toolbar button {
            background-color: #667eea;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .toolbar .edit-btn {
            background-color: #ffc107;
            color: #333;
        }

        .file-info {
            color: #666;
            font-size: 13px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .data-table {
            overflow-x: auto;
        }

        table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: auto;
        }

        table thead {
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th {
            padding: 10px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            background-color: #f8f9fa;
            min-width: 110px;
        }

        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 320px;
        }

        table tr:hover {
            background-color: #f8f9fa;
        }

        .row-number {
            background-color: #f0f0f0;
            text-align: center;
            font-weight: bold;
            width: 45px;
            min-width: 45px;
            position: sticky;
            left: 0;
            z-index: 5;
        }

        th.row-number {
            z-index: 11;
        }

        .role-note {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            background: #e9ecef;
            color: #333;
            font-size: 12px;
            margin-left: 5px;
        }

        .empty-message {
            color: #999;
            padding: 20px;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container">
            <h1>📄 <?php echo htmlspecialchars($file); ?></h1>
            <a href="dashboard.php">← Quay lại Dashboard</a>
        </div>
    </div>

    <div class="container">
        <div class="section">
            <div class="toolbar">
                <a href="?file=<?php echo urlencode($file); ?>&export=1">⬇️ Tải xuống Excel</a>
                <a href="?file=<?php echo urlencode($file); ?>" onclick="window.print(); return false;">🖨️ In</a>

                <?php if ($canEdit && $file !== 'results.tsv'): ?>
                    <a class="edit-btn" href="edit_file.php?file=<?php echo urlencode($file); ?>">✏️ Chỉnh sửa</a>
                <?php endif; ?>
            </div>

            <div class="file-info">
                📁 <strong><?php echo htmlspecialchars($file); ?></strong>
                <?php if ($canEdit): ?>
                    <span class="role-note">Tài khoản có quyền sửa</span>
                <?php else: ?>
                    <span class="role-note">Chỉ xem</span>
                <?php endif; ?>
                <br>
                📊 Tổng <?php echo max(count($data) - 1, 0); ?> dòng dữ liệu |
                ⏰ Cập nhật: <?php echo date('d/m/Y H:i:s', filemtime($filepath)); ?>
            </div>

            <?php if (empty($data)): ?>
                <div class="empty-message">File không có dữ liệu.</div>
            <?php else: ?>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="row-number">#</th>
                                <?php foreach ($data[0] as $header): ?>
                                    <th><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($data, 1) as $index => $row): ?>
                                <tr>
                                    <td class="row-number"><?php echo $index + 1; ?></td>
                                    <?php foreach ($data[0] as $colIndex => $header): ?>
                                        <td><?php echo htmlspecialchars($row[$colIndex] ?? ""); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>