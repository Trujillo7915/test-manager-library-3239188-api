<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * POST /users (solo admin)
     * CA-02.1: Crea un usuario con nombre, clave y rol 'librarian'.
     * CA-02.2: Nombre se guarda en minúsculas y recortado; es único (409 USERNAME_TAKEN).
     * CA-02.3: Pedir rol admin responde 422. La clave nunca se devuelve.
     */
    public function store(Request $request): JsonResponse
    {
        $role = $request->input('role');
        if ($role === 'admin') {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'No es posible crear usuarios con rol admin por la API.',
                'details' => [['field' => 'role', 'issue' => 'rol admin no permitido']],
            ], 422);
        }

        if ($role !== 'librarian') {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El rol debe ser estrictamente librarian.',
                'details' => [['field' => 'role', 'issue' => 'debe ser librarian']],
            ], 422);
        }

        $rawUsername = $request->input('username');
        if (empty($rawUsername)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El nombre de usuario es obligatorio.',
                'details' => [['field' => 'username', 'issue' => 'campo requerido']],
            ], 422);
        }

        $username = strtolower(trim($rawUsername));
        $password = $request->input('password');

        if (empty($password) || strlen($password) < 6) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'La contraseña debe tener al menos 6 caracteres.',
                'details' => [['field' => 'password', 'issue' => 'mínimo 6 caracteres']],
            ], 422);
        }

        if (User::where('username', $username)->exists()) {
            return response()->json([
                'code' => 'USERNAME_TAKEN',
                'message' => "El nombre de usuario «{$username}» ya está en uso.",
                'details' => [['field' => 'username', 'issue' => 'ya registrado']],
            ], 409);
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'librarian',
        ]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}
