<?php

namespace App\Domain\Scoring;

/**
 * Chấm điểm 1 câu hỏi theo cấu hình "scoring" trong bộ tiêu chí.
 *
 * Câu trả lời: ['yes' => '1'|'0', 'inputs' => ['ten_o' => so, ...]].
 * Chọn "Không" => 0 điểm. Chọn "Có" => tính theo scoring.type.
 * Kết quả khớp tuyệt đối với hệ cũ (xem tests/Unit/Scoring/LegacyParityTest.php).
 */
final class QuestionScorer
{
    public function score(array $question, array $answer): ScoreResult
    {
        if (! self::isYes($answer['yes'] ?? null)) {
            return new ScoreResult(0, "Chọn 'Không' nên không đạt yêu cầu, được 0 điểm.", "Answered 'No', so the requirement is not met — 0 points.");
        }

        $scoring = $question['scoring'];
        $max = $question['max'];
        $in = fn (string $name): float => isset($answer['inputs'][$name]) ? (float) $answer['inputs'][$name] : 0;

        return match ($scoring['type']) {
            'fixed' => $this->fixed($scoring, $max),
            'integrity_penalty' => $this->integrityPenalty($in, $max),
            'basic_fwci_hindex' => $this->basicFwciHindex($in, $max),
            'weighted_range' => $this->weightedRange($scoring, $in, $max),
            'capped_weighted_sum' => $this->cappedWeightedSum($scoring, $in, $max),
            'domestic_publication' => $this->domesticPublication($in, $max),
            'ratio_range' => $this->ratioRange($scoring, $in, $max),
            'inverse_cost' => $this->inverseCost($scoring, $in, $max),
            'positive_ratio_cap' => $this->positiveRatioCap($scoring, $in, $max),
            'basic_training_usage' => $this->basicTrainingUsage($in, $max),
            'basic_program_role' => $this->basicProgramRole($in, $max),
            'ip_applied' => $this->ipApplied($in, $max),
            'ip_fte_range' => $this->ipFteRange($in, $max),
            'cost_output_applied' => $this->costOutputApplied($in),
            'external_revenue_ratio' => $this->externalRevenueRatio($in),
            'tech_localization' => $this->techLocalization($in),
            'tech_commercialization_rate' => $this->techCommercializationRate($in),
            'tech_roi' => $this->techRoi($in),
            'tech_social_environment' => $this->techSocialEnvironment($in, $max),
            'policy_recommendation' => $this->policyRecommendation($in),
            'policy_applied' => $this->policyApplied($in),
            'policy_advisory' => $this->policyAdvisory($in, $max),
            'policy_community' => $this->policyCommunity($in, $max),
            'policy_impact_ktxhmt' => $this->policyImpactKtxhmt($in, $max),
            'policy_behavior_change' => $this->policyBehaviorChange($in),
            'policy_diffusion' => $this->policyDiffusion($in),
            default => new ScoreResult(0, 'Loại tính điểm không xác định.', 'Unknown scoring type.'),
        };
    }

    /** Danh sách loại tính điểm được hỗ trợ (dùng để kiểm tra bộ tiêu chí trước khi lưu). */
    public const TYPES = [
        'fixed', 'integrity_penalty', 'basic_fwci_hindex', 'weighted_range', 'capped_weighted_sum',
        'domestic_publication', 'ratio_range', 'inverse_cost', 'positive_ratio_cap', 'basic_training_usage',
        'basic_program_role', 'ip_applied', 'ip_fte_range', 'cost_output_applied', 'external_revenue_ratio',
        'tech_localization', 'tech_commercialization_rate', 'tech_roi', 'tech_social_environment',
        'policy_recommendation', 'policy_applied', 'policy_advisory', 'policy_community',
        'policy_impact_ktxhmt', 'policy_behavior_change', 'policy_diffusion',
    ];

    public static function isYes(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true;
    }

