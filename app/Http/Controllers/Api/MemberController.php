<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Member;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    /**
     * GET /members (admin, librarian)
     * Lista y busca por nombre o documento, paginado.
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Member::query();

        if (!empty($q)) {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'ILIKE', "%{$q}%")
                    ->orWhere('document_number', 'ILIKE', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $members->map(function ($m) {
            return [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'document_number' => $m->document_number,
                'active_loans' => $m->getActiveLoansCount(),
            ];
        });

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    /**
     * GET /members/{id} (admin, librarian)
     */
    public function show(string $id): JsonResponse
    {
        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El socio no fue encontrado.',
                'details' => [],
            ], 404);
        }

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $member->getActiveLoansCount(),
        ], 200);
    }

    /**
     * POST /members (admin, librarian)
     * CA-05.1: Crea socio con nombre y documento. Documento único (409 DOCUMENT_TAKEN).
     */
    public function store(Request $request): JsonResponse
    {
        $fullName = trim($request->input('full_name', ''));
        $documentNumber = trim($request->input('document_number', ''));

        if (empty($fullName) || empty($documentNumber)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El nombre completo y el número de documento son obligatorios.',
                'details' => [],
            ], 422);
        }

        if (Member::where('document_number', $documentNumber)->exists()) {
            return response()->json([
                'code' => 'DOCUMENT_TAKEN',
                'message' => "El número de documento «{$documentNumber}» ya se encuentra registrado.",
                'details' => [['field' => 'document_number', 'issue' => 'documento duplicado']],
            ], 409);
        }

        $member = Member::create([
            'id' => (string) Str::uuid(),
            'full_name' => $fullName,
            'document_number' => $documentNumber,
        ]);

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => 0,
        ], 201);
    }
}
