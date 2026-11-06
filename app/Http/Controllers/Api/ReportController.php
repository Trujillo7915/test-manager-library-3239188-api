<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * GET /reports/most-borrowed (solo admin)
     * HU-10 & ADR-004: Reporte de libros más prestados calculado en el motor y agrupado por valores congelados.
     */
    public function mostBorrowed(Request $request): JsonResponse
    {
        $fromStr = $request->query('from');
        $toStr = $request->query('to');

        if (empty($fromStr) || empty($toStr)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Los parámetros de fecha from y to son obligatorios.',
                'details' => [['field' => 'from/to', 'issue' => 'parámetros requeridos']],
            ], 422);
        }

        try {
            $fromDate = Carbon::parse($fromStr)->startOfDay();
            $toDate = Carbon::parse($toStr)->endOfDay();
        } catch (\Exception $e) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Formato de fecha inválido. Utilice el formato YYYY-MM-DD.',
                'details' => [],
            ], 422);
        }

        // CA-10.2: Fin anterior al inicio: 422 INVALID_DATE_RANGE
        if ($toDate->isBefore($fromDate)) {
            return response()->json([
                'code' => 'INVALID_DATE_RANGE',
                'message' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
                'details' => [['field' => 'to', 'issue' => 'fecha fin anterior a inicio']],
            ], 422);
        }

        // ADR-004: Consulta agregada agrupando por book_id, book_title, category_name congelados
        $results = DB::table('loan_item')
            ->join('loan', 'loan_item.loan_id', '=', 'loan.id')
            ->where('loan.loaned_at', '>=', $fromDate)
            ->where('loan.loaned_at', '<=', $toDate)
            ->select(
                'loan_item.book_id',
                'loan_item.book_title',
                'loan_item.category_name',
                DB::raw('COUNT(loan_item.id) as times_borrowed')
            )
            ->groupBy('loan_item.book_id', 'loan_item.book_title', 'loan_item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('loan_item.book_title', 'asc')
            ->get();

        $rows = $results->map(function ($r) {
            return [
                'book_id' => $r->book_id,
                'book_title' => $r->book_title,
                'category_name' => $r->category_name,
                'times_borrowed' => (int) $r->times_borrowed,
            ];
        });

        return response()->json([
            'from' => $fromStr,
            'to' => $toStr,
            'rows' => $rows,
        ], 200);
    }
}
