<?php
require_once 'logger.php';

function failWithLog($message, $context = [])
{
    writeLog("FORM_SUBMIT_ERROR", $message, $context, "ERROR");
    die($message);
}

function clampScore($score, $max)
{
    return min(max((float) $score, 0), (float) $max);
}

function getInput($answers, $name)
{
    return isset($answers['inputs'][$name]) ? (float) $answers['inputs'][$name] : 0;
}

function isEqualFloat($a, $b)
{
    return abs($a - $b) < 0.00001;
}

function midpointScore($value, $range)
{
    if ($range['max'] === null) {
        return $range['score'] ?? $range['score_max'] ?? 0;
    }

    $mid = ((float) $range['min'] + (float) $range['max']) / 2;

    if ($value < $mid) return $range['score_min'];

    if (isEqualFloat($value, $mid)) {
        return $range['score_mid'] ?? (($range['score_min'] + $range['score_max']) / 2);
    }

    return $range['score_max'];
}

function scoreByRanges($value, $ranges)
{
    foreach ($ranges as $r) {
        $min = (float) $r['min'];
        $max = $r['max'];

        if ($max === null) {
            if ($value >= $min) return $r['score'] ?? $r['score_max'] ?? 0;
        } else {
            if ($value >= $min && $value <= (float) $max) {
                return midpointScore($value, $r);
            }
        }
    }

    return 0;
}

