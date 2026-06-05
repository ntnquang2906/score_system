<?php
session_start();

// Kiểm tra đăng nhập
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Xử lý tải file
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    
    // Validate đường dẫn file để tránh directory traversal
    $file = basename($file);
    $filepath = "results/" . $file;
    
    // Kiểm tra file tồn tại và nằm trong thư mục results
    if (file_exists($filepath) && strpos(realpath($filepath), realpath("results/")) === 0) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    }
}

// Lấy danh sách file trong thư mục results
$resultsDir = "results/";
$files = [];

if (is_dir($resultsDir)) {
    $items = array_diff(scandir($resultsDir), array('.', '..'));
    foreach ($items as $item) {
        $filepath = $resultsDir . $item;
        if (is_file($filepath)) {
            $files[] = [
                'name' => $item,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'modified' => date('d/m/Y H:i:s', filemtime($filepath))
            ];
        }
    }
    // Sắp xếp theo thời gian, file mới nhất trước
    usort($files, function ($a, $b) {
        return $b['time'] - $a['time'];
    });
}

// Lấy nội dung file tổng hợp results.tsv để hiển thị preview
$summaryFile = "results/results.tsv";
$summaryData = [];
$summaryRows = 0;
$uniqueOrganizations = 0;

if (file_exists($summaryFile)) {
    $content = file_get_contents($summaryFile);
    // Xóa BOM nếu có
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    
    $lines = explode("\n", $content);
    $summaryRows = count($lines) - 1; // Trừ header
    
    // Đếm số lượng đơn vị duy nhất (cột Tổ chức - cột thứ 2, index 1)
    $organizations = [];
    for ($i = 1; $i < count($lines); $i++) {
        if (!empty(trim($lines[$i]))) {
            $parts = explode("\t", $lines[$i]);
            if (isset($parts[1]) && !empty(trim($parts[1]))) {
                $org = trim($parts[1]);
                $organizations[$org] = true;
            }
        }
    }
    $uniqueOrganizations = count($organizations);
    
    // Hiển thị 10 dòng gần đây nhất
    $displayLines = array_slice($lines, max(0, count($lines) - 11), 11);
    
    foreach ($displayLines as $line) {
        if (!empty(trim($line))) {
            $summaryData[] = explode("\t", $line);
        }
    }
}

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
            transition: background-color 0.3s;
        }

        .header .logout-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
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

        .file-list {
            overflow-x: auto;
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

        .data-preview {
            overflow-x: auto;
            margin-top: 15px;
        }

        .data-preview table {
            font-size: 11px;
            table-layout: fixed;
        }

        .data-preview th,
        .data-preview td {
            padding: 6px 8px;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .data-preview th:nth-child(19) { width: 100px; } /* Minh chứng - thu nhỏ */

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
        }

        .summary-preview {
            color: #666;
            font-size: 13px;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="container">
            <h1>📊 Dashboard - Kết quả đánh giá KH&CN</h1>
            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
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
                    <div class="label">Tổng số file riêng lẻ</div>
                    <div class="value"><?php echo count($files); ?></div>
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
                💡 Nhấn <strong>Tải xuống</strong> để lưu file, hoặc <strong>Xem</strong> để xem nội dung trực tuyến
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
                                <td><?php echo htmlspecialchars($file['name']); ?></td>
                                <td class="file-size"><?php echo number_format($file['size'], 0) . ' bytes'; ?></td>
                                <td><?php echo $file['modified']; ?></td>
                                <td>
                                    <a href="view_file.php?file=<?php echo urlencode($file['name']); ?>" class="view-btn">Xem</a>
                                    <a href="?download=<?php echo urlencode($file['name']); ?>" class="download-btn">Tải xuống</a>
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
