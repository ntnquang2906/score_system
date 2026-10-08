<?php
session_start();

require_once 'includes/logger.php';
require_once 'includes/lang.php';
require_once 'includes/tsv.php';
require_once 'includes/vietnamese.php';

initLang();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    writeLog("ADMIN_BLOCKED_ACCESS", "Truy cập trang sửa file bị chặn do chưa đăng nhập", [
        "target" => "edit_file.php",
        "file" => $_GET['file'] ?? $_POST['file'] ?? ""
    ], "WARN");

    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'editor') {
    writeLog("ADMIN_EDIT_BLOCKED", "Tài khoản không có quyền sửa file", [
        "username" => $_SESSION['admin_username'] ?? "",
        "role" => $_SESSION['admin_role'] ?? "",
        "file" => $_GET['file'] ?? $_POST['file'] ?? ""
    ], "WARN");

    die(t('edit.no_permission'));
}

$resultsDir = "results/";

$file = $_GET['file'] ?? $_POST['file'] ?? "";
$file = basename($file);

if ($file === "results.tsv") {
    writeLog("ADMIN_EDIT_SUMMARY_BLOCKED", "Chặn sửa trực tiếp file tổng hợp", [
        "file" => $file
    ], "WARN");

    die(t('edit.block_summary'));
}

$parsed = parseDetailFilename($file);

if ($parsed === null) {
    writeLog("ADMIN_EDIT_INVALID_FILENAME", "Tên file không đúng định dạng", [
        "file" => $file
    ], "WARN");

    die(t('edit.invalid_filename'));
}

$filepath = $resultsDir . $file;

if (!file_exists($filepath) || !is_file($filepath)) {
    writeLog("ADMIN_EDIT_FILE_NOT_FOUND", "File cần sửa không tồn tại", [
        "file" => $file,
        "path" => $filepath
    ], "WARN");

    die(t('edit.file_not_found'));
}

if (strpos(realpath($filepath), realpath($resultsDir)) !== 0) {
    writeLog("ADMIN_EDIT_FILE_BLOCKED", "Truy cập file sửa bị chặn do không nằm trong thư mục results", [
        "file" => $file,
        "path" => $filepath
    ], "WARN");

    die(t('edit.access_denied'));
}

writeLog("ADMIN_EDIT_PAGE_ACCESS", "Admin/lãnh đạo truy cập trang sửa file", [
    "file" => $file,
    "username" => $_SESSION['admin_username'] ?? ""
]);

$message = "";
$error = "";

if (isset($_GET['renamed']) && $_GET['renamed'] === "1") {
    $message = t('edit.renamed_success');
}

$data = readTsvFile($filepath);
$header = $data[0] ?? [];
$rows = array_slice($data, 1);

