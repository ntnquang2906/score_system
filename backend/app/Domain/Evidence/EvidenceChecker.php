<?php

namespace App\Domain\Evidence;

use App\Domain\Scoring\QuestionScorer;

/**
 * Kết luận minh chứng của 1 tiêu chí, từ dữ kiện đã đọc được trong các tệp (attachments.analysis)
 * và quy tắc "evidence" của tiêu chí trong bộ tiêu chí:
 *
 *   fail    Không đạt (0 điểm): không có tệp, không đọc được, không liên quan tiêu chí, văn bản của đơn vị khác
 *   review  Cần xem lại (giữ điểm kê khai, chờ lãnh đạo): đúng loại nhưng thiếu dấu/chữ ký, sai năm,
 *           không thấy tên đơn vị, chưa chắc đúng loại văn bản, số mục ít hơn kê khai...
 *   pass    Đạt (giữ điểm kê khai)
 *   pending Đang đọc tệp (tạm giữ điểm kê khai)
 *
 * Quy tắc "evidence" của 1 tiêu chí:
 *   documents     loại văn bản chấp nhận (quyết định, biên bản...), rỗng = loại nào cũng được
 *   keywords      nội dung cần có (ít nhất 1 cụm)
 *   signed        cần chữ ký và con dấu
 *   period_years  văn bản ban hành trong N năm gần nhất (null = không xét)
 *   count         các ô số liệu kê khai cần đối chiếu với số mục trong minh chứng; min_items = số mục tối thiểu
 */
final class EvidenceChecker
{
    public const PASS = 'pass';

    public const REVIEW = 'review';

    public const FAIL = 'fail';

    public const PENDING = 'pending';

    public const VERDICTS = [self::PASS, self::REVIEW, self::FAIL];

    private const LABELS = [
        self::PASS => ['Đạt', 'Passed'],
        self::REVIEW => ['Cần xem lại', 'Needs review'],
        self::FAIL => ['Không đạt', 'Failed'],
    ];

