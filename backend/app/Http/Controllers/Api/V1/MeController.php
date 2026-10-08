<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'staff_code' => $user->staff_code,
            'unit_name' => $user->unit_name,
            'roles' => $user->roles,
            'tenant' => ['code' => $tenant->get()->code, 'name' => $tenant->get()->name],
        ]);
    }
}
