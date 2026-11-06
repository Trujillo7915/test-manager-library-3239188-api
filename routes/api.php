<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes — Manager Library (/api/v1)
| Contrato HTTP estricto según api-contract.md
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // 3.1 Públicos
    Route::get('/health', [HealthController::class, 'check']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Rutas protegidas por Token
    Route::middleware('token.auth')->group(function () {

        // 3.2 Usuarios (solo admin)
        Route::middleware('role:admin')->group(function () {
            Route::post('/users', [UserController::class, 'store']);
        });

        // 3.3 Categorías
        Route::get('/categories', [CategoryController::class, 'index']);

        // 3.4 Libros - Lectura
        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);
        Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

        // 3.4 Libros - Gestión (solo admin)
        Route::middleware('role:admin')->group(function () {
            Route::post('/books', [BookController::class, 'store']);
            Route::put('/books/{id}', [BookController::class, 'update']);
            Route::delete('/books/{id}', [BookController::class, 'destroy']);
            Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
            Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);
        });

        // 3.5 Socios (admin, librarian)
        Route::middleware('role:admin,librarian')->group(function () {
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/members/{id}', [MemberController::class, 'show']);
            Route::post('/members', [MemberController::class, 'store']);
        });

        // 3.6 Préstamos (admin, librarian)
        Route::middleware('role:admin,librarian')->group(function () {
            Route::post('/loans', [LoanController::class, 'store']);
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/overdue', [LoanController::class, 'overdue']);
            Route::get('/loans/{id}', [LoanController::class, 'show']);
            Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
        });

        // 3.7 Reporte (solo admin)
        Route::middleware('role:admin')->group(function () {
            Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
        });

    });

});