function calculateQuestionScore($q, $answer)
{
    if (!isset($answer['yes']) || $answer['yes'] !== "1") return 0;

    $scoring = $q['scoring'];
    $type = $scoring['type'];
    $max = $q['max'];

    switch ($type) {
        case "fixed":
            return clampScore($scoring['score'], $max);

        case "integrity_penalty":
            $r = getInput($answer, "retracted");
            $s = 5;

            if ($r == 1) $s -= 1;
            elseif ($r == 2) $s -= 2;
            elseif ($r >= 3) $s -= 3;

            return clampScore($s, $max);

        case "basic_fwci_hindex":
            $fo = getInput($answer, "fwci_org");
            $fa = getInput($answer, "fwci_avg");
            $ho = getInput($answer, "hindex_org");
            $ha = getInput($answer, "hindex_avg");

            $sf = 0;
            $sh = 0;

            if ($fa > 0) {
                $r = $fo / $fa;

                if ($r > 1.1) $sf = 5;
                elseif ($r > 0.7 && $r < 1.1) {
                    if ($r < 0.9) $sf = 3;
                    elseif (isEqualFloat($r, 0.9)) $sf = 3.5;
                    else $sf = 4;
                } elseif ($r > 0.3 && $r < 0.6) {
                    if ($r < 0.45) $sf = 1;
                    elseif (isEqualFloat($r, 0.45)) $sf = 1.5;
                    else $sf = 2;
                }
            }

            if ($ha > 0) {
                $r = $ho / $ha;

                if ($r > 1) $sh = 5;
                elseif ($r > 0.8 && $r < 1) $sh = 4;
                elseif ($r > 0.5 && $r < 0.8) $sh = 3;
                elseif ($r > 0 && $r < 0.5) {
                    if ($r < 0.25) $sh = 1;
                    elseif (isEqualFloat($r, 0.25)) $sh = 1.5;
                    else $sh = 2;
                }
            }

            return clampScore($sf + $sh, $max);

        case "weighted_range":
            $raw = 0;
            foreach ($scoring['weights'] as $k => $w) {
                $raw += getInput($answer, $k) * $w;
            }
            return clampScore(scoreByRanges($raw, $scoring['ranges']), $max);

        case "capped_weighted_sum":
            $sum = 0;
            foreach ($scoring['weights'] as $k => $w) {
                $sum += getInput($answer, $k) * $w;
            }
            return clampScore(min($sum, $scoring['cap']), $max);

        case "domestic_publication":
            return clampScore(
                min(7, 3 * getInput($answer, "sqt") + 1.5 * getInput($answer, "str"))
                + min(3, 0.5 * getInput($answer, "bc")),
                $max
            );

        case "ratio_range":
            $num = getInput($answer, $scoring['numerator']);
            $den = getInput($answer, $scoring['denominator']);

            if ($den <= 0) return 0;

            return clampScore(scoreByRanges($num / $den, $scoring['ranges']), $max);

        case "inverse_cost":
            $th = getInput($answer, "threshold");
            $ac = getInput($answer, "actual");

            if ($th <= 0 || $ac <= 0) return 0;

            return clampScore(min($scoring['max_score'] * ($th / $ac), $scoring['max_score']), $max);

        case "positive_ratio_cap":
            $ac = getInput($answer, "actual");
            $th = getInput($answer, "threshold");

            if ($th <= 0) return 0;

            return clampScore(min($scoring['max_score'] * ($ac / $th), $scoring['max_score']), $max);

        case "basic_training_usage":
            $docs = getInput($answer, "teaching_docs");
            $th = getInput($answer, "theses");

            $a = $docs >= 3 ? 7 : ($docs == 2 ? 5 : ($docs == 1 ? 3 : 0));
            $b = $th >= 6 ? 3 : ($th >= 3 ? 2 : ($th >= 1 ? 1 : 0));

            return clampScore($a + $b, $max);

        case "basic_program_role":
            $s = 0;

            if (getInput($answer, "intl_lead") > 0) $s = max($s, 2.5);
            if (getInput($answer, "national_board") > 0) $s = max($s, 2);
            if (getInput($answer, "intl_member") > 0) $s = max($s, 1);
            if (getInput($answer, "national_member") > 0) $s = max($s, 0.5);

            return clampScore($s, $max);

        case "ip_applied":
            $s = 5 * getInput($answer, "patent")
                + 3 * getInput($answer, "utility")
                + min(2, 0.5 * getInput($answer, "application"));

            if (getInput($answer, "intl_bonus") > 0) $s += 1;

            return clampScore(min($s, 10), $max);

        case "ip_fte_range":
            $ip = getInput($answer, "ip_score");
            $fte = getInput($answer, "fte");
            $bm = getInput($answer, "benchmark");

            if ($fte <= 0 || $bm <= 0) return 0;

            $p = ($ip / $fte) / $bm;

            $ranges = [
                ["min" => 1.2, "max" => null, "score" => 10],
                ["min" => 1.0, "max" => 1.2, "score_min" => 8, "score_mid" => 9, "score_max" => 10],
                ["min" => 0.8, "max" => 1.0, "score_min" => 6, "score_mid" => 7, "score_max" => 8],
                ["min" => 0.7, "max" => 0.8, "score_min" => 4, "score_mid" => 5, "score_max" => 6],
                ["min" => 0.5, "max" => 0.7, "score_min" => 1, "score_mid" => 2.5, "score_max" => 4]
            ];

            return clampScore(scoreByRanges($p, $ranges), $max);

        case "cost_output_applied":
            $c = getInput($answer, "cost");

            if ($c <= 0) return 0;
            if ($c < 800) return 5;
            if ($c <= 900) return 4;
            if ($c <= 1000) return 3;
            if ($c <= 2000) return 2;

            return 1;

        case "external_revenue_ratio":
            $e = getInput($answer, "external");
            $t = getInput($answer, "total");

            if ($t <= 0 || $e <= 0) return 0;

            $r = ($e / $t) * 100;

            if ($r > 30) return 10;

            if ($r >= 15 && $r <= 30) {
                if ($r < 22.5) return 6;
                if (isEqualFloat($r, 22.5)) return 7;
                return 8;
            }

            if ($r > 0) return 5;

            return 0;

        case "tech_localization":
            $p = max(getInput($answer, "p_lc"), getInput($answer, "p_ndh"));

            if ($p > 70) return 10;

            if ($p >= 40 && $p <= 70) {
                if ($p < 55) return 5;
                if (isEqualFloat($p, 55)) return 6.5;
                return 8;
            }

            if ($p > 0 && $p < 40) return 4;

            return 0;

        case "tech_commercialization_rate":
            $c = getInput($answer, "commercialized");
            $t = getInput($answer, "total");

            if ($t <= 0 || $c <= 0) return 0;

            $r = ($c / $t) * 100;

            if ($r > 50) return 10;

            if ($r >= 30 && $r <= 50) {
                if ($r < 40) return 5;
                if (isEqualFloat($r, 40)) return 6;
                return 7;
            }

            if ($r > 0) return 4;

            return 0;

        case "tech_roi":
            $rev = getInput($answer, "revenue");
            $cost = getInput($answer, "cost");
            $years = getInput($answer, "years");

            if ($cost <= 0 || $rev < 0) return 0;

            $r = $rev / $cost;

            if (isEqualFloat($r, 0)) return 0;
            if ($r <= 1) return 5;
            if ($years == 3 && $r >= 5) return 10;
            if ($years == 5 && $r >= 10) return 10;

            return 9;

        case "tech_social_environment":
            $s = 5 * getInput($answer, "social_breakthrough")
                + 3.5 * getInput($answer, "social_value")
                + 2 * getInput($answer, "social_basic")
                + 5 * getInput($answer, "env_breakthrough")
                + 3.5 * getInput($answer, "env_value")
                + 2 * getInput($answer, "env_basic");

            return clampScore(min($s, 10), $max);

        case "policy_recommendation":
            if (getInput($answer, "written_response_2") >= 2) return 10;
            if (getInput($answer, "official_5") >= 5) return 9;
            if (getInput($answer, "sector_3") >= 3) return 8;
            if (getInput($answer, "local_or_ministry_3") >= 3) return 6;
            if (getInput($answer, "local_1_2") >= 1) return 3;

            return 0;

        case "policy_applied":
            if (getInput($answer, "new_build") > 0) return 10;
            if (getInput($answer, "replace") > 0) return 8;
            if (getInput($answer, "multiple_modify") >= 2) return 6;
            if (getInput($answer, "partial_modify") > 0) return 4;
            if (getInput($answer, "referenced") > 0) return 2;

            return 0;

        case "policy_advisory":
            return clampScore(
                0.5 * getInput($answer, "low")
                + 1 * getInput($answer, "local")
                + 1.5 * getInput($answer, "dialogue")
                + 2 * getInput($answer, "ministry")
                + 2.5 * getInput($answer, "national"),
                $max
            );

        case "policy_community":
            return clampScore(
                1 * getInput($answer, "board")
                + 3 * getInput($answer, "academic_policy")
                + 4 * getInput($answer, "intl_member")
                + 5 * getInput($answer, "intl_lead"),
                $max
            );

        case "policy_impact_ktxhmt":
            $s = 3 * getInput($answer, "kt3")
                + 2 * getInput($answer, "kt2")
                + getInput($answer, "kt1")
                + 3 * getInput($answer, "xh3")
                + 2 * getInput($answer, "xh2")
                + getInput($answer, "xh1")
                + 3 * getInput($answer, "mt3")
                + 2 * getInput($answer, "mt2")
                + getInput($answer, "mt1");

            return clampScore(min($s, 10), $max);

        case "policy_behavior_change":
            $l = getInput($answer, "level");

            if ($l >= 3) return 10;
            if ($l == 2) return 7;
            if ($l == 1) return 3;

            return 0;

        case "policy_diffusion":
            $l = getInput($answer, "level");

            if ($l >= 2) return 5;
            if ($l == 1) return 3;

            return 0;

        default:
            return 0;
    }
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
    $text = preg_replace('/[\/\\\\:\*\?"<>\|]+/u', '_', $text);
    $text = preg_replace('/[\s\-,;]+/u', '_', $text);
    $text = preg_replace('/[^A-Za-z0-9_.]+/u', '_', $text);
    $text = preg_replace('/_+/u', '_', $text);

    return trim($text, '._');
}