    /**
     * @param  array  $files  [['id', 'name', 'status' => pending|done|failed, 'analysis' => [...]], ...]
     * @param  string[]  $otherUnits  tên các đơn vị khác (nhận ra minh chứng của đơn vị khác)
     * @return array|null null khi trả lời "Không" (không xét minh chứng)
     */
    public function check(array $question, array $answer, array $files, string $unitName, array $otherUnits, int $year): ?array
    {
        if (! QuestionScorer::isYes($answer['yes'] ?? null)) {
            return null;
        }
        $spec = $question['evidence'] ?? ['documents' => [], 'keywords' => [], 'signed' => false];

        // Chỉ có mô tả: mô tả/chú thích nhắc đúng nội dung tiêu chí (vd "hợp tác với X") => cần xem lại, không thì không đạt
        if (! $files) {
            $description = trim(($answer['evidence_text'] ?? '')."\n".($answer['note'] ?? ''));
            $mentioned = $description !== '' ? (new TextFacts($description))->found($spec['keywords']) : [];
            if ($mentioned) {
                $excerpt = mb_strimwidth(preg_replace('/\s+/u', ' ', $description), 0, 160, '…');

                return $this->conclude(self::REVIEW, [$this->reason('text_only', ['terms' => implode(', ', $mentioned), 'excerpt' => $excerpt])], []);
            }

            return $this->conclude(self::FAIL, [$this->reason('no_files', ['terms' => implode(', ', array_slice($spec['keywords'], 0, 4))])], []);
        }
        if (array_filter($files, fn ($f) => $f['status'] === 'pending')) {
            return $this->conclude(self::PENDING, [], []);
        }

        $facts = array_map(fn ($f) => $this->fileFacts($f, $spec, $unitName, $otherUnits, $year), $files);
        // Lỗi phía hệ thống (dịch vụ đọc lỗi, định dạng chưa hỗ trợ như .doc/.zip/HEIC) => xem trực tiếp, không trừ điểm
        $analyzed = array_filter($facts, fn ($f) => $f['status'] === 'done');
        if (! $analyzed) {
            $unsupported = array_filter($facts, fn ($f) => $f['status'] === 'unsupported');

            return $this->conclude(self::REVIEW, [$this->reason($unsupported ? 'unsupported_format' : 'analysis_failed')], $facts);
        }

        $readable = array_filter($analyzed, fn ($f) => $f['readable']);
        if (! $readable) {
            return $this->conclude(self::FAIL, [$this->reason('unreadable')], $facts);
        }
        $relevant = array_filter($readable, fn ($f) => $f['relevant'] !== 'no');
        if (! $relevant) {
            $ai = array_values(array_filter(array_column($readable, 'ai_reason')));

            return $this->conclude(self::FAIL, [$ai
                ? $this->reason('irrelevant_ai', ['reason' => $ai[0]])
                : $this->reason('irrelevant', ['terms' => implode(', ', array_slice($spec['keywords'], 0, 4))])], $facts);
        }
        $own = array_filter($relevant, fn ($f) => $f['unit'] !== 'other');
        if (! $own) {
            return $this->conclude(self::FAIL, [$this->reason('other_unit', ['other' => reset($relevant)['other_unit'], 'unit' => $unitName])], $facts);
        }

        $reasons = [];
        if (! array_filter($own, fn ($f) => $f['relevant'] === 'yes')) {
            $ai = array_values(array_filter(array_column($own, 'ai_reason')));
            $reasons[] = match (true) {
                (bool) $ai => $this->reason('uncertain_ai', ['reason' => $ai[0]]),
                (bool) array_filter($own, fn ($f) => $f['relevant'] === 'unclear_type') => $this->reason('uncertain_type', ['documents' => implode(', ', array_slice($spec['documents'], 0, 4))]),
                default => $this->reason('uncertain_topic', ['terms' => implode(', ', array_slice($spec['keywords'], 0, 4))]),
            };
        }
        if (! empty($spec['signed'])) {
            $editable = ! array_filter($own, fn ($f) => ! $f['editable']);
            if ($editable) {
                $reasons[] = $this->reason('editable');
            } else {
                if (! array_filter($own, fn ($f) => $f['stamp'])) {
                    $reasons[] = $this->reason(array_filter($own, fn ($f) => $f['no_color']) ? 'no_color' : 'no_stamp');
                }
                if (! array_filter($own, fn ($f) => $f['signature'])) {
                    $unsigned = array_values(array_filter($own, fn ($f) => $f['unsigned_block']));
                    $reasons[] = $unsigned
                        ? $this->reason('unsigned', ['title' => $unsigned[0]['unsigned_block']])
                        : $this->reason('no_signature');
                }
            }
        }
        if (! array_filter($own, fn ($f) => $f['unit'] === 'yes')) {
            $reasons[] = $this->reason('unit_not_found', ['unit' => $unitName]);
        }
        if (! empty($spec['period_years'])) {
            $years = array_filter(array_column($own, 'year'));
            $inPeriod = array_filter($years, fn ($y) => $y >= $year - $spec['period_years'] && $y <= $year + 1);
            if (! $years) {
                $reasons[] = $this->reason('no_date');
            } elseif (! $inPeriod) {
                $reasons[] = $this->reason('old_document', ['year' => max($years), 'n' => $spec['period_years']]);
            }
        }
        $declared = $this->declaredCount($spec, $answer);
        if ($declared > 0) {
            $found = array_sum(array_column($own, 'count'));
            if ($found < $declared) {
                $reasons[] = $this->reason('count_short', ['declared' => $declared, 'found' => $found, 'label' => $spec['count_label'] ?? 'mục']);
            }
        }

        return $this->conclude($reasons ? self::REVIEW : self::PASS, $reasons, $facts);
    }

    /** Lãnh đạo đặt lại kết luận: giữ kết luận tự động để đối chiếu */
    public function withReview(array $auto, string $verdict, string $reason, ?string $reviewer, string $at): array
    {
        [$vi, $en] = self::LABELS[$verdict];
        $note = rtrim(trim($reason), '.');

        return [
            ...$auto,
            'status' => $verdict,
            'source' => 'reviewer',
            'auto_status' => $auto['status'],
            'review' => ['verdict' => $verdict, 'reason' => $reason, 'by' => $reviewer, 'at' => $at],
            'explain_vi' => "Lãnh đạo kết luận minh chứng {$this->lower($vi)}: {$note}.".$this->consequence($verdict, 'vi')." (Hệ thống tự kiểm tra: {$auto['explain_vi']})",
            'explain_en' => "The reviewer marked the evidence as {$this->lower($en)}: {$note}.".$this->consequence($verdict, 'en')." (Automatic check: {$auto['explain_en']})",
        ];
    }

