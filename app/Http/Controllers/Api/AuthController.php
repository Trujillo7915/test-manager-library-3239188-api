<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Services\TokenService;

class AuthController extends Controller
{
    /**
     * POST /auth/login
     * CA-01.1: Recibe token con vigencia de 8 horas.
     * CA-01.2: Con credenciales inválidas responde 401 con mensaje que no dice si falló usuario o clave.
     */
    public function login(Request $request): JsonResponse
    {
        $username = strtolower(trim($request->input('username', '')));
        $password = $request->input('password', '');

        if (empty($username) || empty($password)) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.',
                'details' => [],
            ], 401);
        }

        $user = User::where('username', $username)->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas.',
                'details' => [],
            ], 401);
        }

        $tokenData = TokenService::createToken($user->id, $user->role);

        return response()->json($tokenData, 200);
    }
}