function hasUploadedEvidence($funcKey, $questionId)
{
    $key = "evidence_" . $funcKey . "_" . $questionId;

    if (!isset($_FILES[$key]) || !isset($_FILES[$key]['name'])) {
        return false;
    }

    foreach ($_FILES[$key]['name'] as $idx => $name) {
        if (
            trim($name) !== "" &&
            isset($_FILES[$key]['error'][$idx]) &&
            $_FILES[$key]['error'][$idx] === UPLOAD_ERR_OK
        ) {
            return true;
        }
    }

    return false;
}

function uploadEvidenceFiles($funcKey, $questionId, $orgSafe)
{
    $key = "evidence_" . $funcKey . "_" . $questionId;
    $saved = [];

    if (!isset($_FILES[$key])) return "";

    $uploadDir = "uploads/" . $orgSafe;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            writeLog("SYSTEM_UPLOAD_DIR_ERROR", "Không thể tạo thư mục upload", [
                "upload_dir" => $uploadDir
            ], "ERROR");

            return "[Không thể tạo thư mục uploads/" . $orgSafe . "]";
        }
    }

    if (!is_writable($uploadDir)) {
        writeLog("SYSTEM_UPLOAD_DIR_NOT_WRITABLE", "Thư mục upload không có quyền ghi", [
            "upload_dir" => $uploadDir
        ], "ERROR");

        return "[Thư mục uploads/" . $orgSafe . " không có quyền ghi]";
    }

    foreach ($_FILES[$key]['name'] as $idx => $name) {
        if (!$name) continue;

        if ($_FILES[$key]['error'][$idx] !== UPLOAD_ERR_OK) {
            writeLog("SYSTEM_UPLOAD_FILE_ERROR", "Upload file minh chứng lỗi", [
                "funcKey" => $funcKey,
                "questionId" => $questionId,
                "file_name" => $name,
                "error_code" => $_FILES[$key]['error'][$idx]
            ], "ERROR");

            continue;
        }

        $tmp = $_FILES[$key]['tmp_name'][$idx];

        $safeOriginalName = normalizeVietnameseKeepCase(basename($name));
        $filename = date("Ymd_His") . "_" . uniqid() . "_" . $safeOriginalName;
        $target = $uploadDir . "/" . $filename;

        if (move_uploaded_file($tmp, $target)) {
            $saved[] = $target;

            writeLog("SYSTEM_FILE_UPLOAD", "Đã upload file minh chứng", [
                "funcKey" => $funcKey,
                "questionId" => $questionId,
                "target" => $target
            ]);
        } else {
            writeLog("SYSTEM_UPLOAD_MOVE_ERROR", "Không thể lưu file upload", [
                "funcKey" => $funcKey,
                "questionId" => $questionId,
                "target" => $target
            ], "ERROR");
        }
    }

    return implode(", ", $saved);
}