    /** Dữ kiện 1 tệp, đối chiếu với quy tắc của tiêu chí */
    private function fileFacts(array $file, array $spec, string $unitName, array $otherUnits, int $year): array
    {
        $a = $file['analysis'] ?? [];
        $status = $file['status'] === 'done' && in_array('unsupported_format', $a['warnings'] ?? [], true) ? 'unsupported' : $file['status'];
        $base = ['id' => $file['id'], 'name' => $file['name'], 'status' => $status];
        if ($status !== 'done') {
            return $base;
        }

        $text = new TextFacts((string) ($a['text'] ?? ''));
        $vlm = $a['vlm'] ?? null;   // nhận định của mô hình thị giác - ngôn ngữ (khi có GPU), ưu tiên hơn so khớp từ khoá
        $readable = ($a['readable'] ?? false) || $vlm;

        // Chủ đề văn bản nằm ở trích yếu: tiêu đề loại văn bản + dòng "Về việc..." ngay sau đó
        // (không có loại văn bản thì lấy phần đầu). Từ khoá chỉ có trong thân (vd tên "Phòng ... Hợp tác"
        // ở phần căn cứ) thì chưa chắc đúng chủ đề.
        $docType = $spec['documents'] ? $text->findEarliest($spec['documents'], 1500) : null;
        if ($docType) {
            $body = $text->findFirst(['căn cứ', 'theo đề nghị', 'xét đề nghị', 'pursuant'], 300, $docType['offset']);
            $subject = $text->findFirst($spec['keywords'], $body ? $body['offset'] - $docType['offset'] : 300, $docType['offset']);
        } else {
            $subject = $text->findFirst($spec['keywords'], 1500);
        }
        $keyword = $subject ?? $text->findFirst($spec['keywords']);
        $hit = $subject ?? $docType ?? $keyword;

        // Không kết luận "không liên quan" khi chữ quá ít/OCR kém, hoặc đúng loại văn bản mà chưa thấy từ khoá
        $relevant = match (true) {
            ! $readable => 'unreadable',
            (bool) $vlm => ['yes' => 'yes', 'no' => 'no'][$vlm['relevant'] ?? ''] ?? 'unclear_topic',
            $subject && (! $spec['documents'] || $docType) => 'yes',
            $subject => 'unclear_type',
            $keyword || $docType || $text->length() < 200 || in_array('garbled_text', $a['warnings'] ?? [], true) => 'unclear_topic',
            default => 'no',
        };

        $other = null;
        $unit = match (true) {
            $text->mentionsUnit($unitName) => 'yes',
            ($other = $text->headerUnit($otherUnits)) !== null => 'other',
            ! empty($vlm['mentions_unit']) => 'yes',
            default => 'unclear',
        };
        $stamp = ! empty($a['stamps']) || ! empty($vlm['stamp']);
        $signature = ! empty($a['signatures']) || ! empty($vlm['signature']);

        $warnings = $a['warnings'] ?? [];
        $unsignedBlocks = array_filter($a['signature_blocks'] ?? [], fn ($b) => ! $b['signed']);

        return $base + [
            'kind' => $a['kind'] ?? null,
            'readable' => $readable,
            'relevant' => $relevant,
            'doc_type' => $docType ? mb_convert_case($docType['term'], MB_CASE_TITLE) : (($vlm['doc_type'] ?? '') ?: null),
            'ai_reason' => $vlm['reason'] ?? null,
            'keywords' => $text->found($spec['keywords']),
            'quote' => $hit ? $text->quote($hit['offset']) : null,
            'page' => $hit ? $text->pageAt($hit['offset']) : null,
            'unit' => $unit,
            'other_unit' => $other,
            'year' => $text->year() ?? $vlm['year'] ?? null,
            'stamp' => $stamp,
            'stamp_page' => $a['stamps'][0]['page'] ?? null,
            'signature' => $signature,
            'digital_signature' => (bool) array_filter($a['signatures'] ?? [], fn ($s) => $s['method'] === 'digital'),
            'unsigned_block' => $signature ? null : (reset($unsignedBlocks)['title'] ?? null),
            'editable' => in_array('editable_document', $warnings, true),
            'no_color' => in_array('no_color', $warnings, true) && ! $stamp,
            'count' => ($a['kind'] ?? null) === 'xlsx' ? (int) ($a['rows'] ?? 0) : $text->itemCount(),
            'ocr' => ! empty($a['ocr_pages']),
        ];
    }

