<?php
session_start();

// Kiểm tra đăng nhập
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$file = $_GET['file'] ?? '';
$file = basename($file); // Validate
$filepath = "results/" . $file;

// Kiểm tra file tồn tại
if (!file_exists($filepath) || !is_file($filepath)) {
    die("File không tồn tại!");
}

// Kiểm tra file nằm trong thư mục results
if (strpos(realpath($filepath), realpath("results/")) !== 0) {
    die("Quyền truy cập bị từ chối!");
}

// Đọc nội dung file
$content = file_get_contents($filepath);

// Xóa BOM nếu có
if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    $content = substr($content, 3);
}

// Chuyển đổi TSV thành mảng
$lines = explode("\n", $content);
$data = [];
foreach ($lines as $line) {
    if (!empty(trim($line))) {
        $data[] = explode("\t", $line);
    }
}

// Nếu có tham số export, tải file
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: must-revalidate, max-age=0');
    
    echo "\xEF\xBB\xBF"; // BOM cho Excel
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
        }

        .header h1 {
            font-size: 20px;
        }

        .header a {
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
            border: 1px solid white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            transition: background-color 0.3s;
        }

        .header a:hover {
            background-color: rgba(255, 255, 255, 0.3);
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
            transition: background-color 0.3s;
        }

        .toolbar a:hover,
        .toolbar button:hover {
            background-color: #764ba2;
        }

        .file-info {
            color: #666;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .data-table {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: fixed;
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
        }

        table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        table tr:hover {
            background-color: #f8f9fa;
        }

        .row-number {
            background-color: #f0f0f0;
            text-align: center;
            font-weight: bold;
            width: 40px;
        }

        /* Tối ưu chiều rộng cột */
        td:nth-child(1) { width: 40px; }  /* # */
        td:nth-child(2) { width: 120px; } /* Thời gian */
        td:nth-child(3) { width: 150px; } /* Tổ chức */
        td:nth-child(4) { width: 100px; } /* Chức năng */
        td:nth-child(5) { width: 80px; }  /* Trọng số */
        td:nth-child(6) { width: 50px; }  /* ĐT1 */
        td:nth-child(7) { width: 50px; }  /* ĐT2 */
        td:nth-child(8) { width: 50px; }  /* ĐT3 */
        td:nth-child(9) { width: 50px; }  /* ĐT4 */
        td:nth-child(10) { width: 50px; } /* ĐT */
        td:nth-child(11) { width: 60px; } /* Điểm quy đổi */
        td:nth-child(12) { width: 60px; } /* Tổng E */
        td:nth-child(13) { width: 60px; } /* Xếp loại */
        td:nth-child(14) { width: 100px; } /* Nhóm */
        td:nth-child(15) { width: 120px; } /* Câu hỏi */
        td:nth-child(16) { width: 60px; } /* Có/Không */
        td:nth-child(17) { width: 60px; } /* Điểm câu hỏi */
        td:nth-child(18) { width: 80px; } /* Chú thích */
        td:nth-child(19) { width: 100px; } /* Minh chứng */

        th:nth-child(1) { width: 40px; }
        th:nth-child(2) { width: 120px; }
        th:nth-child(3) { width: 150px; }
        th:nth-child(4) { width: 100px; }
        th:nth-child(5) { width: 80px; }
        th:nth-child(6) { width: 50px; }
        th:nth-child(7) { width: 50px; }
        th:nth-child(8) { width: 50px; }
        th:nth-child(9) { width: 50px; }
        th:nth-child(10) { width: 50px; }
        th:nth-child(11) { width: 60px; }
        th:nth-child(12) { width: 60px; }
        th:nth-child(13) { width: 60px; }
        th:nth-child(14) { width: 100px; }
        th:nth-child(15) { width: 120px; }
        th:nth-child(16) { width: 60px; }
        th:nth-child(17) { width: 60px; }
        th:nth-child(18) { width: 80px; }
        th:nth-child(19) { width: 100px; }
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
            </div>

            <div class="file-info">
                📁 <strong><?php echo htmlspecialchars($file); ?></strong> |
                📊 Tổng <?php echo count($data); ?> dòng |
                ⏰ Cập nhật: <?php echo date('d/m/Y H:i:s', filemtime($filepath)); ?>
            </div>

            <div class="data-table">
                <table>
                    <thead>
                        <?php if (!empty($data)): ?>
                            <tr>
                                <th class="row-number">#</th>
                                <?php foreach ($data[0] as $header): ?>
                                    <th><?php echo htmlspecialchars($header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($data, 1) as $index => $row): ?>
                            <tr>
                                <td class="row-number"><?php echo $index + 1; ?></td>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>