function getEvidenceText($evidenceTexts, $funcKey, $questionId)
{
    return trim($evidenceTexts[$funcKey][$questionId] ?? "");
}

function buildEvidenceValue($evidenceText, $uploadedFiles)
{
    $parts = [];

    if (trim($evidenceText) !== "") {
        $parts[] = "Mô tả: " . trim($evidenceText);
    }

    if (trim($uploadedFiles) !== "") {
        $parts[] = "Tệp: " . trim($uploadedFiles);
    }

    return implode(" | ", $parts);
}

function saveToExcel($organization, $results, $totalE, $rank)
{
    if (!is_dir("results")) {
        if (!mkdir("results", 0777, true)) {
            writeLog("SYSTEM_RESULTS_DIR_ERROR", "Không thể tạo thư mục results", [], "ERROR");
            return ["error" => "Không thể tạo thư mục 'results'."];
        }
    }

    if (!is_writable("results")) {
        writeLog("SYSTEM_RESULTS_DIR_NOT_WRITABLE", "Thư mục results không có quyền ghi", [], "ERROR");
        return ["error" => "Thư mục 'results' không có quyền ghi."];
    }

    $time = date("Y-m-d H:i:s");
    $timestamp = date("Ymd_His");
    $orgSafe = normalizeVietnameseKeepCase($organization);

    if ($orgSafe === "") {
        writeLog("FORM_SUBMIT_ERROR", "Tên đơn vị không hợp lệ để tạo file", [
            "organization" => $organization
        ], "ERROR");

        return ["error" => "Tên đơn vị không hợp lệ để tạo file."];
    }

    $downloadFile = "results/" . $timestamp . "_" . $orgSafe . ".tsv";
    $fpDownload = fopen($downloadFile, "w");

    if (!$fpDownload) {
        writeLog("SYSTEM_RESULT_FILE_CREATE_ERROR", "Không thể tạo file kết quả", [
            "file" => $downloadFile
        ], "ERROR");

        return ["error" => "Không thể tạo file kết quả."];
    }

    fwrite($fpDownload, "\xEF\xBB\xBF");
    fwrite($fpDownload, "Thời gian\tTổ chức\tChức năng\tTrọng số\tĐt1\tĐt2\tĐt3\tĐt4\tĐT\tĐiểm quy đổi\tTổng E\tXếp loại\tNhóm\tCâu hỏi\tCó/Không\tĐiểm câu hỏi\tChú thích\tMinh chứng\n");

    foreach ($results as $r) {
        foreach ($r['details'] as $d) {
            $row = [
                $time,
                $organization,
                $r['name'],
                $r['weight'] * 100 . "%",
                $r['dt1'],
                $r['dt2'],
                $r['dt3'],
                $r['dt4'],
                $r['dt'],
                round($r['weighted'], 2),
                round($totalE, 2),
                $rank,
                $d['group'],
                $d['question'],
                $d['yes'] === "1" ? "Có" : "Không",
                $d['score'],
                str_replace(["\t", "\n", "\r"], " ", $d['note']),
                str_replace(["\t", "\n", "\r"], " ", $d['evidence'])
            ];

            fwrite($fpDownload, implode("\t", $row) . "\n");
        }
    }

    fclose($fpDownload);

    writeLog("SYSTEM_RESULT_FILE_CREATED", "Đã tạo file kết quả chi tiết", [
        "organization" => $organization,
        "file" => $downloadFile
    ]);

    return ["success" => true, "file" => $downloadFile];
}

