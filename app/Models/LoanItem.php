<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class LoanItem extends Model
{
    protected $table = 'loan_item';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'loan_id',
        'book_id',
        'book_title',
        'isbn',
        'category_name',
        'due_at',
        'returned_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id', 'id');
    }

    /**
     * Días de retraso calculados si el ítem no ha sido devuelto y está vencido.
     */
    public function getDaysLateAttribute(): int
    {
        if ($this->returned_at !== null) {
            return 0;
        }

        $now = Carbon::now('UTC');
        $due = Carbon::parse($this->due_at);

        if ($due->isBefore($now)) {
            return (int) $due->diffInDays($now);
        }

        return 0;
    }
}
