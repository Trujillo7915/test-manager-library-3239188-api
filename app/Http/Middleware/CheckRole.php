<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $userRole = $request->attributes->get('auth_role');

        if (!$userRole || !in_array($userRole, $roles)) {
            return response()->json([
                'code' => 'FORBIDDEN_ROLE',
                'message' => 'No tiene permisos suficientes para realizar esta acción.',
                'details' => [],
            ], 403);
        }

        return $next($request);
    }
}
