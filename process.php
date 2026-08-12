<?php
require_once 'logger.php';
require_once 'lang.php';

initLang();

// ─── Detect POST bị discard do vượt post_max_size ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxSize   = ini_get('post_max_size');

    writeLog("FORM_SUBMIT_ERROR", "POST data rỗng - có thể do vượt post_max_size", [
        "content_length_bytes" => $contentLength,
        "post_max_size"        => $postMaxSize
    ], "ERROR");

    $mb = $contentLength > 0 ? round($contentLength / 1048576, 1) . " MB" : t('process.unknown_size');

    die(t('process.post_too_large', ['mb' => $mb]));
}
// ─────────────────────────────────────────────────────────────────────────────

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

function numFmt($n)
{
    $n = round((float) $n, 2);

    if (abs($n - round($n)) < 0.00001) {
        return (string) (int) round($n);
    }

    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

// Mirrors scoreByRanges()/midpointScore()'s exact matching logic so the
// explanation text can never disagree with the score that was actually given.
function describeRange($value, $ranges)
{
    foreach ($ranges as $r) {
        $min = (float) $r['min'];
        $max = $r['max'];

        if ($max === null) {
            if ($value >= $min) {
                $score = $r['score'] ?? $r['score_max'] ?? 0;
                return [
                    'score' => $score,
                    'vi' => "giá trị " . numFmt($value) . " ≥ " . numFmt($min) . " nên được " . numFmt($score) . " điểm",
                    'en' => "the value " . numFmt($value) . " is ≥ " . numFmt($min) . ", so it received " . numFmt($score) . " points",
                ];
            }
        } else {
            if ($value >= $min && $value <= (float) $max) {
                $score = midpointScore($value, $r);
                return [
                    'score' => $score,
                    'vi' => "giá trị " . numFmt($value) . " nằm trong khoảng [" . numFmt($min) . "-" . numFmt($max) . "] nên được " . numFmt($score) . " điểm",
                    'en' => "the value " . numFmt($value) . " falls within the [" . numFmt($min) . "-" . numFmt($max) . "] range, so it received " . numFmt($score) . " points",
                ];
            }
        }
    }

    return [
        'score' => 0,
        'vi' => "giá trị " . numFmt($value) . " chưa đạt ngưỡng tối thiểu nào nên được 0 điểm",
        'en' => "the value " . numFmt($value) . " does not reach any defined threshold, so it received 0 points",
    ];
}

function calculateQuestionScore($q, $answer)
{
    if (!isset($answer['yes']) || $answer['yes'] !== "1") {
        return [
            'score' => 0,
            'explain_vi' => "Chọn 'Không' nên không đạt yêu cầu, được 0 điểm.",
            'explain_en' => "Answered 'No', so the requirement is not met — 0 points.",
        ];
    }

    $scoring = $q['scoring'];
    $type = $scoring['type'];
    $max = $q['max'];

    switch ($type) {
        case "fixed":
            $score = clampScore($scoring['score'], $max);

            return [
                'score' => $score,
                'explain_vi' => "Đạt yêu cầu (chọn 'Có') nên được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Requirement met (answered 'Yes'), so it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "integrity_penalty":
            $r = getInput($answer, "retracted");
            $s = 5;
            $deduction = 0;

            if ($r == 1) {
                $s -= 1;
                $deduction = 1;
            } elseif ($r == 2) {
                $s -= 2;
                $deduction = 2;
            } elseif ($r >= 3) {
                $s -= 3;
                $deduction = 3;
            }

            $score = clampScore($s, $max);

            if ($deduction === 0) {
                $explainVi = "Không có công bố nào bị thu hồi trong 3 năm nên không bị trừ điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.";
                $explainEn = "No publications were retracted in the last 3 years, so no points were deducted — " . numFmt($score) . "/" . numFmt($max) . " points.";
            } else {
                $explainVi = "Có " . numFmt($r) . " công bố bị thu hồi trong 3 năm nên bị trừ " . numFmt($deduction) . " điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.";
                $explainEn = numFmt($r) . " publication(s) were retracted in the last 3 years, so " . numFmt($deduction) . " point(s) were deducted — " . numFmt($score) . "/" . numFmt($max) . " points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "basic_fwci_hindex":
            $fo = getInput($answer, "fwci_org");
            $fa = getInput($answer, "fwci_avg");
            $ho = getInput($answer, "hindex_org");
            $ha = getInput($answer, "hindex_avg");

            $sf = 0;
            $sh = 0;
            $rf = null;
            $rh = null;

            if ($fa > 0) {
                $rf = $fo / $fa;

                if ($rf > 1.1) $sf = 5;
                elseif ($rf > 0.7 && $rf < 1.1) {
                    if ($rf < 0.9) $sf = 3;
                    elseif (isEqualFloat($rf, 0.9)) $sf = 3.5;
                    else $sf = 4;
                } elseif ($rf > 0.3 && $rf < 0.6) {
                    if ($rf < 0.45) $sf = 1;
                    elseif (isEqualFloat($rf, 0.45)) $sf = 1.5;
                    else $sf = 2;
                }
            }

            if ($ha > 0) {
                $rh = $ho / $ha;

                if ($rh > 1) $sh = 5;
                elseif ($rh > 0.8 && $rh < 1) $sh = 4;
                elseif ($rh > 0.5 && $rh < 0.8) $sh = 3;
                elseif ($rh > 0 && $rh < 0.5) {
                    if ($rh < 0.25) $sh = 1;
                    elseif (isEqualFloat($rh, 0.25)) $sh = 1.5;
                    else $sh = 2;
                }
            }

            $score = clampScore($sf + $sh, $max);

            $fwciVi = $rf === null
                ? "FWCI trung bình ngành/VN bằng 0 nên không tính được tỷ lệ, phần FWCI được 0 điểm"
                : "FWCI đơn vị/trung bình = " . numFmt($fo) . "/" . numFmt($fa) . " (tỷ lệ " . numFmt($rf) . ") nên phần FWCI được " . numFmt($sf) . "/5 điểm";
            $fwciEn = $rf === null
                ? "the sector/national average FWCI is 0, so the ratio cannot be computed — the FWCI part received 0 points"
                : "organization/average FWCI = " . numFmt($fo) . "/" . numFmt($fa) . " (ratio " . numFmt($rf) . "), so the FWCI part received " . numFmt($sf) . "/5 points";

            $hVi = $rh === null
                ? "H-index trung bình ngành bằng 0 nên không tính được tỷ lệ, phần H-index được 0 điểm"
                : "H-index đơn vị/trung bình = " . numFmt($ho) . "/" . numFmt($ha) . " (tỷ lệ " . numFmt($rh) . ") nên phần H-index được " . numFmt($sh) . "/5 điểm";
            $hEn = $rh === null
                ? "the sector average H-index is 0, so the ratio cannot be computed — the H-index part received 0 points"
                : "organization/average H-index = " . numFmt($ho) . "/" . numFmt($ha) . " (ratio " . numFmt($rh) . "), so the H-index part received " . numFmt($sh) . "/5 points";

            return [
                'score' => $score,
                'explain_vi' => ucfirst($fwciVi) . "; " . $hVi . ". Tổng: " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => ucfirst($fwciEn) . "; " . $hEn . ". Total: " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "weighted_range":
            $raw = 0;
            foreach ($scoring['weights'] as $k => $w) {
                $raw += getInput($answer, $k) * $w;
            }

            $desc = describeRange($raw, $scoring['ranges']);
            $score = clampScore($desc['score'], $max);

            return [
                'score' => $score,
                'explain_vi' => "Giá trị quy đổi tính từ số liệu đã nhập là " . numFmt($raw) . "; " . $desc['vi'] . ".",
                'explain_en' => "The computed value from the entered figures is " . numFmt($raw) . "; " . $desc['en'] . ".",
            ];

        case "capped_weighted_sum":
            $sum = 0;
            foreach ($scoring['weights'] as $k => $w) {
                $sum += getInput($answer, $k) * $w;
            }

            $capped = $sum > $scoring['cap'];
            $score = clampScore(min($sum, $scoring['cap']), $max);

            $explainVi = "Tổng điểm quy đổi từ số liệu đã nhập là " . numFmt($sum)
                . ($capped ? ", vượt mức trần " . numFmt($scoring['cap']) . " nên bị giới hạn lại" : "")
                . ", được " . numFmt($score) . "/" . numFmt($max) . " điểm.";
            $explainEn = "The weighted total from the entered figures is " . numFmt($sum)
                . ($capped ? ", which exceeds the cap of " . numFmt($scoring['cap']) . " and was capped" : "")
                . " — it received " . numFmt($score) . "/" . numFmt($max) . " points.";

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "domestic_publication":
            $sqt = getInput($answer, "sqt");
            $str = getInput($answer, "str");
            $bc = getInput($answer, "bc");

            $part1 = min(7, 3 * $sqt + 1.5 * $str);
            $part2 = min(3, 0.5 * $bc);
            $score = clampScore($part1 + $part2, $max);

            return [
                'score' => $score,
                'explain_vi' => "Sách quốc tế/trong nước (" . numFmt($sqt) . "/" . numFmt($str) . ") quy đổi được " . numFmt($part1) . "/7 điểm; báo cáo hội nghị (" . numFmt($bc) . ") quy đổi được " . numFmt($part2) . "/3 điểm. Tổng: " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "International/domestic books (" . numFmt($sqt) . "/" . numFmt($str) . ") convert to " . numFmt($part1) . "/7 points; conference reports (" . numFmt($bc) . ") convert to " . numFmt($part2) . "/3 points. Total: " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "ratio_range":
            $num = getInput($answer, $scoring['numerator']);
            $den = getInput($answer, $scoring['denominator']);

            if ($den <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Mẫu số bằng 0 nên không tính được tỷ lệ, được 0 điểm.",
                    'explain_en' => "The denominator is 0, so the ratio cannot be computed — 0 points.",
                ];
            }

            $ratio = $num / $den;
            $desc = describeRange($ratio, $scoring['ranges']);
            $score = clampScore($desc['score'], $max);

            return [
                'score' => $score,
                'explain_vi' => "Tỷ lệ tính được là " . numFmt($num) . "/" . numFmt($den) . " = " . numFmt($ratio) . "; " . $desc['vi'] . ".",
                'explain_en' => "The computed ratio is " . numFmt($num) . "/" . numFmt($den) . " = " . numFmt($ratio) . "; " . $desc['en'] . ".",
            ];

        case "inverse_cost":
            $th = getInput($answer, "threshold");
            $ac = getInput($answer, "actual");

            if ($th <= 0 || $ac <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Ngưỡng tối thiểu hoặc chi phí thực tế bằng 0 nên không tính được, được 0 điểm.",
                    'explain_en' => "The minimum threshold or actual cost is 0, so it cannot be computed — 0 points.",
                ];
            }

            $score = clampScore(min($scoring['max_score'] * ($th / $ac), $scoring['max_score']), $max);

            return [
                'score' => $score,
                'explain_vi' => "Chi phí thực tế " . numFmt($ac) . " so với ngưỡng tối thiểu " . numFmt($th) . " (chi phí càng thấp so với ngưỡng thì điểm càng cao) nên được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Actual cost " . numFmt($ac) . " compared to the minimum threshold " . numFmt($th) . " (the lower the cost relative to the threshold, the higher the score), so it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "positive_ratio_cap":
            $ac = getInput($answer, "actual");
            $th = getInput($answer, "threshold");

            if ($th <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Ngưỡng tối đa bằng 0 nên không tính được tỷ lệ, được 0 điểm.",
                    'explain_en' => "The maximum threshold is 0, so the ratio cannot be computed — 0 points.",
                ];
            }

            $score = clampScore(min($scoring['max_score'] * ($ac / $th), $scoring['max_score']), $max);

            return [
                'score' => $score,
                'explain_vi' => "Tỷ lệ thực tế " . numFmt($ac) . " so với ngưỡng tối đa " . numFmt($th) . " nên được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Actual rate " . numFmt($ac) . " compared to the maximum threshold " . numFmt($th) . ", so it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "basic_training_usage":
            $docs = getInput($answer, "teaching_docs");
            $theses = getInput($answer, "theses");

            $a = $docs >= 3 ? 7 : ($docs == 2 ? 5 : ($docs == 1 ? 3 : 0));
            $b = $theses >= 6 ? 3 : ($theses >= 3 ? 2 : ($theses >= 1 ? 1 : 0));
            $score = clampScore($a + $b, $max);

            return [
                'score' => $score,
                'explain_vi' => numFmt($docs) . " tài liệu giảng dạy chính thức được " . numFmt($a) . "/7 điểm; " . numFmt($theses) . " luận văn/luận án sử dụng kết quả được " . numFmt($b) . "/3 điểm. Tổng: " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => numFmt($docs) . " official teaching material(s) score " . numFmt($a) . "/7 points; " . numFmt($theses) . " thesis/dissertation(s) using the results score " . numFmt($b) . "/3 points. Total: " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "basic_program_role":
            $s = 0;
            $reasonVi = "Không có vai trò nào trong chương trình KH&CN/ĐMST";
            $reasonEn = "No role in any S&T/innovation program";

            if (getInput($answer, "intl_lead") > 0 && 2.5 > $s) {
                $s = 2.5;
                $reasonVi = "Có vai trò chủ nhiệm/điều phối chương trình quốc tế (mức cao nhất)";
                $reasonEn = "Has a lead/coordinator role in an international program (the highest tier)";
            }
            if (getInput($answer, "national_board") > 0 && 2 > $s) {
                $s = 2;
                $reasonVi = "Có vai trò ban chủ nhiệm chương trình quốc gia";
                $reasonEn = "Has a management-board role in a national program";
            }
            if (getInput($answer, "intl_member") > 0 && 1 > $s) {
                $s = 1;
                $reasonVi = "Có vai trò thành viên chính chương trình quốc tế";
                $reasonEn = "Has a key-member role in an international program";
            }
            if (getInput($answer, "national_member") > 0 && 0.5 > $s) {
                $s = 0.5;
                $reasonVi = "Có vai trò thành viên chương trình quốc gia";
                $reasonEn = "Has a member role in a national program";
            }

            $score = clampScore($s, $max);

            return [
                'score' => $score,
                'explain_vi' => $reasonVi . " nên được " . numFmt($score) . "/" . numFmt($max) . " điểm (tính theo vai trò cao nhất đạt được).",
                'explain_en' => $reasonEn . ", so it received " . numFmt($score) . "/" . numFmt($max) . " points (based on the highest role achieved).",
            ];

        case "ip_applied":
            $patent = getInput($answer, "patent");
            $utility = getInput($answer, "utility");
            $application = getInput($answer, "application");
            $intlBonus = getInput($answer, "intl_bonus") > 0;

            $s = 5 * $patent + 3 * $utility + min(2, 0.5 * $application);

            if ($intlBonus) $s += 1;

            $score = clampScore(min($s, 10), $max);

            return [
                'score' => $score,
                'explain_vi' => numFmt($patent) . " bằng sáng chế, " . numFmt($utility) . " giải pháp hữu ích/IC/giống, " . numFmt($application) . " đơn được chấp nhận" . ($intlBonus ? ", có bằng quốc tế (+1 điểm)" : "") . " nên được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => numFmt($patent) . " patent(s), " . numFmt($utility) . " utility solution(s)/IC layout/plant variety, " . numFmt($application) . " accepted application(s)" . ($intlBonus ? ", plus an international patent (+1 point)" : "") . ", so it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "ip_fte_range":
            $ip = getInput($answer, "ip_score");
            $fte = getInput($answer, "fte");
            $bm = getInput($answer, "benchmark");

            if ($fte <= 0 || $bm <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "FTE hoặc trung bình quốc gia bằng 0 nên không tính được, được 0 điểm.",
                    'explain_en' => "FTE or the national average is 0, so it cannot be computed — 0 points.",
                ];
            }

            $p = ($ip / $fte) / $bm;

            $ranges = [
                ["min" => 1.2, "max" => null, "score" => 10],
                ["min" => 1.0, "max" => 1.2, "score_min" => 8, "score_mid" => 9, "score_max" => 10],
                ["min" => 0.8, "max" => 1.0, "score_min" => 6, "score_mid" => 7, "score_max" => 8],
                ["min" => 0.7, "max" => 0.8, "score_min" => 4, "score_mid" => 5, "score_max" => 6],
                ["min" => 0.5, "max" => 0.7, "score_min" => 1, "score_mid" => 2.5, "score_max" => 4]
            ];

            $desc = describeRange($p, $ranges);
            $score = clampScore($desc['score'], $max);

            return [
                'score' => $score,
                'explain_vi' => "Năng suất IP/FTE so với trung bình quốc gia tính được là " . numFmt($p) . " (điểm IP " . numFmt($ip) . ", FTE " . numFmt($fte) . ", trung bình quốc gia " . numFmt($bm) . "); " . $desc['vi'] . ".",
                'explain_en' => "IP output per FTE relative to the national average is " . numFmt($p) . " (IP score " . numFmt($ip) . ", FTE " . numFmt($fte) . ", national average " . numFmt($bm) . "); " . $desc['en'] . ".",
            ];

        case "cost_output_applied":
            $c = getInput($answer, "cost");

            if ($c <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Chi phí trung bình/đầu ra bằng 0 nên không hợp lệ, được 0 điểm.",
                    'explain_en' => "The average cost per output is 0, which is invalid — 0 points.",
                ];
            }

            if ($c < 800) $score = 5;
            elseif ($c <= 900) $score = 4;
            elseif ($c <= 1000) $score = 3;
            elseif ($c <= 2000) $score = 2;
            else $score = 1;

            return [
                'score' => $score,
                'explain_vi' => "Chi phí trung bình/đầu ra là " . numFmt($c) . " triệu VNĐ nên được " . numFmt($score) . "/5 điểm (chi phí càng thấp thì điểm càng cao).",
                'explain_en' => "Average cost per output is " . numFmt($c) . " million VND, so it received " . numFmt($score) . "/5 points (the lower the cost, the higher the score).",
            ];

        case "external_revenue_ratio":
            $e = getInput($answer, "external");
            $t = getInput($answer, "total");

            if ($t <= 0 || $e <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Doanh thu bên ngoài hoặc tổng doanh thu bằng 0 nên không tính được, được 0 điểm.",
                    'explain_en' => "External revenue or total revenue is 0, so it cannot be computed — 0 points.",
                ];
            }

            $r = ($e / $t) * 100;

            if ($r > 30) {
                $score = 10;
                $bucketVi = "trên 30%";
                $bucketEn = "above 30%";
            } elseif ($r >= 15 && $r <= 30) {
                if ($r < 22.5) $score = 6;
                elseif (isEqualFloat($r, 22.5)) $score = 7;
                else $score = 8;
                $bucketVi = "trong khoảng 15-30%";
                $bucketEn = "within the 15-30% range";
            } elseif ($r > 0) {
                $score = 5;
                $bucketVi = "trên 0% nhưng dưới 15%";
                $bucketEn = "above 0% but below 15%";
            } else {
                $score = 0;
                $bucketVi = "bằng 0%";
                $bucketEn = "equal to 0%";
            }

            return [
                'score' => $score,
                'explain_vi' => "Tỷ lệ doanh thu bên ngoài/tổng doanh thu là " . numFmt($e) . "/" . numFmt($t) . " = " . numFmt($r) . "%, " . $bucketVi . " nên được " . numFmt($score) . "/10 điểm.",
                'explain_en' => "The ratio of external revenue to total revenue is " . numFmt($e) . "/" . numFmt($t) . " = " . numFmt($r) . "%, which is " . $bucketEn . ", so it received " . numFmt($score) . "/10 points.",
            ];

        case "tech_localization":
            $plc = getInput($answer, "p_lc");
            $pndh = getInput($answer, "p_ndh");
            $p = max($plc, $pndh);

            if ($p > 70) {
                $score = 10;
                $bucketVi = "trên 70%";
                $bucketEn = "above 70%";
            } elseif ($p >= 40 && $p <= 70) {
                if ($p < 55) $score = 5;
                elseif (isEqualFloat($p, 55)) $score = 6.5;
                else $score = 8;
                $bucketVi = "trong khoảng 40-70%";
                $bucketEn = "within the 40-70% range";
            } elseif ($p > 0 && $p < 40) {
                $score = 4;
                $bucketVi = "trên 0% nhưng dưới 40%";
                $bucketEn = "above 0% but below 40%";
            } else {
                $score = 0;
                $bucketVi = "bằng 0%";
                $bucketEn = "equal to 0%";
            }

            return [
                'score' => $score,
                'explain_vi' => "Mức độ nội địa hóa/làm chủ công nghệ cao nhất (P_LC/P_NĐH) đạt " . numFmt($p) . "%, " . $bucketVi . " nên được " . numFmt($score) . "/10 điểm.",
                'explain_en' => "The highest localization/technology-mastery level (P_LC/P_NDH) is " . numFmt($p) . "%, which is " . $bucketEn . ", so it received " . numFmt($score) . "/10 points.",
            ];

        case "tech_commercialization_rate":
            $c = getInput($answer, "commercialized");
            $t = getInput($answer, "total");

            if ($t <= 0 || $c <= 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Số kết quả đã thương mại hóa hoặc tổng kết quả đầu ra bằng 0 nên không tính được, được 0 điểm.",
                    'explain_en' => "Commercialized outputs or total outputs is 0, so it cannot be computed — 0 points.",
                ];
            }

            $r = ($c / $t) * 100;

            if ($r > 50) {
                $score = 10;
                $bucketVi = "trên 50%";
                $bucketEn = "above 50%";
            } elseif ($r >= 30 && $r <= 50) {
                if ($r < 40) $score = 5;
                elseif (isEqualFloat($r, 40)) $score = 6;
                else $score = 7;
                $bucketVi = "trong khoảng 30-50%";
                $bucketEn = "within the 30-50% range";
            } elseif ($r > 0) {
                $score = 4;
                $bucketVi = "trên 0% nhưng dưới 30%";
                $bucketEn = "above 0% but below 30%";
            } else {
                $score = 0;
                $bucketVi = "bằng 0%";
                $bucketEn = "equal to 0%";
            }

            return [
                'score' => $score,
                'explain_vi' => "Tỷ lệ thương mại hóa là " . numFmt($c) . "/" . numFmt($t) . " = " . numFmt($r) . "%, " . $bucketVi . " nên được " . numFmt($score) . "/10 điểm.",
                'explain_en' => "The commercialization rate is " . numFmt($c) . "/" . numFmt($t) . " = " . numFmt($r) . "%, which is " . $bucketEn . ", so it received " . numFmt($score) . "/10 points.",
            ];

        case "tech_roi":
            $rev = getInput($answer, "revenue");
            $cost = getInput($answer, "cost");
            $years = getInput($answer, "years");

            if ($cost <= 0 || $rev < 0) {
                return [
                    'score' => 0,
                    'explain_vi' => "Chi phí R&D bằng 0 hoặc doanh thu âm nên không hợp lệ, được 0 điểm.",
                    'explain_en' => "R&D cost is 0 or revenue is negative, which is invalid — 0 points.",
                ];
            }

            $r = $rev / $cost;

            if (isEqualFloat($r, 0)) {
                $score = 0;
                $explainVi = "Doanh thu từ R&D bằng 0 (chi phí " . numFmt($cost) . ") nên được 0 điểm.";
                $explainEn = "Revenue from R&D is 0 (cost " . numFmt($cost) . "), so it received 0 points.";
            } elseif ($r <= 1) {
                $score = 5;
                $explainVi = "Tỷ lệ doanh thu/chi phí R&D là " . numFmt($rev) . "/" . numFmt($cost) . " = " . numFmt($r) . " (≤1) nên được 5/10 điểm.";
                $explainEn = "The revenue/R&D-cost ratio is " . numFmt($rev) . "/" . numFmt($cost) . " = " . numFmt($r) . " (≤1), so it received 5/10 points.";
            } elseif ($years == 3 && $r >= 5) {
                $score = 10;
                $explainVi = "Tỷ lệ " . numFmt($r) . " đạt ngưỡng tối đa cho chu kỳ 3 năm (≥5) nên được 10/10 điểm.";
                $explainEn = "The ratio " . numFmt($r) . " reaches the maximum threshold for the 3-year period (≥5), so it received 10/10 points.";
            } elseif ($years == 5 && $r >= 10) {
                $score = 10;
                $explainVi = "Tỷ lệ " . numFmt($r) . " đạt ngưỡng tối đa cho chu kỳ 5 năm (≥10) nên được 10/10 điểm.";
                $explainEn = "The ratio " . numFmt($r) . " reaches the maximum threshold for the 5-year period (≥10), so it received 10/10 points.";
            } else {
                $score = 9;
                $explainVi = "Tỷ lệ doanh thu/chi phí R&D là " . numFmt($r) . " (>1) nhưng chưa đạt ngưỡng tối đa cho chu kỳ " . numFmt($years) . " năm đã chọn nên được 9/10 điểm.";
                $explainEn = "The revenue/R&D-cost ratio is " . numFmt($r) . " (>1) but does not reach the maximum threshold for the selected " . numFmt($years) . "-year period, so it received 9/10 points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "tech_social_environment":
            $sb = getInput($answer, "social_breakthrough");
            $sv = getInput($answer, "social_value");
            $sBasic = getInput($answer, "social_basic");
            $eb = getInput($answer, "env_breakthrough");
            $ev = getInput($answer, "env_value");
            $eBasic = getInput($answer, "env_basic");

            $s = 5 * $sb + 3.5 * $sv + 2 * $sBasic + 5 * $eb + 3.5 * $ev + 2 * $eBasic;
            $score = clampScore(min($s, 10), $max);

            return [
                'score' => $score,
                'explain_vi' => "Tác động xã hội (đột phá " . numFmt($sb) . ", có giá trị " . numFmt($sv) . ", cơ bản " . numFmt($sBasic) . ") và môi trường (đột phá " . numFmt($eb) . ", có giá trị " . numFmt($ev) . ", cơ bản " . numFmt($eBasic) . ") quy đổi thành " . numFmt($s) . " điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Social impact (breakthrough " . numFmt($sb) . ", valuable " . numFmt($sv) . ", basic " . numFmt($sBasic) . ") and environmental impact (breakthrough " . numFmt($eb) . ", valuable " . numFmt($ev) . ", basic " . numFmt($eBasic) . ") convert to " . numFmt($s) . " points — it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "policy_recommendation":
            $wr2 = getInput($answer, "written_response_2");
            $o5 = getInput($answer, "official_5");
            $s3 = getInput($answer, "sector_3");
            $lm3 = getInput($answer, "local_or_ministry_3");
            $l12 = getInput($answer, "local_1_2");

            if ($wr2 >= 2) {
                $score = 10;
                $explainVi = numFmt($wr2) . " đề xuất được phản hồi/công nhận/trích dẫn/đưa vào xây dựng chính sách (≥2, mức cao nhất) nên được 10/10 điểm.";
                $explainEn = numFmt($wr2) . " proposal(s) acknowledged/cited/incorporated into policy-making (≥2, the highest tier), so it received 10/10 points.";
            } elseif ($o5 >= 5) {
                $score = 9;
                $explainVi = numFmt($o5) . " đề xuất gửi chính thức tới cơ quan có thẩm quyền (≥5) nên được 9/10 điểm.";
                $explainEn = numFmt($o5) . " proposal(s) formally submitted to a competent authority (≥5), so it received 9/10 points.";
            } elseif ($s3 >= 3) {
                $score = 8;
                $explainVi = numFmt($s3) . " đề xuất cấp ngành/liên ngành được xem xét (≥3) nên được 8/10 điểm.";
                $explainEn = numFmt($s3) . " sector/inter-sector proposal(s) under review (≥3), so it received 8/10 points.";
            } elseif ($lm3 >= 3) {
                $score = 6;
                $explainVi = numFmt($lm3) . " đề xuất cấp địa phương/Bộ có phản hồi (≥3) nên được 6/10 điểm.";
                $explainEn = numFmt($lm3) . " local/ministry-level proposal(s) with a response (≥3), so it received 6/10 points.";
            } elseif ($l12 >= 1) {
                $score = 3;
                $explainVi = numFmt($l12) . " đề xuất cấp địa phương đã gửi/tiếp nhận (≥1) nên được 3/10 điểm.";
                $explainEn = numFmt($l12) . " local-level proposal(s) submitted/received (≥1), so it received 3/10 points.";
            } else {
                $score = 0;
                $explainVi = "Chưa có đề xuất nào đạt ngưỡng tối thiểu của bất kỳ mức nào nên được 0 điểm.";
                $explainEn = "No proposal reaches the minimum threshold of any tier, so it received 0 points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "policy_applied":
            $newBuild = getInput($answer, "new_build");
            $replace = getInput($answer, "replace");
            $multipleModify = getInput($answer, "multiple_modify");
            $partialModify = getInput($answer, "partial_modify");
            $referenced = getInput($answer, "referenced");

            if ($newBuild > 0) {
                $score = 10;
                $explainVi = numFmt($newBuild) . " kết quả nghiên cứu xây dựng mới chính sách/văn bản (mức cao nhất) nên được 10/10 điểm.";
                $explainEn = numFmt($newBuild) . " research result(s) that created a new policy/document (the highest tier), so it received 10/10 points.";
            } elseif ($replace > 0) {
                $score = 8;
                $explainVi = numFmt($replace) . " kết quả nghiên cứu thay thế chính sách cũ nên được 8/10 điểm.";
                $explainEn = numFmt($replace) . " research result(s) that replaced an old policy, so it received 8/10 points.";
            } elseif ($multipleModify >= 2) {
                $score = 6;
                $explainVi = numFmt($multipleModify) . " kết quả nghiên cứu sửa đổi từ 2 chính sách trở lên (≥2) nên được 6/10 điểm.";
                $explainEn = numFmt($multipleModify) . " research result(s) that revised 2 or more policies (≥2), so it received 6/10 points.";
            } elseif ($partialModify > 0) {
                $score = 4;
                $explainVi = numFmt($partialModify) . " kết quả nghiên cứu sửa đổi một phần chính sách nên được 4/10 điểm.";
                $explainEn = numFmt($partialModify) . " research result(s) that partially revised a policy, so it received 4/10 points.";
            } elseif ($referenced > 0) {
                $score = 2;
                $explainVi = numFmt($referenced) . " kết quả nghiên cứu được tham khảo/sử dụng hạn chế nên được 2/10 điểm.";
                $explainEn = numFmt($referenced) . " research result(s) referenced/used to a limited extent, so it received 2/10 points.";
            } else {
                $score = 0;
                $explainVi = "Chưa có kết quả nghiên cứu nào được áp dụng ở bất kỳ mức nào nên được 0 điểm.";
                $explainEn = "No research result has been applied at any tier, so it received 0 points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "policy_advisory":
            $low = getInput($answer, "low");
            $local = getInput($answer, "local");
            $dialogue = getInput($answer, "dialogue");
            $ministry = getInput($answer, "ministry");
            $national = getInput($answer, "national");

            $s = 0.5 * $low + 1 * $local + 1.5 * $dialogue + 2 * $ministry + 2.5 * $national;
            $score = clampScore($s, $max);

            return [
                'score' => $score,
                'explain_vi' => "Hoạt động tư vấn/phản biện: cấp thấp " . numFmt($low) . ", địa phương/trung gian " . numFmt($local) . ", đối thoại cấp cao " . numFmt($dialogue) . ", hội đồng cấp Bộ/ngành " . numFmt($ministry) . ", hội đồng cấp Quốc gia " . numFmt($national) . " quy đổi thành " . numFmt($s) . " điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Advisory/critique activity: low-level " . numFmt($low) . ", local/intermediary " . numFmt($local) . ", high-level dialogue " . numFmt($dialogue) . ", ministry/sector council " . numFmt($ministry) . ", national council " . numFmt($national) . " convert to " . numFmt($s) . " points — it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "policy_community":
            $board = getInput($answer, "board");
            $academicPolicy = getInput($answer, "academic_policy");
            $intlMember = getInput($answer, "intl_member");
            $intlLead = getInput($answer, "intl_lead");

            $s = 1 * $board + 3 * $academicPolicy + 4 * $intlMember + 5 * $intlLead;
            $score = clampScore($s, $max);

            return [
                'score' => $score,
                'explain_vi' => "Ảnh hưởng cộng đồng nghiên cứu: vai trò BCN/điều phối " . numFmt($board) . ", xây dựng chính sách học thuật " . numFmt($academicPolicy) . ", thành viên chính CT quốc tế " . numFmt($intlMember) . ", chủ nhiệm/điều phối CT quốc tế " . numFmt($intlLead) . " quy đổi thành " . numFmt($s) . " điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Research-community influence: management-board/coordinator role " . numFmt($board) . ", academic policy-making " . numFmt($academicPolicy) . ", international-program key member " . numFmt($intlMember) . ", international-program lead/coordinator " . numFmt($intlLead) . " convert to " . numFmt($s) . " points — it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "policy_impact_ktxhmt":
            $kt3 = getInput($answer, "kt3");
            $kt2 = getInput($answer, "kt2");
            $kt1 = getInput($answer, "kt1");
            $xh3 = getInput($answer, "xh3");
            $xh2 = getInput($answer, "xh2");
            $xh1 = getInput($answer, "xh1");
            $mt3 = getInput($answer, "mt3");
            $mt2 = getInput($answer, "mt2");
            $mt1 = getInput($answer, "mt1");

            $s = 3 * $kt3 + 2 * $kt2 + $kt1 + 3 * $xh3 + 2 * $xh2 + $xh1 + 3 * $mt3 + 2 * $mt2 + $mt1;
            $score = clampScore(min($s, 10), $max);

            return [
                'score' => $score,
                'explain_vi' => "Tác động kinh tế (mức 3/2/1: " . numFmt($kt3) . "/" . numFmt($kt2) . "/" . numFmt($kt1) . "), xã hội (" . numFmt($xh3) . "/" . numFmt($xh2) . "/" . numFmt($xh1) . ") và môi trường (" . numFmt($mt3) . "/" . numFmt($mt2) . "/" . numFmt($mt1) . ") quy đổi thành " . numFmt($s) . " điểm, được " . numFmt($score) . "/" . numFmt($max) . " điểm.",
                'explain_en' => "Economic impact (level 3/2/1: " . numFmt($kt3) . "/" . numFmt($kt2) . "/" . numFmt($kt1) . "), social (" . numFmt($xh3) . "/" . numFmt($xh2) . "/" . numFmt($xh1) . ") and environmental (" . numFmt($mt3) . "/" . numFmt($mt2) . "/" . numFmt($mt1) . ") convert to " . numFmt($s) . " points — it received " . numFmt($score) . "/" . numFmt($max) . " points.",
            ];

        case "policy_behavior_change":
            $l = getInput($answer, "level");

            if ($l >= 3) {
                $score = 10;
                $explainVi = "Mức tác động nhập là " . numFmt($l) . " (≥3, mức cao nhất) nên được 10/10 điểm.";
                $explainEn = "The entered impact level is " . numFmt($l) . " (≥3, the highest tier), so it received 10/10 points.";
            } elseif ($l == 2) {
                $score = 7;
                $explainVi = "Mức tác động nhập là 2 nên được 7/10 điểm.";
                $explainEn = "The entered impact level is 2, so it received 7/10 points.";
            } elseif ($l == 1) {
                $score = 3;
                $explainVi = "Mức tác động nhập là 1 nên được 3/10 điểm.";
                $explainEn = "The entered impact level is 1, so it received 3/10 points.";
            } else {
                $score = 0;
                $explainVi = "Chưa nhập mức tác động hợp lệ (1, 2 hoặc 3) nên được 0 điểm.";
                $explainEn = "No valid impact level (1, 2, or 3) was entered, so it received 0 points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        case "policy_diffusion":
            $l = getInput($answer, "level");

            if ($l >= 2) {
                $score = 5;
                $explainVi = "Mức lan tỏa nhập là " . numFmt($l) . " (≥2, mức cao nhất) nên được 5/5 điểm.";
                $explainEn = "The entered diffusion level is " . numFmt($l) . " (≥2, the highest tier), so it received 5/5 points.";
            } elseif ($l == 1) {
                $score = 3;
                $explainVi = "Mức lan tỏa nhập là 1 nên được 3/5 điểm.";
                $explainEn = "The entered diffusion level is 1, so it received 3/5 points.";
            } else {
                $score = 0;
                $explainVi = "Chưa nhập mức lan tỏa hợp lệ (1 hoặc 2) nên được 0 điểm.";
                $explainEn = "No valid diffusion level (1 or 2) was entered, so it received 0 points.";
            }

            return ['score' => $score, 'explain_vi' => $explainVi, 'explain_en' => $explainEn];

        default:
            return [
                'score' => 0,
                'explain_vi' => "Loại tính điểm không xác định.",
                'explain_en' => "Unknown scoring type.",
            ];
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

function findGroup($func, $groupId)
{
    foreach ($func['groups'] as $g) {
        if ($g['id'] === $groupId) return $g;
    }

    return null;
}

function saveToExcel($organization, $results, $totalE, $rank)
{
    if (!is_dir("results")) {
        if (!mkdir("results", 0777, true)) {
            writeLog("SYSTEM_RESULTS_DIR_ERROR", "Không thể tạo thư mục results", [], "ERROR");
            return ["error" => t('process.results_dir_failed')];
        }
    }

    if (!is_writable("results")) {
        writeLog("SYSTEM_RESULTS_DIR_NOT_WRITABLE", "Thư mục results không có quyền ghi", [], "ERROR");
        return ["error" => t('process.results_dir_not_writable')];
    }

    $time = date("Y-m-d H:i:s");
    $timestamp = date("Ymd_His");
    $orgSafe = normalizeVietnameseKeepCase($organization);

    if ($orgSafe === "") {
        writeLog("FORM_SUBMIT_ERROR", "Tên đơn vị không hợp lệ để tạo file", [
            "organization" => $organization
        ], "ERROR");

        return ["error" => t('process.invalid_org_for_file')];
    }

    $downloadFile = "results/" . $timestamp . "_" . $orgSafe . ".tsv";
    $fpDownload = fopen($downloadFile, "w");

    if (!$fpDownload) {
        writeLog("SYSTEM_RESULT_FILE_CREATE_ERROR", "Không thể tạo file kết quả", [
            "file" => $downloadFile
        ], "ERROR");

        return ["error" => t('process.result_file_failed')];
    }

    fwrite($fpDownload, "\xEF\xBB\xBF");
    fwrite($fpDownload, "Thời gian\tTổ chức\tChức năng\tTrọng số\tĐt1\tĐt2\tĐt3\tĐt4\tĐT\tĐiểm quy đổi\tTổng E\tXếp loại\tNhóm\tCâu hỏi\tCó/Không\tĐiểm câu hỏi\tChú thích\tMinh chứng\tGiải thích\n");

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
                str_replace(["\t", "\n", "\r"], " ", $d['evidence']),
                str_replace(["\t", "\n", "\r"], " ", $d['explanation'])
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
    failWithLog(t('process.criteria_load_failed'), [
        "file" => "criteria.json"
    ]);
}

$organization = $_POST['organization_name'] ?? "";
$functions = $_POST['function_type'] ?? [];
$weights = $_POST['weight'] ?? [];
$answers = $_POST['answers'] ?? [];
$evidenceTexts = $_POST['evidence_text'] ?? [];

if (trim($organization) === "") {
    failWithLog(t('process.missing_org'), [
        "field" => "organization_name"
    ]);
}

$orgSafe = normalizeVietnameseKeepCase($organization);

if ($orgSafe === "") {
    failWithLog(t('process.invalid_org'), [
        "organization" => $organization
    ]);
}

if (empty($functions)) {
    failWithLog(t('process.missing_function'), [
        "field" => "function_type"
    ]);
}

$weightSum = 0;

foreach ($functions as $funcKey) {
    $weightSum += (float) ($weights[$funcKey] ?? 0);
}

if (abs($weightSum - 100) > 0.00001) {
    failWithLog(t('process.weight_sum_invalid'), [
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
                failWithLog(t('process.missing_yes_no', ['question' => pickField($q, 'text')]), [
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
                        failWithLog(t('process.missing_input', [
                            'label' => pickField($input, 'label'),
                            'question' => pickField($q, 'text')
                        ]), [
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
                    failWithLog(t('process.missing_note', ['question' => pickField($q, 'text')]), [
                        "funcKey" => $funcKey,
                        "group" => $group['id'],
                        "questionId" => $q['id']
                    ]);
                }
            }

            $evidenceText = getEvidenceText($evidenceTexts, $funcKey, $q['id']);
            $hasFile = hasUploadedEvidence($funcKey, $q['id']);

            if ($evidenceText === "" && !$hasFile) {
                failWithLog(t('process.missing_evidence', ['question' => pickField($q, 'text')]), [
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

            $scoreResult = calculateQuestionScore($q, $answer);
            $score = $scoreResult['score'];

            $evidenceText = getEvidenceText($evidenceTexts, $funcKey, $q['id']);
            $uploadedFiles = uploadEvidenceFiles($funcKey, $q['id'], $orgSafe);
            $evidence = buildEvidenceValue($evidenceText, $uploadedFiles);

            $dtScores[$group['id']] += $score;

            $details[] = [
                "group" => $group['id'],
                "question" => $q['text'],
                "question_en" => pickField($q, 'text'),
                "max" => $q['max'],
                "yes" => $answer['yes'] ?? "",
                "score" => $score,
                "explanation" => $scoreResult['explain_vi'],
                "explanation_en" => $scoreResult['explain_en'],
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
    $rankDisplay = t('process.rank_a');
} elseif ($totalE >= 60) {
    $rank = "B - Tốt";
    $rankDisplay = t('process.rank_b');
} elseif ($totalE >= 40) {
    $rank = "C - Trung bình";
    $rankDisplay = t('process.rank_c');
} else {
    $rank = "D - Kém";
    $rankDisplay = t('process.rank_d');
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
<html lang="<?php echo $LANG; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo t('process.page_title'); ?></title>
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
        <h1><?php echo t('process.h1'); ?></h1>

        <?php if ($errorMessage): ?>
            <div class="error-message" style="background-color: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px;">
                <strong><?php echo t('process.error_label'); ?></strong> <?= htmlspecialchars($errorMessage) ?>
                <p style="margin-top: 10px; font-size: 0.9em;"><?php echo t('process.error_contact_admin'); ?></p>
            </div>
        <?php else: ?>
            <div class="success-message" style="background-color: #d4edda; color: #155724; padding: 15px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px;">
                <strong><?php echo t('process.success_label'); ?></strong> <?php echo t('process.success_message'); ?>
                <?php if ($downloadFile): ?>
                    <p style="margin-top: 10px;">
                        <a href="<?= htmlspecialchars($downloadFile) ?>" download style="display: inline-block; margin-top: 10px; padding: 10px 20px; background-color: #28a745; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                            <?php echo t('process.download_result'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h2><?php echo t('process.evaluator_prefix'); ?><?= htmlspecialchars($organization) ?></h2>

        <?php foreach ($results as $funcKey => $r): ?>
            <div class="function-card">
                <h2><?= htmlspecialchars(pickField($criteria['functions'][$funcKey], 'name')) ?></h2>

                <p><?php echo t('process.weight_label'); ?><?= $r['weight'] * 100 ?>%</p>
                <p><?php echo t('process.dt1_label'); ?><?= round($r['dt1'], 2) ?></p>
                <p><?php echo t('process.dt2_label'); ?><?= round($r['dt2'], 2) ?></p>
                <p><?php echo t('process.dt3_label'); ?><?= round($r['dt3'], 2) ?></p>
                <p><?php echo t('process.dt4_label'); ?><?= round($r['dt4'], 2) ?></p>

                <h3><?php echo t('process.dt_label'); ?><?= round($r['dt'], 2) ?></h3>
                <h3><?php echo t('process.weighted_label'); ?><?= round($r['weighted'], 2) ?></h3>

                <details class="explain-details">
                    <summary><?php echo t('process.details_summary'); ?></summary>
                    <?php $lastGroupId = null; ?>
                    <?php foreach ($r['details'] as $d): ?>
                        <?php if ($d['group'] !== $lastGroupId): ?>
                            <?php $lastGroupId = $d['group']; ?>
                            <?php $groupInfo = findGroup($criteria['functions'][$funcKey], $lastGroupId); ?>
                            <h4 class="explain-group-title"><?= $groupInfo ? htmlspecialchars(pickField($groupInfo, 'title')) : htmlspecialchars($lastGroupId) ?></h4>
                        <?php endif; ?>
                        <div class="explain-item">
                            <p class="explain-question"><?= htmlspecialchars($LANG === 'en' ? $d['question_en'] : $d['question']) ?></p>
                            <p class="explain-meta">
                                <?php echo t('process.answer_label'); ?><?= $d['yes'] === '1' ? t('js.yes') : t('js.no') ?>
                                &nbsp;|&nbsp;
                                <?php echo t('process.score_label'); ?><strong><?= numFmt($d['score']) ?>/<?= numFmt($d['max']) ?></strong>
                            </p>
                            <p class="explain-text"><?= htmlspecialchars($LANG === 'en' ? $d['explanation_en'] : $d['explanation']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </details>
            </div>
        <?php endforeach; ?>

        <hr>

        <h2><?php echo t('process.total_e_label'); ?><?= round($totalE, 2) ?></h2>
        <h2><?php echo t('process.rank_label'); ?><?= htmlspecialchars($rankDisplay) ?></h2>

        <div style="margin-top: 30px; text-align: center;">
            <a href="index.php" style="display: inline-block; padding: 10px 20px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;">
                <?php echo t('process.back_link'); ?>
            </a>
        </div>
    </div>
</body>

</html>