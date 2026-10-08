<?php

namespace App\Domain\Evaluation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Quy tắc kiểm tra bài nộp, sinh từ bộ tiêu chí (giống form cũ):
 *  - phải chọn ít nhất 1 chức năng, tổng trọng số các chức năng đã chọn = 100%
 *  - mỗi câu hỏi phải chọn Có/Không
 *  - câu định lượng: nhập đủ mọi ô số liệu; câu định tính: bắt buộc chú thích
 *  - mỗi câu phải có minh chứng: mô tả HOẶC ít nhất 1 tệp
 *
 * Dữ liệu (JSON hoặc multipart):
 *   organization_name, functions[], weights[func], answers[func][qid][yes|inputs|note|evidence_text],
 *   evidence[func][qid][] (tệp)
 */
final class EvaluationRules
{
    public const MAX_FILE_KB = 51200; // 50 MB / tệp

    /** @param array $existingFiles tệp đã có sẵn (khi sửa bài): [chức năng][mã câu] => true */
    public static function validate(array $definition, array $data, array $files, bool $requireOrganizationName, array $existingFiles = []): array
    {
        $functionKeys = array_keys($definition['functions']);

        $base = Validator::make($data, [
            'organization_name' => [$requireOrganizationName ? 'required' : 'nullable', 'string', 'max:255'],
            'functions' => ['required', 'array', 'min:1'],
            'functions.*' => ['distinct', 'in:'.implode(',', $functionKeys)],
            'weights' => ['required', 'array'],
        ], [
            'organization_name.required' => 'Vui lòng nhập tên tổ chức.',
            'functions.required' => 'Vui lòng chọn ít nhất 1 chức năng.',
            'functions.*.in' => 'Chức năng không hợp lệ.',
        ])->validate();

        $rules = [];
        $messages = [];
        $functions = $data['functions'];

        foreach ($functions as $funcKey) {
            $rules["weights.$funcKey"] = ['required', 'numeric', 'min:0', 'max:100'];
            $messages["weights.$funcKey.required"] = "Vui lòng nhập trọng số cho chức năng {$definition['functions'][$funcKey]['name']}.";

            foreach ($definition['functions'][$funcKey]['groups'] as $group) {
                foreach ($group['criteria'] as $q) {
                    $p = "answers.$funcKey.{$q['id']}";
                    $text = $q['text'];

                    $rules["$p.yes"] = ['required', 'in:0,1,true,false'];
                    $messages["$p.yes.required"] = "Vui lòng chọn Có/Không cho câu: {$text}";

                    foreach ($q['inputs'] ?? [] as $input) {
                        $rules["$p.inputs.{$input['name']}"] = ['required', 'numeric', 'min:0'];
                        $messages["$p.inputs.{$input['name']}.required"] = "Vui lòng nhập \"{$input['label']}\" cho câu: {$text}";
                        $messages["$p.inputs.{$input['name']}.numeric"] = "\"{$input['label']}\" phải là số (câu: {$text})";
                        $messages["$p.inputs.{$input['name']}.min"] = "\"{$input['label']}\" không được âm (câu: {$text})";
                    }

                    $quantitative = ($q['display_mode'] ?? '') === 'quantitative' || ! empty($q['inputs']);
                    $rules["$p.note"] = [$quantitative ? 'nullable' : 'required', 'string', 'max:5000'];
                    $messages["$p.note.required"] = "Vui lòng nhập chú thích cho câu: {$text}";

                    $hasFile = ! empty($files[$funcKey][$q['id']]) || ! empty($existingFiles[$funcKey][$q['id']]);
                    $rules["$p.evidence_text"] = [$hasFile ? 'nullable' : 'required', 'string', 'max:5000'];
                    $messages["$p.evidence_text.required"] = "Vui lòng nhập mô tả hoặc đính kèm tệp minh chứng cho câu: {$text}";

                    $rules["evidence.$funcKey.{$q['id']}.*"] = ['file', 'max:'.self::MAX_FILE_KB];
                }
            }
        }

        $validated = Validator::make($data + ['evidence' => $files], $rules, $messages)->validate();

        $weightSum = array_sum(array_map(fn ($f) => (float) $data['weights'][$f], $functions));
        if (abs($weightSum - 100) > 0.00001) {
            throw ValidationException::withMessages([
                'weights' => 'Tổng trọng số các chức năng đã chọn phải bằng 100% (hiện tại: '.$weightSum.'%).',
            ]);
        }

        return array_replace_recursive($base, $validated);
    }
}
