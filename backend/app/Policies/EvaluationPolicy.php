<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\User;

class EvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canSeeAllEvaluations() || $user->unitKey() !== null;
    }

    public function view(User $user, Evaluation $evaluation): bool
    {
        return $user->canSeeAllEvaluations()
            || ($user->unitKey() !== null && $evaluation->organization->normalized_key === $user->unitKey());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole('admin', 'editor', 'unit');
    }

    /** Sửa bài đã nộp: chỉ quản trị và tài khoản quyền sửa (như edit_file.php của hệ cũ) */
    public function update(User $user, Evaluation $evaluation): bool
    {
        return $user->hasAnyRole('admin', 'editor');
    }
}