writeLog("FORM_SUBMIT_START_BACKEND", "Backend bắt đầu xử lý form");

$criteria = json_decode(file_get_contents("criteria.json"), true);

if (!is_array($criteria)) {
    failWithLog("Không đọc được criteria.json hoặc JSON không hợp lệ.", [
        "file" => "criteria.json"
    ]);
}

$organization = $_POST['organization_name'] ?? "";
$functions = $_POST['function_type'] ?? [];
$weights = $_POST['weight'] ?? [];
$answers = $_POST['answers'] ?? [];
$evidenceTexts = $_POST['evidence_text'] ?? [];

if (trim($organization) === "") {
    failWithLog("Thiếu tên đơn vị đánh giá.", [
        "field" => "organization_name"
    ]);
}

$orgSafe = normalizeVietnameseKeepCase($organization);

if ($orgSafe === "") {
    failWithLog("Tên đơn vị không hợp lệ.", [
        "organization" => $organization
    ]);
}

if (empty($functions)) {
    failWithLog("Phải chọn ít nhất 1 chức năng.", [
        "field" => "function_type"
    ]);
}

$weightSum = 0;

foreach ($functions as $funcKey) {
    $weightSum += (float) ($weights[$funcKey] ?? 0);
}

if (abs($weightSum - 100) > 0.00001) {
    failWithLog("Tổng trọng số các chức năng đang chọn phải bằng 100%.", [
        "weight_sum" => $weightSum,
        "functions" => $functions
    ]);
}

