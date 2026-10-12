<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * obs: aqui não usamos PHPDoc pois o Laravel já faz isso
     */
    protected $fillable = [
        'user_id',
        'type',
        'name',
        'color',
        'keywords',
    ];

    /**
     * Obtém o usuário dono desta categoria (nulo se for categoria global).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtém as transações associadas a esta categoria.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function expenseContacts(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'default_expense_category_id'
        );
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function incomeContacts(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'default_income_category_id'
        );
    }
}
