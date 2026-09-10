<?php
// Đọc/ghi file kết quả dạng TSV (tab-separated, có BOM UTF-8) và phân tích
// tên file chi tiết. Dùng chung bởi process.php, dashboard.php, view_file.php,
// edit_file.php, unit_dashboard.php.

require_once __DIR__ . '/logger.php';

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

// Tên file kết quả chi tiết có dạng: {Ymd_His}_{TenDonViDaChuanHoa}.tsv
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