    private function declaredCount(array $spec, array $answer): int
    {
        if (! empty($spec['min_items'])) {
            return (int) $spec['min_items'];
        }

        return (int) array_sum(array_map(fn ($name) => (float) ($answer['inputs'][$name] ?? 0), $spec['count'] ?? []));
    }

    private function conclude(string $status, array $reasons, array $facts): array
    {
        $files = array_values($facts);
        [$vi, $en] = match ($status) {
            self::PENDING => [
                'Đang kiểm tra tệp minh chứng; tạm tính theo số liệu kê khai.',
                'Evidence files are being checked; the score is provisional, based on the declared figures.',
            ],
            self::PASS => [
                'Minh chứng đạt: '.$this->describe($files, 'vi').'.'.$this->consequence(self::PASS, 'vi'),
                'Evidence passed: '.$this->describe($files, 'en').'.'.$this->consequence(self::PASS, 'en'),
            ],
            self::REVIEW => [
                'Minh chứng cần xem lại: '.implode('; ', array_column($reasons, 'vi')).'.'.$this->consequence(self::REVIEW, 'vi'),
                'Evidence needs review: '.implode('; ', array_column($reasons, 'en')).'.'.$this->consequence(self::REVIEW, 'en'),
            ],
            self::FAIL => [
                'Minh chứng không đạt: '.implode('; ', array_column($reasons, 'vi')).'.'.$this->consequence(self::FAIL, 'vi'),
                'Evidence failed: '.implode('; ', array_column($reasons, 'en')).'.'.$this->consequence(self::FAIL, 'en'),
            ],
        };

        return [
            'status' => $status,
            'source' => 'auto',
            'reasons' => array_column($reasons, 'code'),
            'explain_vi' => $vi,
            'explain_en' => $en,
            'files' => $files,
        ];
    }

    private function consequence(string $verdict, string $lang): string
    {
        return match ($verdict) {
            self::PASS => $lang === 'vi' ? ' Giữ nguyên điểm theo số liệu kê khai.' : ' The score based on the declared figures is kept.',
            self::REVIEW => $lang === 'vi' ? ' Tạm giữ điểm theo số liệu kê khai, chờ lãnh đạo xác nhận.' : ' The declared score is kept provisionally, pending review.',
            self::FAIL => $lang === 'vi' ? ' Vì vậy tiêu chí này được 0 điểm.' : ' Therefore this criterion scores 0.',
        };
    }

    /** Mô tả tệp minh chứng tốt nhất: «QĐ.pdf» là Quyết Định (trang 1), năm 2025, có chữ ký và con dấu (trang 4) */
    private function describe(array $files, string $lang): string
    {
        usort($files, fn ($a, $b) => ($b['relevant'] === 'yes') <=> ($a['relevant'] === 'yes'));
        $f = $files[0];
        $parts = ["«{$f['name']}»"];
        if ($f['doc_type']) {
            $parts[0] .= ($lang === 'vi' ? ' là ' : ' is a ').$f['doc_type'].($f['page'] ? ($lang === 'vi' ? " (trang {$f['page']})" : " (page {$f['page']})") : '');
        } elseif ($f['keywords']) {
            $parts[0] .= ($lang === 'vi' ? ' có nội dung về ' : ' covers ').implode(', ', array_slice($f['keywords'], 0, 3));
        }
        if ($f['year']) {
            $parts[] = ($lang === 'vi' ? 'năm ' : 'dated ').$f['year'];
        }
        if ($f['stamp'] || $f['signature']) {
            $what = match (true) {
                $f['stamp'] && $f['signature'] => $lang === 'vi' ? 'có chữ ký và con dấu' : 'signed and stamped',
                $f['signature'] => $lang === 'vi' ? 'có chữ ký' : 'signed',
                default => $lang === 'vi' ? 'có con dấu' : 'stamped',
            };
            $parts[] = $what.($f['stamp_page'] ? ($lang === 'vi' ? " (trang {$f['stamp_page']})" : " (page {$f['stamp_page']})") : '');
        }
        if ($f['ai_reason'] ?? null) {
            $parts[] = ($lang === 'vi' ? 'AI nhận định: ' : 'AI: ').rtrim($f['ai_reason'], '.');
        }
        if (count($files) > 1) {
            $parts[] = $lang === 'vi' ? 'cùng '.(count($files) - 1).' tệp khác' : 'plus '.(count($files) - 1).' other file(s)';
        }

        return implode(', ', $parts);
    }

