<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $actualRole = $request->user()?->role;
        $allowed = $role === 'admin' ? in_array($actualRole, ['admin', 'super_admin'], true) : $actualRole === $role;
        abort_unless($allowed, 403);
        return $next($request);
    }
}
