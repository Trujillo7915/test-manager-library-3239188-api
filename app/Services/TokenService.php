<?php

namespace App\Services;

use Exception;

class TokenService
{
    /**
     * Genera un JWT firmado con HS256, vigencia de 8 horas.
     */
    public static function createToken(string $userId, string $role): array
    {
        $secret = env('JWT_SECRET', 'sdd_library_3239188_secret_jwt_token_sign_key_adso');
        $expiresIn = (int) env('JWT_TTL_HOURS', 8) * 3600; // 28800 segundos
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $expiresIn,
        ];

        $base64UrlHeader = self::base64UrlEncode(json_encode($header));
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        return [
            'access_token' => $jwt,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'role' => $role,
        ];
    }

    /**
     * Decodifica y valida un JWT firmado.
     */
    public static function decodeToken(string $jwt): array
    {
        $secret = env('JWT_SECRET', 'sdd_library_3239188_secret_jwt_token_sign_key_adso');
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new Exception('Formato de token inválido');
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        $signature = self::base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Firma de token inválida');
        }

        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);

        if (!$payload || !isset($payload['exp']) || time() >= $payload['exp']) {
            throw new Exception('Token expirado o inválido');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