    private function reason(string $code, array $p = []): array
    {
        [$vi, $en] = match ($code) {
            'no_files' => ["không có tệp minh chứng và mô tả không nhắc tới nội dung của tiêu chí (như: {$p['terms']})", "no evidence file, and the description does not mention the criterion (e.g. {$p['terms']})"],
            'text_only' => ["chỉ có mô tả, chưa có tệp minh chứng; mô tả có nhắc tới {$p['terms']}: “{$p['excerpt']}”", "only a description, no evidence file; it mentions {$p['terms']}: “{$p['excerpt']}”"],
            'analysis_failed' => ['hệ thống chưa đọc được tệp (lỗi dịch vụ đọc tệp), cần xem trực tiếp', 'the files could not be processed (reader service error) and need a manual check'],
            'unsupported_format' => ['hệ thống chưa đọc được định dạng tệp này (vd .doc, .xls, .zip, HEIC), cần xem trực tiếp; nên nộp bản PDF hoặc ảnh chụp', 'this file format is not supported yet (e.g. .doc, .xls, .zip, HEIC) and needs a manual check; PDF or photos are preferred'],
            'unreadable' => ['không đọc được nội dung tệp (tệp hỏng, có mật khẩu, quá mờ hoặc định dạng chưa hỗ trợ)', 'the file content could not be read (damaged, password-protected, too blurry or unsupported format)'],
            'irrelevant' => ["tệp không liên quan đến tiêu chí (không thấy nội dung như: {$p['terms']})", "the file is unrelated to the criterion (no content such as: {$p['terms']})"],
            'other_unit' => ["đây là văn bản của đơn vị khác ({$p['other']}), không phải của {$p['unit']}", "this is a document of another unit ({$p['other']}), not of {$p['unit']}"],
            'uncertain_type' => ["chưa chắc đúng loại văn bản yêu cầu ({$p['documents']})", "not clearly the required document type ({$p['documents']})"],
            'irrelevant_ai' => ["tệp không chứng minh được tiêu chí (AI nhận định: {$p['reason']})", "the file does not prove the criterion (AI: {$p['reason']})"],
            'uncertain_ai' => ["chưa chắc tệp chứng minh được tiêu chí (AI nhận định: {$p['reason']})", "not clear that the file proves the criterion (AI: {$p['reason']})"],
            'uncertain_topic' => ["chưa chắc đúng nội dung tiêu chí: tiêu đề/trích yếu không nhắc tới {$p['terms']}", "the subject line does not clearly match the criterion ({$p['terms']})"],
            'editable' => ['tệp Word/Excel không có chữ ký, con dấu; nên tải bản PDF/ảnh chụp văn bản đã ký', 'a Word/Excel file carries no signature or stamp; upload a signed PDF/scan instead'],
            'no_stamp' => ['không thấy con dấu', 'no stamp found'],
            'no_color' => ['bản scan đen trắng nên không nhận ra con dấu đỏ', 'black-and-white scan, so the red stamp cannot be recognised'],
            'no_signature' => ['không thấy chữ ký', 'no signature found'],
            'unsigned' => ["có chỗ ký ({$p['title']}) nhưng chưa thấy chữ ký", "there is a signature block ({$p['title']}) but no signature"],
            'unit_not_found' => ["không thấy tên đơn vị \"{$p['unit']}\" trong văn bản", "the unit name \"{$p['unit']}\" does not appear in the document"],
            'no_date' => ['không xác định được ngày ban hành văn bản', 'the issue date could not be determined'],
            'old_document' => ["văn bản năm {$p['year']}, ngoài {$p['n']} năm gần nhất", "the document is from {$p['year']}, outside the last {$p['n']} years"],
            'count_short' => ["kê khai {$p['declared']} {$p['label']} nhưng minh chứng chỉ thấy {$p['found']}", "{$p['declared']} items declared but only {$p['found']} found in the evidence"],
        };

        return ['code' => $code, 'vi' => $vi, 'en' => $en];
    }

    private function lower(string $s): string
    {
        return mb_strtolower($s);
    }
}
