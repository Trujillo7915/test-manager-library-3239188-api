<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Category;

class CategoryController extends Controller
{
    /**
     * GET /categories (autenticado)
     * Las cinco categorías, orden por nombre.
     */
    public function index(): JsonResponse
    {
        $categories = Category::orderBy('name', 'asc')->get(['id', 'name']);
        return response()->json($categories, 200);
    }
}