$currentUnitName = $parsed['unit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedRows = $_POST['rows'] ?? [];
    $newUnitInput = $_POST['unit_name'] ?? $currentUnitName;

    $newUnitName = normalizeVietnameseKeepCase($newUnitInput);
    $newFilename = $parsed['timestamp'] . "_" . $newUnitName . ".tsv";
    $newFilepath = $resultsDir . $newFilename;
    $isRename = ($newFilename !== $file);

    writeLog("ADMIN_EDIT_SUBMIT", "Admin/lãnh đạo gửi form sửa file", [
        "file" => $file,
        "old_unit" => $currentUnitName,
        "new_unit_input" => $newUnitInput,
        "new_unit_normalized" => $newUnitName,
        "is_rename" => $isRename,
        "posted_row_count" => count($postedRows),
        "old_row_count" => count($rows)
    ]);

    if ($newUnitName === "") {
        $error = t('edit.invalid_unit_name');

        writeLog("ADMIN_EDIT_VALIDATE_FAIL", "Tên đơn vị mới không hợp lệ", [
            "file" => $file,
            "new_unit_input" => $newUnitInput
        ], "WARN");
    } elseif ($isRename) {
        /*
         * Đổi tên file:
         * - Chỉ copy nguyên file vật lý sang tên mới.
         * - Không đọc/ghi lại TSV.
         * - Không dùng dữ liệu rows từ form.
         * - Không xóa file cũ để đảm bảo dữ liệu gốc vẫn còn trên server.
         */
        if (file_exists($newFilepath)) {
            $error = t('edit.filename_exists', ['file' => $newFilename]);

            writeLog("ADMIN_RENAME_COPY_CONFLICT", "Không đổi tên vì file mới đã tồn tại", [
                "old_file" => $file,
                "new_file" => $newFilename,
                "old_path" => $filepath,
                "new_path" => $newFilepath
            ], "WARN");
        } else {
            $copied = copy($filepath, $newFilepath);

            if ($copied && file_exists($newFilepath) && filesize($newFilepath) > 0) {
                writeLog("ADMIN_RENAME_COPY_SUCCESS", "Đã đổi tên file bằng cách copy nguyên nội dung", [
                    "old_file" => $file,
                    "new_file" => $newFilename,
                    "old_path" => $filepath,
                    "new_path" => $newFilepath,
                    "old_size" => filesize($filepath),
                    "new_size" => filesize($newFilepath)
                ]);

                header("Location: edit_file.php?file=" . urlencode($newFilename) . "&renamed=1");
                exit();
            } else {
                if (file_exists($newFilepath) && filesize($newFilepath) === 0) {
                    @unlink($newFilepath);
                }

                $error = t('edit.rename_create_failed');

                writeLog("ADMIN_RENAME_COPY_ERROR", "Không thể copy file khi đổi tên", [
                    "old_file" => $file,
                    "new_file" => $newFilename,
                    "old_path" => $filepath,
                    "new_path" => $newFilepath
                ], "ERROR");
            }
        }
    } else {
        /*
         * Không đổi tên:
         * Đây mới là thao tác sửa nội dung file.
         */
        if (empty($header)) {
            $error = t('edit.no_header_row');

            writeLog("ADMIN_EDIT_VALIDATE_FAIL", "File không có header", [
                "file" => $file,
                "path" => $filepath
            ], "ERROR");
        } elseif (empty($postedRows) && count($rows) > 0) {
            $error = t('edit.empty_post_blocked');

            writeLog("ADMIN_EDIT_EMPTY_POST_BLOCKED", "Chặn lưu vì dữ liệu POST rỗng khi sửa nội dung", [
                "file" => $file,
                "path" => $filepath,
                "old_row_count" => count($rows)
            ], "ERROR");
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
                $message = t('edit.save_success');

                writeLog("ADMIN_EDIT_FILE_SAVED", "Đã lưu nội dung file chi tiết", [
                    "file" => $file,
                    "path" => $filepath,
                    "row_count" => max(count($newData) - 1, 0)
                ]);

                $data = readTsvFile($filepath);
                $header = $data[0] ?? [];
                $rows = array_slice($data, 1);
                $currentUnitName = $newUnitName;
            } else {
                $error = t('edit.save_failed');

                writeLog("ADMIN_EDIT_FILE_SAVE_ERROR", "Không thể ghi file chi tiết", [
                    "file" => $file,
                    "path" => $filepath
                ], "ERROR");
            }
        }
    }
}
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
    <title><?php echo t('edit.title', ['file' => htmlspecialchars($file)]); ?></title>
    <link rel="stylesheet" href="i18n.css">
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
            position: sticky;
            top: 0;
            z-index: 1000;
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
            <h1><?php echo t('edit.h1', ['file' => htmlspecialchars($file)]); ?></h1>
            <div style="display:flex; gap:10px; align-items:center;">
                <?php echo langSwitchLinks(true); ?>
                <a href="dashboard.php"><?php echo t('edit.back_dashboard'); ?></a>
            </div>
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
                <?php echo t('edit.info_box'); ?>
            </div>

            <?php if (empty($data)): ?>
                <p><?php echo t('edit.no_data'); ?></p>
            <?php else: ?>
                <form method="POST" id="editForm" action="edit_file.php?file=<?php echo urlencode($file); ?>">
                    <input type="hidden" name="file" value="<?php echo htmlspecialchars($file); ?>">

                    <div class="filename-box">
                        <label><?php echo t('edit.unit_name_label'); ?></label>
                        <input
                            type="text"
                            name="unit_name"
                            value="<?php echo htmlspecialchars($currentUnitName); ?>">
                        <small>
                            <?php echo t('edit.unit_name_help'); ?>
                        </small>
                    </div>

                    <div class="toolbar">
                        <button type="submit" class="btn save-btn"><?php echo t('edit.save_btn'); ?></button>
                        <a href="view_file.php?file=<?php echo urlencode($file); ?>" class="btn view-btn"><?php echo t('edit.view_btn'); ?></a>
                        <a href="dashboard.php" class="btn back-btn"><?php echo t('edit.back_btn'); ?></a>
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
                                    <td class="row-number"><?php echo t('edit.row_header_label'); ?></td>
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
                                            $headerLower = strtolower($colName);

                                            $class = "";

                                            if (
                                                strpos($headerLower, "câu hỏi") !== false ||
                                                strpos($headerLower, "chú thích") !== false ||
                                                strpos($headerLower, "minh chứng") !== false ||
                                                strpos($headerLower, "giải thích") !== false
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
    <script src="pwa-register.js"></script>
</body>

</html>