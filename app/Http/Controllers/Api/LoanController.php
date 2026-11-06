<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use App\Models\Book;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LoanController extends Controller
{
    /**
     * POST /loans (admin, librarian)
     * HU-06: Registrar préstamo.
     */
    public function store(Request $request): JsonResponse
    {
        $memberId = $request->input('member_id');
        $itemsData = $request->input('items', []);
        $authUser = $request->attributes->get('auth_user');

        if (empty($memberId) || !is_array($itemsData)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El socio y la lista de libros son requeridos.',
                'details' => [],
            ], 422);
        }

        // CA-06.1: Trae de 1 a 3 líneas
        $itemCount = count($itemsData);
        if ($itemCount < 1 || $itemCount > 3) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Un préstamo debe contener entre 1 y 3 libros.',
                'details' => [['field' => 'items', 'issue' => 'debe contener entre 1 y 3 elementos']],
            ], 422);
        }

        // Verificar socio
        $member = Member::find($memberId);
        if (!$member) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El socio especificado no existe.',
                'details' => [['field' => 'member_id', 'issue' => 'no encontrado']],
            ], 404);
        }

        // CA-06.2: No se repite el mismo libro en un préstamo
        $bookIds = [];
        foreach ($itemsData as $it) {
            $bId = $it['book_id'] ?? null;
            if (!$bId) {
                return response()->json([
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Cada línea debe incluir el book_id.',
                    'details' => [],
                ], 422);
            }
            if (in_array($bId, $bookIds)) {
                return response()->json([
                    'code' => 'DUPLICATE_BOOK_IN_LOAN',
                    'message' => 'No se permite repetir el mismo libro en un solo préstamo.',
                    'details' => [['field' => 'items', 'issue' => 'libro duplicado']],
                ], 422);
            }
            $bookIds[] = $bId;
        }

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro
        if ($member->hasOverdueLoans()) {
            return response()->json([
                'code' => 'MEMBER_HAS_OVERDUE',
                'message' => 'El socio tiene libros vencidos pendientes de devolución; no puede solicitar nuevos préstamos.',
                'details' => [['field' => 'member_id', 'issue' => 'posee préstamos vencidos']],
            ], 409);
        }

        // CA-06.4: Un socio no puede tener más de 3 libros sin devolver en total
        $currentActive = $member->getActiveLoansCount();
        if ($currentActive + $itemCount > 3) {
            return response()->json([
                'code' => 'MEMBER_LIMIT_EXCEEDED',
                'message' => "El socio ya tiene {$currentActive} libros prestados. Con estos {$itemCount} libros superaría el límite de 3.",
                'details' => [['field' => 'member_id', 'issue' => 'límite de 3 libros excedido']],
            ], 422);
        }

        // Cargar libros y verificar disponibilidad
        $books = Book::with('category')->active()->whereIn('id', $bookIds)->get()->keyBy('id');

        foreach ($bookIds as $bId) {
            $book = $books->get($bId);
            if (!$book) {
                return response()->json([
                    'code' => 'NOT_FOUND',
                    'message' => "El libro con ID «{$bId}» no existe o está dado de baja.",
                    'details' => [['field' => 'items', 'issue' => 'libro no existe']],
                ], 404);
            }

            // CA-06.3: Sin ejemplares: 409 y no se registra nada
            if ($book->available_copies <= 0) {
                return response()->json([
                    'code' => 'BOOK_NOT_AVAILABLE',
                    'message' => "No quedan ejemplares disponibles de «{$book->title}».",
                    'details' => [['field' => 'book_id', 'issue' => 'sin ejemplares disponibles']],
                ], 409);
            }
        }

        // Operación atómica en BD
        $now = $request->has('loaned_at') ? Carbon::parse($request->input('loaned_at')) : Carbon::now('UTC');
        $dueAt = (clone $now)->addDays(14); // CA-06.6: loaned_at + 14 días

        $loan = null;
        $createdItems = [];

        DB::transaction(function () use ($memberId, $authUser, $now, $dueAt, $bookIds, $books, &$loan, &$createdItems) {
            $loan = Loan::create([
                'id' => (string) Str::uuid(),
                'member_id' => $memberId,
                'loaned_by_user_id' => $authUser ? $authUser->id : User::first()->id,
                'loaned_at' => $now,
            ]);

            foreach ($bookIds as $bId) {
                $book = $books->get($bId);

                // Descontar ejemplar (RNF-2, RN-1)
                $book->withdraw();

                // CA-06.6: La línea congela título, ISBN y nombre de categoría en ese instante
                $loanItem = LoanItem::create([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loan->id,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category->name,
                    'due_at' => $dueAt,
                    'returned_at' => null,
                ]);

                $createdItems[] = [
                    'id' => $loanItem->id,
                    'book_id' => $loanItem->book_id,
                    'book_title' => $loanItem->book_title,
                    'isbn' => $loanItem->isbn,
                    'category_name' => $loanItem->category_name,
                    'due_at' => $loanItem->due_at->toIso8601ZuluString(),
                    'returned_at' => null,
                ];
            }
        });

        return response()->json([
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => $loan->loaned_at->toIso8601ZuluString(),
            'status' => 'open', // Recién creado siempre es open
            'items' => $createdItems,
        ], 201);
    }

    /**
     * GET /loans (admin, librarian)
     * Filtros: from, to, status, member_id, paginado, orden por fecha descendente.
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $statusFilter = $request->query('status');
        $memberId = $request->query('member_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Loan::with('items')->orderBy('loaned_at', 'desc');

        if (!empty($memberId)) {
            $query->where('member_id', $memberId);
        }

        if (!empty($from)) {
            $query->where('loaned_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if (!empty($to)) {
            $query->where('loaned_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $allLoans = $query->get();

        // Filtrar por status calculado si se especificó
        if (!empty($statusFilter)) {
            $allLoans = $allLoans->filter(function ($l) use ($statusFilter) {
                return $l->calculated_status === $statusFilter;
            });
        }

        $total = $allLoans->count();
        $paged = $allLoans->slice(($page - 1) * $size, $size)->values();

        $items = $paged->map(function ($l) {
            return $this->formatLoan($l);
        });

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    /**
     * GET /loans/{id} (admin, librarian)
     */
    public function show(string $id): JsonResponse
    {
        $loan = Loan::with('items')->find($id);

        if (!$loan) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El préstamo solicitado no existe.',
                'details' => [],
            ], 404);
        }

        return response()->json($this->formatLoan($loan), 200);
    }

    /**
     * POST /loans/{loan_id}/items/{item_id}/return (admin, librarian)
     * HU-07: Devolución por línea.
     */
    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        $loanItem = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->first();

        if (!$loanItem) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'La línea del préstamo no fue encontrada.',
                'details' => [],
            ], 404);
        }

        // CA-07.2: Devolver una línea ya devuelta: 409 ITEM_ALREADY_RETURNED
        if ($loanItem->returned_at !== null) {
            return response()->json([
                'code' => 'ITEM_ALREADY_RETURNED',
                'message' => 'Esta línea del préstamo ya fue devuelta previamente.',
                'details' => [['field' => 'item_id', 'issue' => 'línea ya devuelta']],
            ], 409);
        }

        DB::transaction(function () use ($loanItem) {
            $loanItem->returned_at = Carbon::now('UTC');
            $loanItem->save();

            // Suma 1 a los disponibles (CA-07.1)
            $book = Book::find($loanItem->book_id);
            if ($book) {
                $book->restock();
            }
        });

        return response()->json([
            'id' => $loanItem->id,
            'loan_id' => $loanItem->loan_id,
            'book_id' => $loanItem->book_id,
            'book_title' => $loanItem->book_title,
            'isbn' => $loanItem->isbn,
            'category_name' => $loanItem->category_name,
            'due_at' => $loanItem->due_at->toIso8601ZuluString(),
            'returned_at' => $loanItem->returned_at->toIso8601ZuluString(),
        ], 200);
    }

    /**
     * GET /loans/overdue (admin, librarian)
     * HU-09: Líneas sin devolver con due_at < now(), ordenadas por más días de retraso primero.
     */
    public function overdue(): JsonResponse
    {
        $now = Carbon::now('UTC');

        $overdueItems = LoanItem::with(['loan.member'])
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->get();

        // Ordenar por días de retraso descendente
        $sorted = $overdueItems->sortByDesc(fn($item) => $item->days_late)->values();

        $rows = $sorted->map(function ($it) {
            return [
                'loan_id' => $it->loan_id,
                'item_id' => $it->id,
                'book_title' => $it->book_title,
                'member_name' => $it->loan && $it->loan->member ? $it->loan->member->full_name : 'Desconocido',
                'due_at' => $it->due_at->toIso8601ZuluString(),
                'days_late' => $it->days_late,
            ];
        });

        return response()->json([
            'items' => $rows,
            'total' => $rows->count(),
        ], 200);
    }

    private function formatLoan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => $loan->loaned_at->toIso8601ZuluString(),
            'status' => $loan->calculated_status,
            'items' => $loan->items->map(function ($it) {
                return [
                    'id' => $it->id,
                    'book_id' => $it->book_id,
                    'book_title' => $it->book_title,
                    'isbn' => $it->isbn,
                    'category_name' => $it->category_name,
                    'due_at' => $it->due_at->toIso8601ZuluString(),
                    'returned_at' => $it->returned_at ? $it->returned_at->toIso8601ZuluString() : null,
                ];
            })->toArray(),
        ];
    }
}
