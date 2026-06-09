<?php
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

function uploadEvidenceFiles($funcKey, $questionId)
{
    $key = "evidence_" . $funcKey . "_" . $questionId;
    $saved = [];

    if (!isset($_FILES[$key])) return "";

    if (!is_dir("uploads")) {
        if (!mkdir("uploads", 0777, true)) {
            return "[Không thể tạo thư mục uploads]";
        }
    }

    if (!is_writable("uploads")) {
        return "[Thư mục uploads không có quyền ghi]";
    }

    foreach ($_FILES[$key]['name'] as $idx => $name) {
        if (!$name) continue;

        if ($_FILES[$key]['error'][$idx] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp = $_FILES[$key]['tmp_name'][$idx];
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $filename = date("Ymd_His") . "_" . uniqid() . "_" . $safe;
        $target = "uploads/" . $filename;

        if (move_uploaded_file($tmp, $target)) {
            $saved[] = $target;
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

function slugify($text)
{
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII;', $text);
    } else {
        $vietnamese = array(
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'đ' => 'd',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y'
        );
        $text = str_replace(array_keys($vietnamese), array_values($vietnamese), $text);
    }

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim($text, '_');

    return $text;
}

function saveToExcel($organization, $results, $totalE, $rank)
{
    if (!is_dir("results")) {
        if (!mkdir("results", 0777, true)) {
            return ["error" => "Không thể tạo thư mục 'results'."];
        }
    }

    if (!is_writable("results")) {
        return ["error" => "Thư mục 'results' không có quyền ghi."];
    }

    $time = date("Y-m-d H:i:s");
    $timestamp = date("Ymd_His");
    $orgSafe = slugify($organization);

    $downloadFile = "results/" . $timestamp . "_" . $orgSafe . ".tsv";
    $fpDownload = fopen($downloadFile, "w");

    if (!$fpDownload) {
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

    $summaryFile = "results/results.tsv";
    $isNew = !file_exists($summaryFile);

    $existingLines = [];
    $headerLine = "";

    if ($isNew) {
        $headerLine = "Thời gian\tTổ chức\tChức năng\tTrọng số\tĐt1\tĐt2\tĐt3\tĐt4\tĐT\tĐiểm quy đổi\tTổng E\tXếp loại\tNhóm\tCâu hỏi\tCó/Không\tĐiểm câu hỏi\tChú thích\tMinh chứng";
    } else {
        $fileContent = file_get_contents($summaryFile);

        if (substr($fileContent, 0, 3) === "\xEF\xBB\xBF") {
            $fileContent = substr($fileContent, 3);
        }

        $lines = explode("\n", $fileContent);

        if (!empty($lines)) {
            $headerLine = array_shift($lines);
        }

        foreach ($lines as $line) {
            if (trim($line) === "") continue;

            $columns = explode("\t", $line);

            if (isset($columns[1]) && $columns[1] !== $organization) {
                $existingLines[] = $line;
            }
        }
    }

    $newLines = [];

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

            $newLines[] = implode("\t", $row);
        }
    }

    $fpSummary = fopen($summaryFile, "w");

    if ($fpSummary) {
        fwrite($fpSummary, "\xEF\xBB\xBF");
        fwrite($fpSummary, $headerLine . "\n");

        foreach ($existingLines as $line) {
            fwrite($fpSummary, $line . "\n");
        }

        foreach ($newLines as $line) {
            fwrite($fpSummary, $line . "\n");
        }

        fclose($fpSummary);
    }

    return ["success" => true, "file" => $downloadFile];
}

$criteria = json_decode(file_get_contents("criteria.json"), true);

$organization = $_POST['organization_name'] ?? "";
$functions = $_POST['function_type'] ?? [];
$weights = $_POST['weight'] ?? [];
$answers = $_POST['answers'] ?? [];
$evidenceTexts = $_POST['evidence_text'] ?? [];

if (trim($organization) === "") {
    die("Thiếu tên đơn vị đánh giá.");
}

if (empty($functions)) {
    die("Phải chọn ít nhất 1 chức năng.");
}

$weightSum = 0;

foreach ($functions as $funcKey) {
    $weightSum += (float) ($weights[$funcKey] ?? 0);
}

if (abs($weightSum - 100) > 0.00001) {
    die("Tổng trọng số các chức năng đang chọn phải bằng 100%.");
}

foreach ($functions as $funcKey) {
    if (!isset($criteria['functions'][$funcKey])) {
        continue;
    }

    $func = $criteria['functions'][$funcKey];

    foreach ($func['groups'] as $group) {
        foreach ($group['criteria'] as $q) {
            $answer = $answers[$funcKey][$q['id']] ?? [];

            if (!isset($answer['yes']) || $answer['yes'] === "") {
                die("Thiếu câu trả lời Có/Không cho tiêu chí: " . $q['text']);
            }

            if (!empty($q['inputs'])) {
                foreach ($q['inputs'] as $input) {
                    $inputName = $input['name'];

                    if (
                        !isset($answer['inputs'][$inputName]) ||
                        trim((string) $answer['inputs'][$inputName]) === ""
                    ) {
                        die("Thiếu số liệu '" . $input['label'] . "' của tiêu chí: " . $q['text']);
                    }
                }
            }

            $isQuantitative = ($q['display_mode'] ?? "") === "quantitative" || !empty($q['inputs']);

            if (!$isQuantitative) {
                if (!isset($answer['note']) || trim($answer['note']) === "") {
                    die("Thiếu ghi chú cho tiêu chí: " . $q['text']);
                }
            }

            $evidenceText = getEvidenceText($evidenceTexts, $funcKey, $q['id']);
            $hasFile = hasUploadedEvidence($funcKey, $q['id']);

            if ($evidenceText === "" && !$hasFile) {
                die("Thiếu minh chứng cho tiêu chí: " . $q['text']);
            }
        }
    }
}

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
            $uploadedFiles = uploadEvidenceFiles($funcKey, $q['id']);
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

$saveResult = saveToExcel($organization, $results, $totalE, $rank);

if (is_array($saveResult) && isset($saveResult['error'])) {
    $errorMessage = $saveResult['error'];
    $downloadFile = null;
} else {
    $errorMessage = null;
    $downloadFile = $saveResult['file'] ?? null;
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