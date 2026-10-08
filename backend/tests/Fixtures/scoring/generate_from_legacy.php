<?php
/**
 * Sinh "đáp án chuẩn" cho test chấm điểm bằng cách chạy CHÍNH các hàm chấm điểm của hệ cũ
 * (legacy/process.php) trên nhiều bộ số liệu, gồm cả giá trị biên của từng ngưỡng.
 *
 * Chỉ cần chạy lại khi muốn sinh lại fixtures (khi còn thư mục legacy/), từ thư mục gốc repo:
 *   docker run --rm -v "$PWD/legacy":/legacy:ro -v "$PWD/backend/tests/Fixtures/scoring":/out \
 *     score-php:dev php /out/generate_from_legacy.php /legacy /out/legacy_cases.json.gz
 * Test dùng fixtures này: tests/Unit/Scoring/LegacyParityTest.php
 */

$legacyDir = $argv[1] ?? '/var/www/legacy';
$outFile = $argv[2] ?? __DIR__ . '/legacy_cases.json.gz';

// Lấy nguyên văn các hàm thuần (clampScore ... calculateQuestionScore) từ process.php
$src = file_get_contents("$legacyDir/process.php");
$start = strpos($src, 'function clampScore');
$end = strpos($src, 'function hasUploadedEvidence');
eval(substr($src, $start, $end - $start));

$criteria = json_decode(file_get_contents("$legacyDir/criteria.json"), true);

mt_srand(20261008); // cố định để sinh lại ra cùng kết quả

$pool = [0, 0.25, 0.3, 0.45, 0.5, 0.6, 0.7, 0.75, 0.8, 0.9, 1, 1.1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 7, 9, 10,
    12, 13, 15, 19, 20, 22.5, 25, 30, 35, 40, 45, 50, 55, 60, 70, 80, 100, 500, 800, 850, 900, 950, 1000, 1500, 2000, 2500];

function randomValue(array $pool)
{
    $r = mt_rand(1, 100);
    if ($r <= 70) return $pool[array_rand($pool)];
    if ($r <= 90) return mt_rand(0, 3000) / 100;     // số thực ngẫu nhiên
    return mt_rand(0, 60);
}

$questionCases = [];
foreach ($criteria['functions'] as $funcKey => $func) {
    foreach ($func['groups'] as $group) {
        foreach ($group['criteria'] as $q) {
            $inputNames = array_column($q['inputs'] ?? [], 'name');
            $answers = [['yes' => '0'], ['yes' => ''], ['yes' => '1']];   // Không / bỏ trống / Có, không số liệu
            if ($inputNames) {
                // 1) Duyệt có hệ thống: từng ô nhận mọi giá trị biên, các ô còn lại = 0 / 1 / 10 / 100 / ngẫu nhiên
                //    (chạm đúng các ngưỡng tỷ lệ như 0.9, 0.45, 22.5%, 900 triệu...)
                foreach ($inputNames as $target) {
                    foreach ($pool as $v) {
                        foreach ([0, 1, 10, 100, null] as $base) {
                            $inputs = [];
                            foreach ($inputNames as $name) {
                                $inputs[$name] = (string) ($name === $target ? $v : ($base ?? randomValue($pool)));
                            }
                            $answers[] = ['yes' => '1', 'inputs' => $inputs];
                        }
                    }
                }
                // 2) Câu có ≤ 3 ô: thử mọi tổ hợp trên tập giá trị nhỏ (vd chu kỳ 5 năm VÀ tỷ lệ đúng 10)
                if (count($inputNames) <= 3) {
                    $small = [0, 0.5, 1, 2, 3, 4, 5, 9, 10, 20, 50, 100];
                    $combos = [[]];
                    foreach ($inputNames as $name) {
                        $next = [];
                        foreach ($combos as $c) {
                            foreach ($small as $v) {
                                $next[] = $c + [$name => (string) $v];
                            }
                        }
                        $combos = $next;
                    }
                    foreach ($combos as $inputs) {
                        $answers[] = ['yes' => '1', 'inputs' => $inputs];
                    }
                }
                // 3) Ngẫu nhiên toàn bộ các ô
                for ($i = 0; $i < 100; $i++) {
                    $inputs = [];
                    foreach ($inputNames as $name) {
                        $inputs[$name] = (string) randomValue($pool);
                    }
                    $answers[] = ['yes' => '1', 'inputs' => $inputs];
                }
            }
            foreach ($answers as $answer) {
                $res = calculateQuestionScore($q, $answer);
                $questionCases[] = [
                    'function' => $funcKey,
                    'question' => $q['id'],
                    'answer' => $answer,
                    'expected' => $res,
                ];
            }
        }
    }
}

// Cả bài đánh giá: tổng Đt1..Đt4, ĐT, điểm quy đổi theo trọng số, tổng E, xếp loại
// (cùng công thức với phần thân process.php)
$evaluationCases = [];
$funcKeys = array_keys($criteria['functions']);
for ($i = 0; $i < 60; $i++) {
    $chosen = $funcKeys;
    shuffle($chosen);
    $chosen = array_slice($chosen, 0, mt_rand(1, count($funcKeys)));
    $weights = [];
    $left = 100;
    foreach ($chosen as $k => $fk) {
        $weights[$fk] = $k === count($chosen) - 1 ? $left : ($w = mt_rand(0, $left));
        $left -= $k === count($chosen) - 1 ? 0 : $w;
    }

    $answers = [];
    foreach ($chosen as $fk) {
        foreach ($criteria['functions'][$fk]['groups'] as $group) {
            foreach ($group['criteria'] as $q) {
                $inputs = [];
                foreach ($q['inputs'] ?? [] as $in) {
                    $inputs[$in['name']] = (string) randomValue($pool);
                }
                $answers[$fk][$q['id']] = ['yes' => mt_rand(1, 100) <= 80 ? '1' : '0', 'inputs' => $inputs];
            }
        }
    }

    $functions = [];
    $totalE = 0;
    foreach ($chosen as $fk) {
        $dt = ['DT1' => 0, 'DT2' => 0, 'DT3' => 0, 'DT4' => 0];
        foreach ($criteria['functions'][$fk]['groups'] as $group) {
            foreach ($group['criteria'] as $q) {
                $dt[$group['id']] += calculateQuestionScore($q, $answers[$fk][$q['id']])['score'];
            }
        }
        $sum = $dt['DT1'] + $dt['DT2'] + $dt['DT3'] + $dt['DT4'];
        $weighted = $sum * ((float) $weights[$fk] / 100);
        $totalE += $weighted;
        $functions[$fk] = ['dt1' => $dt['DT1'], 'dt2' => $dt['DT2'], 'dt3' => $dt['DT3'], 'dt4' => $dt['DT4'],
            'dt' => $sum, 'weighted' => $weighted];
    }
    $grade = $totalE >= 80 ? 'A' : ($totalE >= 60 ? 'B' : ($totalE >= 40 ? 'C' : 'D'));

    $evaluationCases[] = [
        'functions' => $chosen,
        'weights' => array_map('strval', $weights),
        'answers' => $answers,
        'expected' => ['functions' => $functions, 'total' => $totalE, 'grade' => $grade],
    ];
}

file_put_contents($outFile, gzencode(json_encode([
    'generated_from' => 'legacy/process.php + legacy/criteria.json',
    'questions' => $questionCases,
    'evaluations' => $evaluationCases,
], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 9));

echo count($questionCases) . " question cases, " . count($evaluationCases) . " evaluation cases\n";
