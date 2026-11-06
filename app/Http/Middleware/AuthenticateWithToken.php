<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\TokenService;
use App\Models\User;

class AuthenticateWithToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Token de autenticación no proporcionado o formato inválido.',
                'details' => [],
            ], 401);
        }

        $token = substr($header, 7);

        try {
            $payload = TokenService::decodeToken($token);
            $user = User::find($payload['sub']);

            if (!$user) {
                return response()->json([
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'El usuario asociado al token no existe.',
                    'details' => [],
                ], 401);
            }

            $request->attributes->set('auth_user', $user);
            $request->attributes->set('auth_role', $payload['role']);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Token inválido o expirado.',
                'details' => [],
            ], 401);
        }

        return $next($request);
    }
}
