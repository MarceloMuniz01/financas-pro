<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'document',
        'contact_type',

        'default_expense_category_id',
        'default_income_category_id',

        'looks_like_contact_id',
        'similarity_dismissed_at',
    ];

    /**
     * Casts automáticos.
     */
    protected function casts(): array
    {
        return [
            'similarity_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Usuário dono do contato.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    /**
     * Categoria padrão para despesas.
     */
    public function defaultExpenseCategory(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'default_expense_category_id'
        );
    }

    /**
     * Categoria padrão para receitas.
     */
    public function defaultIncomeCategory(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'default_income_category_id'
        );
    }

    /**
     * Contato mais completo com o qual este contato se parece.
     */
    public function looksLikeContact(): BelongsTo
    {
        return $this->belongsTo(
            Contact::class,
            'looks_like_contact_id'
        );
    }

    /**
     * Contatos que apontam para este como possível duplicidade.
     */
    public function similarContacts(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'looks_like_contact_id'
        );
    }

    /**
     * Apelidos associados a este contato.
     *
     * Exemplo:
     *
     * contato principal:
     * Maria dos Navegantes
     *
     * apelidos:
     * mariadosnavegant
     * maria navegante
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(
            ContactAlias::class
        );
    }

    /**
     * Transações vinculadas ao contato.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(
            Transaction::class
        );
    }
}