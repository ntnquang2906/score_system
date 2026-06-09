<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'editor') {
    die("Bạn không có quyền sửa file kết quả.");
}

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
    if (!preg_match('/^(\d{8}_\d{6})_(.+)\.tsv$/u', $file, $matches)) {
        return null;
    }

    if ($file === "results.tsv") {
        return null;
    }

    return [
        'timestamp' => $matches[1],
        'unit' => $matches[2]
    ];
}

function normalizeVietnameseKeepCase($text)
{
    $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',

        'À'=>'A','Á'=>'A','Ạ'=>'A','Ả'=>'A','Ã'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ậ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ặ'=>'A','Ẳ'=>'A','Ẵ'=>'A',
        'È'=>'E','É'=>'E','Ẹ'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ệ'=>'E','Ể'=>'E','Ễ'=>'E',
        'Ì'=>'I','Í'=>'I','Ị'=>'I','Ỉ'=>'I','Ĩ'=>'I',
        'Ò'=>'O','Ó'=>'O','Ọ'=>'O','Ỏ'=>'O','Õ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ộ'=>'O','Ổ'=>'O','Ỗ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ợ'=>'O','Ở'=>'O','Ỡ'=>'O',
        'Ù'=>'U','Ú'=>'U','Ụ'=>'U','Ủ'=>'U','Ũ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ự'=>'U','Ử'=>'U','Ữ'=>'U',
        'Ỳ'=>'Y','Ý'=>'Y','Ỵ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Đ'=>'D'
    ];

    $text = trim($text);
    $text = strtr($text, $map);

    // Ký tự nguy hiểm cho tên file
    $text = preg_replace('/[\/\\\\:\*\?"<>\|]+/u', '_', $text);

    // Các dấu phân cách phổ biến chuyển thành _
    $text = preg_replace('/[\s\-,;]+/u', '_', $text);

    // Chỉ giữ chữ, số, dấu gạch dưới, dấu chấm
    $text = preg_replace('/[^A-Za-z0-9_.]+/u', '_', $text);

    $text = preg_replace('/_+/u', '_', $text);
    $text = trim($text, '._');

    return $text;
}

$file = $_GET['file'] ?? $_POST['file'] ?? "";
$file = basename($file);

if ($file === "results.tsv") {
    die("Không chỉnh sửa trực tiếp file tổng hợp. File results.tsv được tự động tạo lại từ các file chi tiết.");
}

$parsed = parseDetailFilename($file);

if ($parsed === null) {
    die("Tên file không đúng định dạng timestamp_ten_don_vi.tsv.");
}

$filepath = $resultsDir . $file;

if (!file_exists($filepath) || !is_file($filepath)) {
    die("File không tồn tại.");
}

if (strpos(realpath($filepath), realpath($resultsDir)) !== 0) {
    die("Quyền truy cập bị từ chối.");
}

$message = "";
$error = "";

if (isset($_GET['renamed']) && $_GET['renamed'] === "1") {
    $message = "Đã đổi tên đơn vị thành công.";
}

$data = readTsvFile($filepath);
$header = $data[0] ?? [];
$rows = array_slice($data, 1);

