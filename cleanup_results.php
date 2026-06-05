<?php
/**
 * Script để làm sạch file results.tsv
 * Chỉ giữ lại kết quả cuối cùng (mới nhất) của mỗi tổ chức
 */

$summaryFile = "results/results.tsv";

if (!file_exists($summaryFile)) {
    die("File $summaryFile không tồn tại.\n");
}

$fileContent = file_get_contents($summaryFile);

// Loại bỏ BOM nếu có
if (substr($fileContent, 0, 3) === "\xEF\xBB\xBF") {
    $fileContent = substr($fileContent, 3);
}

$lines = explode("\n", $fileContent);

// Lấy header (dòng đầu tiên)
$headerLine = array_shift($lines);

// Nhóm dữ liệu theo tổ chức (cột 2)
$dataByOrg = [];
$organizationTimestamps = [];

foreach ($lines as $line) {
    if (trim($line) === "") continue;
    
    $columns = explode("\t", $line);
    
    if (count($columns) < 2) continue;
    
    $timestamp = $columns[0]; // Cột 0: Thời gian
    $organization = $columns[1]; // Cột 1: Tổ chức
    
    // Tạo key để nhóm theo tổ chức và thời gian
    if (!isset($organizationTimestamps[$organization])) {
        $organizationTimestamps[$organization] = $timestamp;
        $dataByOrg[$organization] = [];
    }
    
    // Cập nhật timestamp nếu mới hơn
    if ($timestamp > $organizationTimestamps[$organization]) {
        // Có submission mới hơn, xóa submission cũ
        $dataByOrg[$organization] = [];
        $organizationTimestamps[$organization] = $timestamp;
    }
    
    // Chỉ thêm dòng nếu timestamp khớp (là submission mới nhất)
    if ($timestamp === $organizationTimestamps[$organization]) {
        $dataByOrg[$organization][] = $line;
    }
}

// Viết lại file với dữ liệu đã lọc
$fpSummary = fopen($summaryFile, "w");
if ($fpSummary) {
    // Ghi BOM cho UTF-8
    fwrite($fpSummary, "\xEF\xBB\xBF");
    
    // Ghi header
    fwrite($fpSummary, $headerLine . "\n");
    
    // Ghi dữ liệu từng tổ chức (sắp xếp theo timestamp)
    $sortedOrgs = [];
    foreach ($dataByOrg as $org => $rows) {
        $sortedOrgs[$org] = $organizationTimestamps[$org];
    }
    asort($sortedOrgs);
    
    foreach (array_keys($sortedOrgs) as $org) {
        foreach ($dataByOrg[$org] as $line) {
            fwrite($fpSummary, $line . "\n");
        }
    }
    
    fclose($fpSummary);
    
    echo "✓ Đã làm sạch file results.tsv thành công!\n";
    echo "Giữ lại kết quả mới nhất của mỗi tổ chức:\n";
    foreach ($sortedOrgs as $org => $timestamp) {
        echo "  - $org: $timestamp (rows: " . count($dataByOrg[$org]) . ")\n";
    }
} else {
    echo "Lỗi: Không thể mở file $summaryFile để ghi.\n";
}
?>