    private function fixed(array $scoring, $max): ScoreResult
    {
        $score = Num::clamp($scoring['score'], $max);

        return new ScoreResult(
            $score,
            "Đạt yêu cầu (chọn 'Có') nên được ".Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            "Requirement met (answered 'Yes'), so it received ".Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function integrityPenalty(callable $in, $max): ScoreResult
    {
        $r = $in('retracted');
        $deduction = match (true) {
            $r == 1 => 1,
            $r == 2 => 2,
            $r >= 3 => 3,
            default => 0,
        };
        $score = Num::clamp(5 - $deduction, $max);

        if ($deduction === 0) {
            return new ScoreResult(
                $score,
                'Không có công bố nào bị thu hồi trong 3 năm nên không bị trừ điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
                'No publications were retracted in the last 3 years, so no points were deducted — '.Num::fmt($score).'/'.Num::fmt($max).' points.',
            );
        }

        return new ScoreResult(
            $score,
            'Có '.Num::fmt($r).' công bố bị thu hồi trong 3 năm nên bị trừ '.Num::fmt($deduction).' điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            Num::fmt($r).' publication(s) were retracted in the last 3 years, so '.Num::fmt($deduction).' point(s) were deducted — '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function basicFwciHindex(callable $in, $max): ScoreResult
    {
        $fo = $in('fwci_org');
        $fa = $in('fwci_avg');
        $ho = $in('hindex_org');
        $ha = $in('hindex_avg');

        $sf = 0;
        $sh = 0;
        $rf = null;
        $rh = null;

        if ($fa > 0) {
            $rf = $fo / $fa;

            if ($rf > 1.1) {
                $sf = 5;
            } elseif ($rf > 0.7 && $rf < 1.1) {
                $sf = $rf < 0.9 ? 3 : (Num::eq($rf, 0.9) ? 3.5 : 4);
            } elseif ($rf > 0.3 && $rf < 0.6) {
                $sf = $rf < 0.45 ? 1 : (Num::eq($rf, 0.45) ? 1.5 : 2);
            }
        }

        if ($ha > 0) {
            $rh = $ho / $ha;

            if ($rh > 1) {
                $sh = 5;
            } elseif ($rh > 0.8 && $rh < 1) {
                $sh = 4;
            } elseif ($rh > 0.5 && $rh < 0.8) {
                $sh = 3;
            } elseif ($rh > 0 && $rh < 0.5) {
                $sh = $rh < 0.25 ? 1 : (Num::eq($rh, 0.25) ? 1.5 : 2);
            }
        }

        $score = Num::clamp($sf + $sh, $max);

        $fwciVi = $rf === null
            ? 'FWCI trung bình ngành/VN bằng 0 nên không tính được tỷ lệ, phần FWCI được 0 điểm'
            : 'FWCI đơn vị/trung bình = '.Num::fmt($fo).'/'.Num::fmt($fa).' (tỷ lệ '.Num::fmt($rf).') nên phần FWCI được '.Num::fmt($sf).'/5 điểm';
        $fwciEn = $rf === null
            ? 'the sector/national average FWCI is 0, so the ratio cannot be computed — the FWCI part received 0 points'
            : 'organization/average FWCI = '.Num::fmt($fo).'/'.Num::fmt($fa).' (ratio '.Num::fmt($rf).'), so the FWCI part received '.Num::fmt($sf).'/5 points';
        $hVi = $rh === null
            ? 'H-index trung bình ngành bằng 0 nên không tính được tỷ lệ, phần H-index được 0 điểm'
            : 'H-index đơn vị/trung bình = '.Num::fmt($ho).'/'.Num::fmt($ha).' (tỷ lệ '.Num::fmt($rh).') nên phần H-index được '.Num::fmt($sh).'/5 điểm';
        $hEn = $rh === null
            ? 'the sector average H-index is 0, so the ratio cannot be computed — the H-index part received 0 points'
            : 'organization/average H-index = '.Num::fmt($ho).'/'.Num::fmt($ha).' (ratio '.Num::fmt($rh).'), so the H-index part received '.Num::fmt($sh).'/5 points';

        return new ScoreResult(
            $score,
            ucfirst($fwciVi).'; '.$hVi.'. Tổng: '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            ucfirst($fwciEn).'; '.$hEn.'. Total: '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function weightedRange(array $scoring, callable $in, $max): ScoreResult
    {
        $raw = 0;
        foreach ($scoring['weights'] as $k => $w) {
            $raw += $in($k) * $w;
        }

        $desc = RangeTable::describe($raw, $scoring['ranges']);
        $score = Num::clamp($desc['score'], $max);

        return new ScoreResult(
            $score,
            'Giá trị quy đổi tính từ số liệu đã nhập là '.Num::fmt($raw).'; '.$desc['vi'].'.',
            'The computed value from the entered figures is '.Num::fmt($raw).'; '.$desc['en'].'.',
        );
    }

    private function cappedWeightedSum(array $scoring, callable $in, $max): ScoreResult
    {
        $sum = 0;
        foreach ($scoring['weights'] as $k => $w) {
            $sum += $in($k) * $w;
        }

        $capped = $sum > $scoring['cap'];
        $score = Num::clamp(min($sum, $scoring['cap']), $max);

        return new ScoreResult(
            $score,
            'Tổng điểm quy đổi từ số liệu đã nhập là '.Num::fmt($sum)
                .($capped ? ', vượt mức trần '.Num::fmt($scoring['cap']).' nên bị giới hạn lại' : '')
                .', được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'The weighted total from the entered figures is '.Num::fmt($sum)
                .($capped ? ', which exceeds the cap of '.Num::fmt($scoring['cap']).' and was capped' : '')
                .' — it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function domesticPublication(callable $in, $max): ScoreResult
    {
        $sqt = $in('sqt');
        $str = $in('str');
        $bc = $in('bc');

        $part1 = min(7, 3 * $sqt + 1.5 * $str);
        $part2 = min(3, 0.5 * $bc);
        $score = Num::clamp($part1 + $part2, $max);

        return new ScoreResult(
            $score,
            'Sách quốc tế/trong nước ('.Num::fmt($sqt).'/'.Num::fmt($str).') quy đổi được '.Num::fmt($part1).'/7 điểm; báo cáo hội nghị ('.Num::fmt($bc).') quy đổi được '.Num::fmt($part2).'/3 điểm. Tổng: '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'International/domestic books ('.Num::fmt($sqt).'/'.Num::fmt($str).') convert to '.Num::fmt($part1).'/7 points; conference reports ('.Num::fmt($bc).') convert to '.Num::fmt($part2).'/3 points. Total: '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function ratioRange(array $scoring, callable $in, $max): ScoreResult
    {
        $num = $in($scoring['numerator']);
        $den = $in($scoring['denominator']);

        if ($den <= 0) {
            return new ScoreResult(0, 'Mẫu số bằng 0 nên không tính được tỷ lệ, được 0 điểm.', 'The denominator is 0, so the ratio cannot be computed — 0 points.');
        }

        $ratio = $num / $den;
        $desc = RangeTable::describe($ratio, $scoring['ranges']);
        $score = Num::clamp($desc['score'], $max);

        return new ScoreResult(
            $score,
            'Tỷ lệ tính được là '.Num::fmt($num).'/'.Num::fmt($den).' = '.Num::fmt($ratio).'; '.$desc['vi'].'.',
            'The computed ratio is '.Num::fmt($num).'/'.Num::fmt($den).' = '.Num::fmt($ratio).'; '.$desc['en'].'.',
        );
    }

    private function inverseCost(array $scoring, callable $in, $max): ScoreResult
    {
        $th = $in('threshold');
        $ac = $in('actual');

        if ($th <= 0 || $ac <= 0) {
            return new ScoreResult(0, 'Ngưỡng tối thiểu hoặc chi phí thực tế bằng 0 nên không tính được, được 0 điểm.', 'The minimum threshold or actual cost is 0, so it cannot be computed — 0 points.');
        }

        $score = Num::clamp(min($scoring['max_score'] * ($th / $ac), $scoring['max_score']), $max);

        return new ScoreResult(
            $score,
            'Chi phí thực tế '.Num::fmt($ac).' so với ngưỡng tối thiểu '.Num::fmt($th).' (chi phí càng thấp so với ngưỡng thì điểm càng cao) nên được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Actual cost '.Num::fmt($ac).' compared to the minimum threshold '.Num::fmt($th).' (the lower the cost relative to the threshold, the higher the score), so it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function positiveRatioCap(array $scoring, callable $in, $max): ScoreResult
    {
        $ac = $in('actual');
        $th = $in('threshold');

        if ($th <= 0) {
            return new ScoreResult(0, 'Ngưỡng tối đa bằng 0 nên không tính được tỷ lệ, được 0 điểm.', 'The maximum threshold is 0, so the ratio cannot be computed — 0 points.');
        }

        $score = Num::clamp(min($scoring['max_score'] * ($ac / $th), $scoring['max_score']), $max);

        return new ScoreResult(
            $score,
            'Tỷ lệ thực tế '.Num::fmt($ac).' so với ngưỡng tối đa '.Num::fmt($th).' nên được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Actual rate '.Num::fmt($ac).' compared to the maximum threshold '.Num::fmt($th).', so it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function basicTrainingUsage(callable $in, $max): ScoreResult
    {
        $docs = $in('teaching_docs');
        $theses = $in('theses');

        $a = $docs >= 3 ? 7 : ($docs == 2 ? 5 : ($docs == 1 ? 3 : 0));
        $b = $theses >= 6 ? 3 : ($theses >= 3 ? 2 : ($theses >= 1 ? 1 : 0));
        $score = Num::clamp($a + $b, $max);

        return new ScoreResult(
            $score,
            Num::fmt($docs).' tài liệu giảng dạy chính thức được '.Num::fmt($a).'/7 điểm; '.Num::fmt($theses).' luận văn/luận án sử dụng kết quả được '.Num::fmt($b).'/3 điểm. Tổng: '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            Num::fmt($docs).' official teaching material(s) score '.Num::fmt($a).'/7 points; '.Num::fmt($theses).' thesis/dissertation(s) using the results score '.Num::fmt($b).'/3 points. Total: '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function basicProgramRole(callable $in, $max): ScoreResult
    {
        // Lấy vai trò cao nhất đạt được
        $tiers = [
            ['intl_lead', 2.5, 'Có vai trò chủ nhiệm/điều phối chương trình quốc tế (mức cao nhất)', 'Has a lead/coordinator role in an international program (the highest tier)'],
            ['national_board', 2, 'Có vai trò ban chủ nhiệm chương trình quốc gia', 'Has a management-board role in a national program'],
            ['intl_member', 1, 'Có vai trò thành viên chính chương trình quốc tế', 'Has a key-member role in an international program'],
            ['national_member', 0.5, 'Có vai trò thành viên chương trình quốc gia', 'Has a member role in a national program'],
        ];

        $s = 0;
        $reasonVi = 'Không có vai trò nào trong chương trình KH&CN/ĐMST';
        $reasonEn = 'No role in any S&T/innovation program';
        foreach ($tiers as [$input, $points, $vi, $en]) {
            if ($in($input) > 0 && $points > $s) {
                [$s, $reasonVi, $reasonEn] = [$points, $vi, $en];
            }
        }

        $score = Num::clamp($s, $max);

        return new ScoreResult(
            $score,
            $reasonVi.' nên được '.Num::fmt($score).'/'.Num::fmt($max).' điểm (tính theo vai trò cao nhất đạt được).',
            $reasonEn.', so it received '.Num::fmt($score).'/'.Num::fmt($max).' points (based on the highest role achieved).',
        );
    }

    private function ipApplied(callable $in, $max): ScoreResult
    {
        $patent = $in('patent');
        $utility = $in('utility');
        $application = $in('application');
        $intlBonus = $in('intl_bonus') > 0;

        $s = 5 * $patent + 3 * $utility + min(2, 0.5 * $application) + ($intlBonus ? 1 : 0);
        $score = Num::clamp(min($s, 10), $max);

        return new ScoreResult(
            $score,
            Num::fmt($patent).' bằng sáng chế, '.Num::fmt($utility).' giải pháp hữu ích/IC/giống, '.Num::fmt($application).' đơn được chấp nhận'.($intlBonus ? ', có bằng quốc tế (+1 điểm)' : '').' nên được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            Num::fmt($patent).' patent(s), '.Num::fmt($utility).' utility solution(s)/IC layout/plant variety, '.Num::fmt($application).' accepted application(s)'.($intlBonus ? ', plus an international patent (+1 point)' : '').', so it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function ipFteRange(callable $in, $max): ScoreResult
    {
        $ip = $in('ip_score');
        $fte = $in('fte');
        $bm = $in('benchmark');

        if ($fte <= 0 || $bm <= 0) {
            return new ScoreResult(0, 'FTE hoặc trung bình quốc gia bằng 0 nên không tính được, được 0 điểm.', 'FTE or the national average is 0, so it cannot be computed — 0 points.');
        }

        $p = ($ip / $fte) / $bm;
        $desc = RangeTable::describe($p, [
            ['min' => 1.2, 'max' => null, 'score' => 10],
            ['min' => 1.0, 'max' => 1.2, 'score_min' => 8, 'score_mid' => 9, 'score_max' => 10],
            ['min' => 0.8, 'max' => 1.0, 'score_min' => 6, 'score_mid' => 7, 'score_max' => 8],
            ['min' => 0.7, 'max' => 0.8, 'score_min' => 4, 'score_mid' => 5, 'score_max' => 6],
            ['min' => 0.5, 'max' => 0.7, 'score_min' => 1, 'score_mid' => 2.5, 'score_max' => 4],
        ]);
        $score = Num::clamp($desc['score'], $max);

        return new ScoreResult(
            $score,
            'Năng suất IP/FTE so với trung bình quốc gia tính được là '.Num::fmt($p).' (điểm IP '.Num::fmt($ip).', FTE '.Num::fmt($fte).', trung bình quốc gia '.Num::fmt($bm).'); '.$desc['vi'].'.',
            'IP output per FTE relative to the national average is '.Num::fmt($p).' (IP score '.Num::fmt($ip).', FTE '.Num::fmt($fte).', national average '.Num::fmt($bm).'); '.$desc['en'].'.',
        );
    }

    private function costOutputApplied(callable $in): ScoreResult
    {
        $c = $in('cost');

        if ($c <= 0) {
            return new ScoreResult(0, 'Chi phí trung bình/đầu ra bằng 0 nên không hợp lệ, được 0 điểm.', 'The average cost per output is 0, which is invalid — 0 points.');
        }

        $score = match (true) {
            $c < 800 => 5,
            $c <= 900 => 4,
            $c <= 1000 => 3,
            $c <= 2000 => 2,
            default => 1,
        };

        return new ScoreResult(
            $score,
            'Chi phí trung bình/đầu ra là '.Num::fmt($c).' triệu VNĐ nên được '.Num::fmt($score).'/5 điểm (chi phí càng thấp thì điểm càng cao).',
            'Average cost per output is '.Num::fmt($c).' million VND, so it received '.Num::fmt($score).'/5 points (the lower the cost, the higher the score).',
        );
    }

    private function externalRevenueRatio(callable $in): ScoreResult
    {
        $e = $in('external');
        $t = $in('total');

        if ($t <= 0 || $e <= 0) {
            return new ScoreResult(0, 'Doanh thu bên ngoài hoặc tổng doanh thu bằng 0 nên không tính được, được 0 điểm.', 'External revenue or total revenue is 0, so it cannot be computed — 0 points.');
        }

        $r = ($e / $t) * 100;

        if ($r > 30) {
            [$score, $bucketVi, $bucketEn] = [10, 'trên 30%', 'above 30%'];
        } elseif ($r >= 15 && $r <= 30) {
            $score = $r < 22.5 ? 6 : (Num::eq($r, 22.5) ? 7 : 8);
            [$bucketVi, $bucketEn] = ['trong khoảng 15-30%', 'within the 15-30% range'];
        } elseif ($r > 0) {
            [$score, $bucketVi, $bucketEn] = [5, 'trên 0% nhưng dưới 15%', 'above 0% but below 15%'];
        } else {
            [$score, $bucketVi, $bucketEn] = [0, 'bằng 0%', 'equal to 0%'];
        }

        return new ScoreResult(
            $score,
            'Tỷ lệ doanh thu bên ngoài/tổng doanh thu là '.Num::fmt($e).'/'.Num::fmt($t).' = '.Num::fmt($r).'%, '.$bucketVi.' nên được '.Num::fmt($score).'/10 điểm.',
            'The ratio of external revenue to total revenue is '.Num::fmt($e).'/'.Num::fmt($t).' = '.Num::fmt($r).'%, which is '.$bucketEn.', so it received '.Num::fmt($score).'/10 points.',
        );
    }

    private function techLocalization(callable $in): ScoreResult
    {
        $p = max($in('p_lc'), $in('p_ndh'));

        if ($p > 70) {
            [$score, $bucketVi, $bucketEn] = [10, 'trên 70%', 'above 70%'];
        } elseif ($p >= 40 && $p <= 70) {
            $score = $p < 55 ? 5 : (Num::eq($p, 55) ? 6.5 : 8);
            [$bucketVi, $bucketEn] = ['trong khoảng 40-70%', 'within the 40-70% range'];
        } elseif ($p > 0 && $p < 40) {
            [$score, $bucketVi, $bucketEn] = [4, 'trên 0% nhưng dưới 40%', 'above 0% but below 40%'];
        } else {
            [$score, $bucketVi, $bucketEn] = [0, 'bằng 0%', 'equal to 0%'];
        }

        return new ScoreResult(
            $score,
            'Mức độ nội địa hóa/làm chủ công nghệ cao nhất (P_LC/P_NĐH) đạt '.Num::fmt($p).'%, '.$bucketVi.' nên được '.Num::fmt($score).'/10 điểm.',
            'The highest localization/technology-mastery level (P_LC/P_NDH) is '.Num::fmt($p).'%, which is '.$bucketEn.', so it received '.Num::fmt($score).'/10 points.',
        );
    }

    private function techCommercializationRate(callable $in): ScoreResult
    {
        $c = $in('commercialized');
        $t = $in('total');

        if ($t <= 0 || $c <= 0) {
            return new ScoreResult(0, 'Số kết quả đã thương mại hóa hoặc tổng kết quả đầu ra bằng 0 nên không tính được, được 0 điểm.', 'Commercialized outputs or total outputs is 0, so it cannot be computed — 0 points.');
        }

        $r = ($c / $t) * 100;

        if ($r > 50) {
            [$score, $bucketVi, $bucketEn] = [10, 'trên 50%', 'above 50%'];
        } elseif ($r >= 30 && $r <= 50) {
            $score = $r < 40 ? 5 : (Num::eq($r, 40) ? 6 : 7);
            [$bucketVi, $bucketEn] = ['trong khoảng 30-50%', 'within the 30-50% range'];
        } elseif ($r > 0) {
            [$score, $bucketVi, $bucketEn] = [4, 'trên 0% nhưng dưới 30%', 'above 0% but below 30%'];
        } else {
            [$score, $bucketVi, $bucketEn] = [0, 'bằng 0%', 'equal to 0%'];
        }

        return new ScoreResult(
            $score,
            'Tỷ lệ thương mại hóa là '.Num::fmt($c).'/'.Num::fmt($t).' = '.Num::fmt($r).'%, '.$bucketVi.' nên được '.Num::fmt($score).'/10 điểm.',
            'The commercialization rate is '.Num::fmt($c).'/'.Num::fmt($t).' = '.Num::fmt($r).'%, which is '.$bucketEn.', so it received '.Num::fmt($score).'/10 points.',
        );
    }

    private function techRoi(callable $in): ScoreResult
    {
        $rev = $in('revenue');
        $cost = $in('cost');
        $years = $in('years');

        if ($cost <= 0 || $rev < 0) {
            return new ScoreResult(0, 'Chi phí R&D bằng 0 hoặc doanh thu âm nên không hợp lệ, được 0 điểm.', 'R&D cost is 0 or revenue is negative, which is invalid — 0 points.');
        }

        $r = $rev / $cost;

        if (Num::eq($r, 0)) {
            return new ScoreResult(0,
                'Doanh thu từ R&D bằng 0 (chi phí '.Num::fmt($cost).') nên được 0 điểm.',
                'Revenue from R&D is 0 (cost '.Num::fmt($cost).'), so it received 0 points.');
        }
        if ($r <= 1) {
            return new ScoreResult(5,
                'Tỷ lệ doanh thu/chi phí R&D là '.Num::fmt($rev).'/'.Num::fmt($cost).' = '.Num::fmt($r).' (≤1) nên được 5/10 điểm.',
                'The revenue/R&D-cost ratio is '.Num::fmt($rev).'/'.Num::fmt($cost).' = '.Num::fmt($r).' (≤1), so it received 5/10 points.');
        }
        if ($years == 3 && $r >= 5) {
            return new ScoreResult(10,
                'Tỷ lệ '.Num::fmt($r).' đạt ngưỡng tối đa cho chu kỳ 3 năm (≥5) nên được 10/10 điểm.',
                'The ratio '.Num::fmt($r).' reaches the maximum threshold for the 3-year period (≥5), so it received 10/10 points.');
        }
        if ($years == 5 && $r >= 10) {
            return new ScoreResult(10,
                'Tỷ lệ '.Num::fmt($r).' đạt ngưỡng tối đa cho chu kỳ 5 năm (≥10) nên được 10/10 điểm.',
                'The ratio '.Num::fmt($r).' reaches the maximum threshold for the 5-year period (≥10), so it received 10/10 points.');
        }

        return new ScoreResult(9,
            'Tỷ lệ doanh thu/chi phí R&D là '.Num::fmt($r).' (>1) nhưng chưa đạt ngưỡng tối đa cho chu kỳ '.Num::fmt($years).' năm đã chọn nên được 9/10 điểm.',
            'The revenue/R&D-cost ratio is '.Num::fmt($r).' (>1) but does not reach the maximum threshold for the selected '.Num::fmt($years).'-year period, so it received 9/10 points.');
    }

    private function techSocialEnvironment(callable $in, $max): ScoreResult
    {
        $sb = $in('social_breakthrough');
        $sv = $in('social_value');
        $sBasic = $in('social_basic');
        $eb = $in('env_breakthrough');
        $ev = $in('env_value');
        $eBasic = $in('env_basic');

        $s = 5 * $sb + 3.5 * $sv + 2 * $sBasic + 5 * $eb + 3.5 * $ev + 2 * $eBasic;
        $score = Num::clamp(min($s, 10), $max);

        return new ScoreResult(
            $score,
            'Tác động xã hội (đột phá '.Num::fmt($sb).', có giá trị '.Num::fmt($sv).', cơ bản '.Num::fmt($sBasic).') và môi trường (đột phá '.Num::fmt($eb).', có giá trị '.Num::fmt($ev).', cơ bản '.Num::fmt($eBasic).') quy đổi thành '.Num::fmt($s).' điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Social impact (breakthrough '.Num::fmt($sb).', valuable '.Num::fmt($sv).', basic '.Num::fmt($sBasic).') and environmental impact (breakthrough '.Num::fmt($eb).', valuable '.Num::fmt($ev).', basic '.Num::fmt($eBasic).') convert to '.Num::fmt($s).' points — it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function policyRecommendation(callable $in): ScoreResult
    {
        $wr2 = $in('written_response_2');
        $o5 = $in('official_5');
        $s3 = $in('sector_3');
        $lm3 = $in('local_or_ministry_3');
        $l12 = $in('local_1_2');

        if ($wr2 >= 2) {
            return new ScoreResult(10,
                Num::fmt($wr2).' đề xuất được phản hồi/công nhận/trích dẫn/đưa vào xây dựng chính sách (≥2, mức cao nhất) nên được 10/10 điểm.',
                Num::fmt($wr2).' proposal(s) acknowledged/cited/incorporated into policy-making (≥2, the highest tier), so it received 10/10 points.');
        }
        if ($o5 >= 5) {
            return new ScoreResult(9,
                Num::fmt($o5).' đề xuất gửi chính thức tới cơ quan có thẩm quyền (≥5) nên được 9/10 điểm.',
                Num::fmt($o5).' proposal(s) formally submitted to a competent authority (≥5), so it received 9/10 points.');
        }
        if ($s3 >= 3) {
            return new ScoreResult(8,
                Num::fmt($s3).' đề xuất cấp ngành/liên ngành được xem xét (≥3) nên được 8/10 điểm.',
                Num::fmt($s3).' sector/inter-sector proposal(s) under review (≥3), so it received 8/10 points.');
        }
        if ($lm3 >= 3) {
            return new ScoreResult(6,
                Num::fmt($lm3).' đề xuất cấp địa phương/Bộ có phản hồi (≥3) nên được 6/10 điểm.',
                Num::fmt($lm3).' local/ministry-level proposal(s) with a response (≥3), so it received 6/10 points.');
        }
        if ($l12 >= 1) {
            return new ScoreResult(3,
                Num::fmt($l12).' đề xuất cấp địa phương đã gửi/tiếp nhận (≥1) nên được 3/10 điểm.',
                Num::fmt($l12).' local-level proposal(s) submitted/received (≥1), so it received 3/10 points.');
        }

        return new ScoreResult(0,
            'Chưa có đề xuất nào đạt ngưỡng tối thiểu của bất kỳ mức nào nên được 0 điểm.',
            'No proposal reaches the minimum threshold of any tier, so it received 0 points.');
    }

    private function policyApplied(callable $in): ScoreResult
    {
        $newBuild = $in('new_build');
        $replace = $in('replace');
        $multipleModify = $in('multiple_modify');
        $partialModify = $in('partial_modify');
        $referenced = $in('referenced');

        if ($newBuild > 0) {
            return new ScoreResult(10,
                Num::fmt($newBuild).' kết quả nghiên cứu xây dựng mới chính sách/văn bản (mức cao nhất) nên được 10/10 điểm.',
                Num::fmt($newBuild).' research result(s) that created a new policy/document (the highest tier), so it received 10/10 points.');
        }
        if ($replace > 0) {
            return new ScoreResult(8,
                Num::fmt($replace).' kết quả nghiên cứu thay thế chính sách cũ nên được 8/10 điểm.',
                Num::fmt($replace).' research result(s) that replaced an old policy, so it received 8/10 points.');
        }
        if ($multipleModify >= 2) {
            return new ScoreResult(6,
                Num::fmt($multipleModify).' kết quả nghiên cứu sửa đổi từ 2 chính sách trở lên (≥2) nên được 6/10 điểm.',
                Num::fmt($multipleModify).' research result(s) that revised 2 or more policies (≥2), so it received 6/10 points.');
        }
        if ($partialModify > 0) {
            return new ScoreResult(4,
                Num::fmt($partialModify).' kết quả nghiên cứu sửa đổi một phần chính sách nên được 4/10 điểm.',
                Num::fmt($partialModify).' research result(s) that partially revised a policy, so it received 4/10 points.');
        }
        if ($referenced > 0) {
            return new ScoreResult(2,
                Num::fmt($referenced).' kết quả nghiên cứu được tham khảo/sử dụng hạn chế nên được 2/10 điểm.',
                Num::fmt($referenced).' research result(s) referenced/used to a limited extent, so it received 2/10 points.');
        }

        return new ScoreResult(0,
            'Chưa có kết quả nghiên cứu nào được áp dụng ở bất kỳ mức nào nên được 0 điểm.',
            'No research result has been applied at any tier, so it received 0 points.');
    }

    private function policyAdvisory(callable $in, $max): ScoreResult
    {
        $low = $in('low');
        $local = $in('local');
        $dialogue = $in('dialogue');
        $ministry = $in('ministry');
        $national = $in('national');

        $s = 0.5 * $low + 1 * $local + 1.5 * $dialogue + 2 * $ministry + 2.5 * $national;
        $score = Num::clamp($s, $max);

        return new ScoreResult(
            $score,
            'Hoạt động tư vấn/phản biện: cấp thấp '.Num::fmt($low).', địa phương/trung gian '.Num::fmt($local).', đối thoại cấp cao '.Num::fmt($dialogue).', hội đồng cấp Bộ/ngành '.Num::fmt($ministry).', hội đồng cấp Quốc gia '.Num::fmt($national).' quy đổi thành '.Num::fmt($s).' điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Advisory/critique activity: low-level '.Num::fmt($low).', local/intermediary '.Num::fmt($local).', high-level dialogue '.Num::fmt($dialogue).', ministry/sector council '.Num::fmt($ministry).', national council '.Num::fmt($national).' convert to '.Num::fmt($s).' points — it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function policyCommunity(callable $in, $max): ScoreResult
    {
        $board = $in('board');
        $academicPolicy = $in('academic_policy');
        $intlMember = $in('intl_member');
        $intlLead = $in('intl_lead');

        $s = 1 * $board + 3 * $academicPolicy + 4 * $intlMember + 5 * $intlLead;
        $score = Num::clamp($s, $max);

        return new ScoreResult(
            $score,
            'Ảnh hưởng cộng đồng nghiên cứu: vai trò BCN/điều phối '.Num::fmt($board).', xây dựng chính sách học thuật '.Num::fmt($academicPolicy).', thành viên chính CT quốc tế '.Num::fmt($intlMember).', chủ nhiệm/điều phối CT quốc tế '.Num::fmt($intlLead).' quy đổi thành '.Num::fmt($s).' điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Research-community influence: management-board/coordinator role '.Num::fmt($board).', academic policy-making '.Num::fmt($academicPolicy).', international-program key member '.Num::fmt($intlMember).', international-program lead/coordinator '.Num::fmt($intlLead).' convert to '.Num::fmt($s).' points — it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function policyImpactKtxhmt(callable $in, $max): ScoreResult
    {
        $v = [];
        foreach (['kt3', 'kt2', 'kt1', 'xh3', 'xh2', 'xh1', 'mt3', 'mt2', 'mt1'] as $k) {
            $v[$k] = $in($k);
        }

        $s = 3 * $v['kt3'] + 2 * $v['kt2'] + $v['kt1'] + 3 * $v['xh3'] + 2 * $v['xh2'] + $v['xh1'] + 3 * $v['mt3'] + 2 * $v['mt2'] + $v['mt1'];
        $score = Num::clamp(min($s, 10), $max);

        $levels = fn (string $p) => Num::fmt($v[$p.'3']).'/'.Num::fmt($v[$p.'2']).'/'.Num::fmt($v[$p.'1']);

        return new ScoreResult(
            $score,
            'Tác động kinh tế (mức 3/2/1: '.$levels('kt').'), xã hội ('.$levels('xh').') và môi trường ('.$levels('mt').') quy đổi thành '.Num::fmt($s).' điểm, được '.Num::fmt($score).'/'.Num::fmt($max).' điểm.',
            'Economic impact (level 3/2/1: '.$levels('kt').'), social ('.$levels('xh').') and environmental ('.$levels('mt').') convert to '.Num::fmt($s).' points — it received '.Num::fmt($score).'/'.Num::fmt($max).' points.',
        );
    }

    private function policyBehaviorChange(callable $in): ScoreResult
    {
        $l = $in('level');

        if ($l >= 3) {
            return new ScoreResult(10,
                'Mức tác động nhập là '.Num::fmt($l).' (≥3, mức cao nhất) nên được 10/10 điểm.',
                'The entered impact level is '.Num::fmt($l).' (≥3, the highest tier), so it received 10/10 points.');
        }
        if ($l == 2) {
            return new ScoreResult(7, 'Mức tác động nhập là 2 nên được 7/10 điểm.', 'The entered impact level is 2, so it received 7/10 points.');
        }
        if ($l == 1) {
            return new ScoreResult(3, 'Mức tác động nhập là 1 nên được 3/10 điểm.', 'The entered impact level is 1, so it received 3/10 points.');
        }

        return new ScoreResult(0,
            'Chưa nhập mức tác động hợp lệ (1, 2 hoặc 3) nên được 0 điểm.',
            'No valid impact level (1, 2, or 3) was entered, so it received 0 points.');
    }

    private function policyDiffusion(callable $in): ScoreResult
    {
        $l = $in('level');

        if ($l >= 2) {
            return new ScoreResult(5,
                'Mức lan tỏa nhập là '.Num::fmt($l).' (≥2, mức cao nhất) nên được 5/5 điểm.',
                'The entered diffusion level is '.Num::fmt($l).' (≥2, the highest tier), so it received 5/5 points.');
        }
        if ($l == 1) {
            return new ScoreResult(3, 'Mức lan tỏa nhập là 1 nên được 3/5 điểm.', 'The entered diffusion level is 1, so it received 3/5 points.');
        }

        return new ScoreResult(0,
            'Chưa nhập mức lan tỏa hợp lệ (1 hoặc 2) nên được 0 điểm.',
            'No valid diffusion level (1 or 2) was entered, so it received 0 points.');
    }
}
