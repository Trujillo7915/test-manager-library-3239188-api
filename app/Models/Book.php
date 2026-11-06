<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Exceptions\BusinessException;

class Book extends Model
{
    protected $table = 'book';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'author',
        'isbn',
        'category_id',
        'total_copies',
        'available_copies',
        'cover_key',
        'deleted_at',
    ];

    protected $casts = [
        'total_copies' => 'integer',
        'available_copies' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function loanItems(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'book_id', 'id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Descuenta 1 copia disponible (RN-1, RNF-2).
     */
    public function withdraw(): void
    {
        if ($this->available_copies <= 0) {
            throw new BusinessException(
                'BOOK_NOT_AVAILABLE',
                "No quedan ejemplares disponibles de «{$this->title}».",
                409,
                [['field' => 'book_id', 'issue' => 'sin ejemplares']]
            );
        }

        $this->available_copies -= 1;
        $this->save();
    }

    /**
     * Repone 1 copia disponible tras devolución (RN-1).
     */
    public function restock(): void
    {
        if ($this->available_copies >= $this->total_copies) {
            // La base garantiza que no se supere el total
            return;
        }

        $this->available_copies += 1;
        $this->save();
    }
}