foreach ($functions as $funcKey) {
    if (!isset($criteria['functions'][$funcKey])) {
        writeLog("FORM_UNKNOWN_FUNCTION", "Chức năng không tồn tại trong criteria", [
            "funcKey" => $funcKey
        ], "WARN");

        continue;
    }

    $func = $criteria['functions'][$funcKey];

    foreach ($func['groups'] as $group) {
        foreach ($group['criteria'] as $q) {
            $answer = $answers[$funcKey][$q['id']] ?? [];

            if (!isset($answer['yes']) || $answer['yes'] === "") {
                failWithLog("Thiếu câu trả lời Có/Không cho tiêu chí: " . $q['text'], [
                    "funcKey" => $funcKey,
                    "group" => $group['id'],
                    "questionId" => $q['id']
                ]);
            }

            if (!empty($q['inputs'])) {
                foreach ($q['inputs'] as $input) {
                    $inputName = $input['name'];

                    if (
                        !isset($answer['inputs'][$inputName]) ||
                        trim((string) $answer['inputs'][$inputName]) === ""
                    ) {
                        failWithLog("Thiếu số liệu '" . $input['label'] . "' của tiêu chí: " . $q['text'], [
                            "funcKey" => $funcKey,
                            "group" => $group['id'],
                            "questionId" => $q['id'],
                            "inputName" => $inputName
                        ]);
                    }
                }
            }

            $isQuantitative = ($q['display_mode'] ?? "") === "quantitative" || !empty($q['inputs']);

            if (!$isQuantitative) {
                if (!isset($answer['note']) || trim($answer['note']) === "") {
                    failWithLog("Thiếu ghi chú cho tiêu chí: " . $q['text'], [
                        "funcKey" => $funcKey,
                        "group" => $group['id'],
                        "questionId" => $q['id']
                    ]);
                }
            }

            $evidenceText = getEvidenceText($evidenceTexts, $funcKey, $q['id']);
            $hasFile = hasUploadedEvidence($funcKey, $q['id']);

            if ($evidenceText === "" && !$hasFile) {
                failWithLog("Thiếu minh chứng cho tiêu chí: " . $q['text'], [
                    "funcKey" => $funcKey,
                    "group" => $group['id'],
                    "questionId" => $q['id']
                ]);
            }
        }
    }
}

writeLog("FORM_VALIDATE_SUCCESS", "Dữ liệu form hợp lệ", [
    "organization" => $organization,
    "organization_safe" => $orgSafe,
    "functions" => $functions,
    "weight_sum" => $weightSum
]);

$results = [];
$totalE = 0;

foreach ($functions as $funcKey) {
    if (!isset($criteria['functions'][$funcKey])) {
        continue;
    }

    $func = $criteria['functions'][$funcKey];

    $dtScores = [
        "DT1" => 0,
        "DT2" => 0,
        "DT3" => 0,
        "DT4" => 0
    ];

    $details = [];

    foreach ($func['groups'] as $group) {
        foreach ($group['criteria'] as $q) {
            $answer = $answers[$funcKey][$q['id']] ?? [];

            $score = calculateQuestionScore($q, $answer);

            $evidenceText = getEvidenceText($evidenceTexts, $funcKey, $q['id']);
            $uploadedFiles = uploadEvidenceFiles($funcKey, $q['id'], $orgSafe);
            $evidence = buildEvidenceValue($evidenceText, $uploadedFiles);

            $dtScores[$group['id']] += $score;

            $details[] = [
                "group" => $group['id'],
                "question" => $q['text'],
                "yes" => $answer['yes'] ?? "",
                "score" => $score,
                "note" => $answer['note'] ?? "",
                "evidence" => $evidence
            ];

            writeLog("FORM_QUESTION_RECORDED", "Backend ghi nhận câu trả lời tiêu chí", [
                "organization" => $organization,
                "funcKey" => $funcKey,
                "group" => $group['id'],
                "questionId" => $q['id'],
                "yes" => $answer['yes'] ?? "",
                "score" => $score,
                "has_note" => trim($answer['note'] ?? "") !== "",
                "has_evidence_text" => trim($evidenceText) !== "",
                "has_uploaded_file" => trim($uploadedFiles) !== ""
            ]);
        }
    }

    $dt = $dtScores["DT1"] + $dtScores["DT2"] + $dtScores["DT3"] + $dtScores["DT4"];

    $weight = ((float) ($weights[$funcKey] ?? 0)) / 100;
    $weighted = $dt * $weight;

    $totalE += $weighted;

    $results[$funcKey] = [
        "name" => $func['name'],
        "dt1" => $dtScores["DT1"],
        "dt2" => $dtScores["DT2"],
        "dt3" => $dtScores["DT3"],
        "dt4" => $dtScores["DT4"],
        "dt" => $dt,
        "weight" => $weight,
        "weighted" => $weighted,
        "details" => $details
    ];
}

