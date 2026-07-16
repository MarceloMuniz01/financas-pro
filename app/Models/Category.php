<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
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
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtém as transações associadas a esta categoria.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Obtém os contatos que usam esta categoria como padrão.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'default_category_id');
    }
}