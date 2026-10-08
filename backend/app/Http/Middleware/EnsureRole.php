<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dùng trong route: ->middleware('role:editor,viewer') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user && $user->hasAnyRole(...$roles), 403, 'Bạn không có quyền thực hiện thao tác này.');

        return $next($request);
    }
}