if ($totalE >= 80) {
    $rank = "A - Xuất sắc";
} elseif ($totalE >= 60) {
    $rank = "B - Tốt";
} elseif ($totalE >= 40) {
    $rank = "C - Trung bình";
} else {
    $rank = "D - Kém";
}

writeLog("FORM_SCORE_CALCULATED", "Đã tính điểm form", [
    "organization" => $organization,
    "totalE" => round($totalE, 2),
    "rank" => $rank
]);

$saveResult = saveToExcel($organization, $results, $totalE, $rank);

if (is_array($saveResult) && isset($saveResult['error'])) {
    $errorMessage = $saveResult['error'];
    $downloadFile = null;

    writeLog("FORM_SUBMIT_FAILED", "Nộp form thất bại khi lưu file", [
        "organization" => $organization,
        "error" => $errorMessage
    ], "ERROR");
} else {
    $errorMessage = null;
    $downloadFile = $saveResult['file'] ?? null;

    writeLog("FORM_SUBMIT_SUCCESS", "Nộp form thành công", [
        "organization" => $organization,
        "file" => $downloadFile,
        "totalE" => round($totalE, 2),
        "rank" => $rank
    ]);
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Kết quả đánh giá</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <?php if (!$errorMessage): ?>
        <script>
            try {
                localStorage.removeItem("score_system_form_state_v1");
                fetch("log_event.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        type: "FORM_DRAFT_CLEARED",
                        message: "Đã xóa bản nháp localStorage sau khi nộp thành công",
                        context: {},
                        level: "INFO"
                    }),
                    keepalive: true
                }).catch(() => {});
            } catch (e) {}
        </script>
    <?php endif; ?>

    <div class="container">
        <h1>Kết quả đánh giá</h1>

        <?php if ($errorMessage): ?>
            <div class="error-message" style="background-color: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
                <strong>⚠️ Lỗi:</strong> <?= htmlspecialchars($errorMessage) ?>
                <p style="margin-top: 10px; font-size: 0.9em;">Vui lòng liên hệ quản trị viên hệ thống để xử lý vấn đề này.</p>
            </div>
        <?php else: ?>
            <div class="success-message" style="background-color: #d4edda; color: #155724; padding: 15px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px;">
                <strong>✅ Thành công!</strong> Kết quả đã được lưu. Bạn có thể tải file kết quả dưới đây.
                <?php if ($downloadFile): ?>
                    <p style="margin-top: 10px;">
                        <a href="<?= htmlspecialchars($downloadFile) ?>" download style="display: inline-block; margin-top: 10px; padding: 10px 20px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                            📥 Tải file kết quả
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h2>Người đánh giá: <?= htmlspecialchars($organization) ?></h2>

        <?php foreach ($results as $r): ?>
            <div class="function-card">
                <h2><?= htmlspecialchars($r['name']) ?></h2>

                <p>Trọng số: <?= $r['weight'] * 100 ?>%</p>
                <p>Đt1: <?= round($r['dt1'], 2) ?></p>
                <p>Đt2: <?= round($r['dt2'], 2) ?></p>
                <p>Đt3: <?= round($r['dt3'], 2) ?></p>
                <p>Đt4: <?= round($r['dt4'], 2) ?></p>

                <h3>ĐT: <?= round($r['dt'], 2) ?></h3>
                <h3>Điểm quy đổi: <?= round($r['weighted'], 2) ?></h3>
            </div>
        <?php endforeach; ?>

        <hr>

        <h2>Tổng điểm E: <?= round($totalE, 2) ?></h2>
        <h2>Xếp loại: <?= $rank ?></h2>

        <div style="margin-top: 30px; text-align: center;">
            <a href="index.php" style="display: inline-block; padding: 10px 20px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                ← Quay lại
            </a>
        </div>
    </div>
</body>

</html>