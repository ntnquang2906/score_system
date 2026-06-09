<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'editor') {
    die("Bạn không có quyền sửa file kết quả.");
}

function removeBom($content)
{
    return substr($content, 0, 3) === "\xEF\xBB\xBF" ? substr($content, 3) : $content;
}

function readTsvFile($filepath)
{
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

function rowToString($row)
{
    return implode("\t", $row);
}

function safeTsvFilename($filename)
{
    $filename = basename(trim($filename));
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

    if ($filename === "") {
        return "";
    }

    if (!str_ends_with(strtolower($filename), ".tsv")) {
        $filename .= ".tsv";
    }

    return $filename;
}

function syncDetailToSummary($oldData, $newData)
{
    $summaryFile = "results/results.tsv";

    if (!file_exists($summaryFile)) {
        return;
    }

    $summaryData = readTsvFile($summaryFile);

    if (empty($summaryData)) {
        return;
    }

    $header = $summaryData[0];
    $summaryRows = array_slice($summaryData, 1);

    $oldRows = array_slice($oldData, 1);
    $newRows = array_slice($newData, 1);

    $replaceMap = [];

    foreach ($oldRows as $index => $oldRow) {
        if (isset($newRows[$index])) {
            $replaceMap[rowToString($oldRow)] = $newRows[$index];
        }
    }

    $updatedRows = [];

    foreach ($summaryRows as $row) {
        $key = rowToString($row);

        if (isset($replaceMap[$key])) {
            $updatedRows[] = $replaceMap[$key];
        } else {
            $updatedRows[] = $row;
        }
    }

    $finalData = array_merge([$header], $updatedRows);
    writeTsvFile($summaryFile, $finalData);
}

function syncSummaryToDetails($oldData, $newData)
{
    $resultsDir = "results/";

    if (!is_dir($resultsDir)) {
        return;
    }

    $oldRows = array_slice($oldData, 1);
    $newRows = array_slice($newData, 1);

    $replaceMap = [];

    foreach ($oldRows as $index => $oldRow) {
        if (isset($newRows[$index])) {
            $replaceMap[rowToString($oldRow)] = $newRows[$index];
        }
    }

    $fileList = scandir($resultsDir);

    foreach ($fileList as $file) {
        if ($file === "." || $file === ".." || $file === "results.tsv") {
            continue;
        }

        if (!preg_match('/^\d{8}_\d{6}_.+\.tsv$/', $file)) {
            continue;
        }

        $filepath = $resultsDir . $file;

        if (!is_file($filepath)) {
            continue;
        }

        $detailData = readTsvFile($filepath);

        if (empty($detailData)) {
            continue;
        }

        $changed = false;
        $header = $detailData[0];
        $rows = array_slice($detailData, 1);
        $updatedRows = [];

        foreach ($rows as $row) {
            $key = rowToString($row);

            if (isset($replaceMap[$key])) {
                $updatedRows[] = $replaceMap[$key];
                $changed = true;
            } else {
                $updatedRows[] = $row;
            }
        }

        if ($changed) {
            $finalData = array_merge([$header], $updatedRows);
            writeTsvFile($filepath, $finalData);
        }
    }
}

$file = $_GET['file'] ?? $_POST['file'] ?? "";
$file = basename($file);

$filepath = "results/" . $file;

if (!file_exists($filepath) || !is_file($filepath)) {
    die("File không tồn tại.");
}

if (strpos(realpath($filepath), realpath("results/")) !== 0) {
    die("Quyền truy cập bị từ chối.");
}

$isSummaryFile = ($file === "results.tsv");

$message = "";
$error = "";

if (isset($_GET['renamed']) && $_GET['renamed'] === "1") {
    $message = "Đã đổi tên file thành công.";
}

$oldData = readTsvFile($filepath);
$data = $oldData;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedData = $_POST['data'] ?? [];

    if (empty($postedData)) {
        $error = "Không có dữ liệu để lưu.";
    } else {
        $newData = [];

        foreach ($postedData as $row) {
            $newRow = [];

            foreach ($row as $cell) {
                $newRow[] = trim($cell);
            }

            $newData[] = $newRow;
        }

        $saved = writeTsvFile($filepath, $newData);

        if ($saved) {
            if ($isSummaryFile) {
                syncSummaryToDetails($oldData, $newData);
            } else {
                syncDetailToSummary($oldData, $newData);
            }

            $message = "Đã lưu và đồng bộ dữ liệu thành công.";
            $data = readTsvFile($filepath);

            // Chỉ cho đổi tên file chi tiết, không cho đổi tên results.tsv
            if (!$isSummaryFile) {
                $newFilename = safeTsvFilename($_POST['new_filename'] ?? $file);

                if ($newFilename === "") {
                    $error = "Tên file mới không hợp lệ.";
                } elseif ($newFilename !== $file) {
                    $newFilepath = "results/" . $newFilename;

                    if (file_exists($newFilepath)) {
                        $error = "Tên file mới đã tồn tại. Vui lòng chọn tên khác.";
                    } else {
                        if (rename($filepath, $newFilepath)) {
                            header("Location: edit_file.php?file=" . urlencode($newFilename) . "&renamed=1");
                            exit();
                        } else {
                            $error = "Không thể đổi tên file. Vui lòng kiểm tra quyền thư mục results.";
                        }
                    }
                }
            }
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
            max-width: 600px;
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
                Khi lưu:
                <strong>
                    <?php echo $isSummaryFile
                        ? "file tổng hợp chỉ được sửa nội dung, không được đổi tên."
                        : "file chi tiết sẽ đồng bộ nội dung lên results.tsv. Tên file chi tiết cũng có thể được đổi để đồng nhất tên đơn vị."; ?>
                </strong>
            </div>

            <?php if (empty($data)): ?>
                <p>File không có dữ liệu.</p>
            <?php else: ?>
                <form method="POST" id="editForm">
                    <input type="hidden" name="file" value="<?php echo htmlspecialchars($file); ?>">

                    <div class="filename-box">
                        <label>Tên file</label>

                        <?php if ($isSummaryFile): ?>
                            <input type="text" value="<?php echo htmlspecialchars($file); ?>" disabled>
                            <small>
                                File <strong>results.tsv</strong> là file tổng hợp hệ thống nên không được đổi tên.
                            </small>
                        <?php else: ?>
                            <input
                                type="text"
                                name="new_filename"
                                value="<?php echo htmlspecialchars($file); ?>">
                            <small>
                                Chỉ nên đổi phần tên đơn vị để đồng nhất. Hệ thống sẽ tự giữ/ép đuôi <strong>.tsv</strong>.
                                Không dùng dấu cách/ký tự đặc biệt; nếu có, hệ thống sẽ tự chuyển thành dấu gạch dưới.
                            </small>
                        <?php endif; ?>
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
                                    <?php foreach ($data[0] as $header): ?>
                                        <th><?php echo htmlspecialchars($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($data as $rowIndex => $row): ?>
                                    <tr>
                                        <td class="row-number">
                                            <?php echo $rowIndex === 0 ? "Header" : $rowIndex; ?>
                                        </td>

                                        <?php foreach ($data[0] as $colIndex => $header): ?>
                                            <?php
                                            $value = $row[$colIndex] ?? "";
                                            $headerLower = mb_strtolower($header, 'UTF-8');

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
                                                <textarea name="data[<?php echo $rowIndex; ?>][<?php echo $colIndex; ?>]"><?php echo htmlspecialchars($value); ?></textarea>
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