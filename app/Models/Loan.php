<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Loan extends Model
{
    protected $table = 'loan';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'member_id',
        'loaned_by_user_id',
        'loaned_at',
    ];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function loanedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'loan_id', 'id');
    }

    /**
     * Estado calculado según Constitución Artículo VII y api-contract.md §3.6:
     * - 'open': hay líneas sin devolver y ninguna vencida.
     * - 'overdue': alguna línea sin devolver está vencida.
     * - 'closed': todas las líneas están devueltas.
     */
    public function getCalculatedStatusAttribute(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return 'closed';
        }

        $allReturned = $items->every(fn($item) => $item->returned_at !== null);
        if ($allReturned) {
            return 'closed';
        }

        $now = Carbon::now('UTC');
        $hasOverdue = $items->some(fn($item) => $item->returned_at === null && Carbon::parse($item->due_at)->isBefore($now));

        return $hasOverdue ? 'overdue' : 'open';
    }
}
