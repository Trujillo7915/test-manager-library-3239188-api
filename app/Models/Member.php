<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Member extends Model
{
    protected $table = 'member';
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'full_name',
        'document_number',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id', 'id');
    }

    public function loanItems(): HasManyThrough
    {
        return $this->hasManyThrough(LoanItem::class, Loan::class, 'member_id', 'loan_id', 'id', 'id');
    }

    /**
     * Cuenta de libros prestados sin devolver actualmente.
     */
    public function getActiveLoansCount(): int
    {
        return $this->loanItems()->whereNull('returned_at')->count();
    }

    /**
     * Verifica si el socio tiene al menos un libro con fecha vencida sin devolver.
     */
    public function hasOverdueLoans(): bool
    {
        return $this->loanItems()
            ->whereNull('returned_at')
            ->where('due_at', '<', now())
            ->exists();
    }
}
