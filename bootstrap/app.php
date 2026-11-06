<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'token.auth' => \App\Http\Middleware\AuthenticateWithToken::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Formato de error único según el Contrato de API (api-contract.md)
        $exceptions->render(function (ValidationException $e, Request $request) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $details[] = [
                        'field' => $field,
                        'issue' => $msg,
                    ];
                }
            }

            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Los datos proporcionados no son válidos.',
                'details' => $details,
            ], 422);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El recurso solicitado no fue encontrado.',
                'details' => [],
            ], 404);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return response()->json([
                'code' => 'FORBIDDEN_ROLE',
                'message' => 'No tiene permisos suficientes para realizar esta acción.',
                'details' => [],
            ], 403);
        });

        $exceptions->render(function (UnauthorizedHttpException $e, Request $request) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Credenciales inválidas o token no proporcionado.',
                'details' => [],
            ], 401);
        });

        $exceptions->render(function (\App\Exceptions\BusinessException $e, Request $request) {
            return response()->json([
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], $e->getStatusCode());
        });
    })->create();
