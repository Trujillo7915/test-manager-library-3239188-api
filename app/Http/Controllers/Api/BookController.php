<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BookController extends Controller
{
    /**
     * GET /books (autenticado)
     * Búsqueda por texto parcial en título o autor, filtro por categoría,
     * solo activos, orden por título asc, paginada (page, size máx 50).
     */
    public function index(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));
        $categoryId = $request->query('category_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Book::with('category')->active();

        if (!empty($q)) {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'ILIKE', "%{$q}%")
                    ->orWhere('author', 'ILIKE', "%{$q}%");
            });
        }

        if (!empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $books->map(fn($b) => $this->formatBook($b));

        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ], 200);
    }

    /**
     * GET /books/{id} (autenticado)
     */
    public function show(string $id): JsonResponse
    {
        $book = Book::with('category')->active()->find($id);

        if (!$book) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El libro solicitado no existe o ha sido dado de baja.',
                'details' => [],
            ], 404);
        }

        return response()->json($this->formatBook($book), 200);
    }

    /**
     * POST /books (admin)
     */
    public function store(Request $request): JsonResponse
    {
        $title = trim($request->input('title', ''));
        $author = trim($request->input('author', ''));
        $isbn = trim($request->input('isbn', ''));
        $categoryId = $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies', 0);

        if (empty($title) || empty($author) || empty($isbn) || empty($categoryId)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Todos los campos son obligatorios.',
                'details' => [],
            ], 422);
        }

        if (!preg_match('/^\d{13}$/', $isbn)) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El ISBN debe tener exactamente 13 dígitos numéricos.',
                'details' => [['field' => 'isbn', 'issue' => 'debe contener 13 dígitos']],
            ], 422);
        }

        if ($totalCopies < 1) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El total de ejemplares debe ser mayor o igual a 1.',
                'details' => [['field' => 'total_copies', 'issue' => 'debe ser >= 1']],
            ], 422);
        }

        if (!Category::where('id', $categoryId)->exists()) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'La categoría seleccionada no existe.',
                'details' => [['field' => 'category_id', 'issue' => 'no existe']],
            ], 422);
        }

        // ISBN único entre libros activos
        if (Book::active()->where('isbn', $isbn)->exists()) {
            return response()->json([
                'code' => 'ISBN_TAKEN',
                'message' => "El ISBN «{$isbn}» ya está registrado en un libro activo.",
                'details' => [['field' => 'isbn', 'issue' => 'ya registrado']],
            ], 409);
        }

        $book = Book::create([
            'id' => (string) Str::uuid(),
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category_id' => $categoryId,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies, // Arrancan iguales al total (CA-03.1)
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        $book->load('category');

        return response()->json($this->formatBook($book), 201);
    }

    /**
     * PUT /books/{id} (admin)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El libro solicitado no existe o ha sido dado de baja.',
                'details' => [],
            ], 404);
        }

        $title = trim($request->input('title', $book->title));
        $author = trim($request->input('author', $book->author));
        $categoryId = $request->input('category_id', $book->category_id);
        $newTotal = $request->has('total_copies') ? (int) $request->input('total_copies') : $book->total_copies;

        if ($newTotal < 1) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El total de ejemplares debe ser mayor o igual a 1.',
                'details' => [['field' => 'total_copies', 'issue' => 'debe ser >= 1']],
            ], 422);
        }

        // CA-03.3: Cambiar el total ajusta disponibles en la misma diferencia y nunca deja disponibles < 0 (422)
        $diff = $newTotal - $book->total_copies;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'La reducción de ejemplares no puede dejar los disponibles en número negativo.',
                'details' => [['field' => 'total_copies', 'issue' => 'dejaría disponibles < 0']],
            ], 422);
        }

        $book->update([
            'title' => $title,
            'author' => $author,
            'category_id' => $categoryId,
            'total_copies' => $newTotal,
            'available_copies' => $newAvailable,
        ]);

        $book->load('category');

        return response()->json($this->formatBook($book), 200);
    }

    /**
     * DELETE /books/{id} (admin)
     * Baja lógica (deleted_at).
     * CA-03.5: Un libro con préstamos abiertos no se da de baja (409 BOOK_HAS_OPEN_LOANS).
     */
    public function destroy(string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El libro no existe o ya fue dado de baja.',
                'details' => [],
            ], 404);
        }

        // Verificar si tiene préstamos abiertos (líneas con returned_at NULL)
        $hasOpenLoans = LoanItem::where('book_id', $id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            return response()->json([
                'code' => 'BOOK_HAS_OPEN_LOANS',
                'message' => 'No es posible dar de baja un libro que tiene préstamos abiertos pendientes de devolución.',
                'details' => [['field' => 'id', 'issue' => 'posee préstamos abiertos']],
            ], 409);
        }

        $book->update(['deleted_at' => Carbon::now('UTC')]);

        return response()->json(null, 204);
    }

    /**
     * PUT /books/{id}/cover (admin)
     * CA-03.4 & Seguridad §4: Sube portada (JPG/PNG, máx. 2 MB) guarda clave opaca.
     */
    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El libro no existe o ha sido dado de baja.',
                'details' => [],
            ], 404);
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'El archivo de portada es obligatorio.',
                'details' => [['field' => 'file', 'issue' => 'archivo no recibido']],
            ], 422);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType();

        if (!in_array($mime, ['image/jpeg', 'image/png'])) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Solo se permiten imágenes en formato JPG o PNG.',
                'details' => [['field' => 'file', 'issue' => 'formato inválido']],
            ], 422);
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'La imagen de portada no puede superar los 2 MB de tamaño.',
                'details' => [['field' => 'file', 'issue' => 'tamaño mayor a 2MB']],
            ], 422);
        }

        // Generar clave opaca única (nunca ruta en la base de datos)
        $opaqueKey = (string) Str::uuid() . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
        $file->storeAs('public/covers', $opaqueKey);

        $book->update(['cover_key' => $opaqueKey]);
        $book->load('category');

        return response()->json($this->formatBook($book), 200);
    }

    /**
     * DELETE /books/{id}/cover (admin)
     */
    public function deleteCover(string $id): JsonResponse
    {
        $book = Book::active()->find($id);

        if (!$book) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El libro no existe o ha sido dado de baja.',
                'details' => [],
            ], 404);
        }

        if ($book->cover_key) {
            Storage::delete('public/covers/' . $book->cover_key);
            $book->update(['cover_key' => null]);
        }

        return response()->json(null, 204);
    }

    /**
     * GET /books/{id}/cover (autenticado)
     */
    public function getCover(string $id)
    {
        $book = Book::active()->find($id);

        if (!$book || !$book->cover_key || !Storage::exists('public/covers/' . $book->cover_key)) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'La portada no existe.',
                'details' => [],
            ], 404);
        }

        return response()->file(storage_path('app/public/covers/' . $book->cover_key));
    }

    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $book->category->id,
                'name' => $book->category->name,
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }
}