$currentUnitName = $parsed['unit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedRows = $_POST['rows'] ?? [];
    $newUnitInput = $_POST['unit_name'] ?? $currentUnitName;

    $newUnitName = normalizeVietnameseKeepCase($newUnitInput);

    if ($newUnitName === "") {
        $error = "Tên đơn vị không hợp lệ.";
    } else {
        $newData = [];
        $newData[] = $header;

        foreach ($postedRows as $row) {
            $newRow = [];

            foreach ($header as $colIndex => $colName) {
                $newRow[] = trim($row[$colIndex] ?? "");
            }

            $newData[] = $newRow;
        }

        $saved = writeTsvFile($filepath, $newData);

        if ($saved) {
            $message = "Đã lưu nội dung file chi tiết thành công.";

            $newFilename = $parsed['timestamp'] . "_" . $newUnitName . ".tsv";

            if ($newFilename !== $file) {
                $newFilepath = $resultsDir . $newFilename;

                if (file_exists($newFilepath)) {
                    $error = "Tên file sau khi đổi đã tồn tại: " . $newFilename;
                } else {
                    if (rename($filepath, $newFilepath)) {
                        header("Location: edit_file.php?file=" . urlencode($newFilename) . "&renamed=1");
                        exit();
                    } else {
                        $error = "Không thể đổi tên file. Vui lòng kiểm tra quyền thư mục results.";
                    }
                }
            }

            $data = readTsvFile($filepath);
            $header = $data[0] ?? [];
            $rows = array_slice($data, 1);
            $currentUnitName = $newUnitName;
        } else {
            $error = "Không thể ghi file. Vui lòng kiểm tra quyền thư mục results.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Chỉnh sửa file - <?php echo htmlspecialchars($file); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            margin: 0;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }

        .header-inner {
            max-width: 1500px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: center;
        }

        .header h1 {
            font-size: 20px;
            margin: 0;
            word-break: break-word;
        }

        .header a {
            color: white;
            text-decoration: none;
            border: 1px solid white;
            padding: 8px 14px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.2);
            white-space: nowrap;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 20px;
        }

        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
        }

        .notice {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            line-height: 1.6;
        }

        .filename-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .filename-box label {
            font-weight: bold;
            display: block;
            margin-bottom: 6px;
        }

        .filename-box input {
            width: 100%;
            max-width: 700px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-family: Arial, sans-serif;
        }

        .filename-box small {
            display: block;
            color: #666;
            margin-top: 6px;
            line-height: 1.5;
        }

        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .btn {
            border: none;
            padding: 10px 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }

        .save-btn {
            background: #28a745;
            color: white;
        }

        .back-btn {
            background: #6c757d;
            color: white;
        }

        .view-btn {
            background: #17a2b8;
            color: white;
        }

        .table-wrap {
            overflow-x: auto;
            max-height: 75vh;
            border: 1px solid #ddd;
        }

        table {
            border-collapse: collapse;
            width: max-content;
            min-width: 100%;
            font-size: 12px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 5;
            min-width: 120px;
        }

        .row-number {
            background: #f0f0f0;
            font-weight: bold;
            text-align: center;
            min-width: 45px;
            position: sticky;
            left: 0;
            z-index: 4;
        }

        th.row-number {
            z-index: 6;
        }

        textarea {
            width: 180px;
            min-height: 70px;
            resize: vertical;
            font-family: Arial, sans-serif;
            font-size: 12px;
            padding: 6px;
        }

        .wide textarea {
            width: 260px;
            min-height: 90px;
        }

        .small textarea {
            width: 100px;
            min-height: 55px;
        }

        .readonly-header {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-inner">
            <h1>✏️ Chỉnh sửa file: <?php echo htmlspecialchars($file); ?></h1>
            <a href="dashboard.php">← Dashboard</a>
        </div>
    </div>

    <div class="container">
        <div class="section">
            <?php if ($message): ?>
                <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="notice info">
                Nguồn dữ liệu gốc là <strong>file chi tiết</strong>. File <strong>results.tsv</strong> sẽ tự động được tạo lại từ các file chi tiết mới nhất khi mở Dashboard.
                <br>
                Dòng tiêu đề cột chỉ đọc để tránh làm hỏng cấu trúc file.
            </div>

            <?php if (empty($data)): ?>
                <p>File không có dữ liệu.</p>
            <?php else: ?>
                <form method="POST" id="editForm">
                    <input type="hidden" name="file" value="<?php echo htmlspecialchars($file); ?>">

                    <div class="filename-box">
                        <label>Tên đơn vị</label>
                        <input
                            type="text"
                            name="unit_name"
                            value="<?php echo htmlspecialchars($currentUnitName); ?>">
                        <small>
                            Khi lưu, hệ thống sẽ giữ timestamp và chuẩn hóa tên file:
                            bỏ dấu tiếng Việt, thay khoảng trắng bằng dấu gạch dưới, giữ nguyên hoa/thường.
                            <br>
                            Ví dụ: <strong>Viện Công nghệ thông tin</strong> → <strong>Vien_Cong_nghe_thong_tin</strong>
                        </small>
                    </div>

                    <div class="toolbar">
                        <button type="submit" form="editForm" class="btn save-btn">💾 Lưu thay đổi</button>
                        <a href="view_file.php?file=<?php echo urlencode($file); ?>" class="btn view-btn">👁️ Xem file</a>
                        <a href="dashboard.php" class="btn back-btn">← Quay lại</a>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th class="row-number">#</th>
                                    <?php foreach ($header as $colIndex => $colName): ?>
                                        <th><?php echo htmlspecialchars($colName); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <tr class="readonly-header">
                                    <td class="row-number">Header</td>
                                    <?php foreach ($header as $colName): ?>
                                        <td><?php echo htmlspecialchars($colName); ?></td>
                                    <?php endforeach; ?>
                                </tr>

                                <?php foreach ($rows as $rowIndex => $row): ?>
                                    <tr>
                                        <td class="row-number"><?php echo $rowIndex + 1; ?></td>

                                        <?php foreach ($header as $colIndex => $colName): ?>
                                            <?php
                                            $value = $row[$colIndex] ?? "";
                                            $headerLower = mb_strtolower($colName, 'UTF-8');

                                            $class = "";

                                            if (
                                                strpos($headerLower, "câu hỏi") !== false ||
                                                strpos($headerLower, "chú thích") !== false ||
                                                strpos($headerLower, "minh chứng") !== false
                                            ) {
                                                $class = "wide";
                                            } elseif (
                                                strpos($headerLower, "đt") !== false ||
                                                strpos($headerLower, "điểm") !== false ||
                                                strpos($headerLower, "trọng số") !== false
                                            ) {
                                                $class = "small";
                                            }
                                            ?>

                                            <td class="<?php echo $class; ?>">
                                                <textarea name="rows[<?php echo $rowIndex; ?>][<?php echo $colIndex; ?>]"><?php echo htmlspecialchars($value); ?></textarea>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>