<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Transaction extends Model
{
    use HasFactory;

    /**
     * Campos permitidos para Mass Assignment.
     */
    protected $fillable = [
        'user_id',
        'import_id',
        'contact_id',
        'category_id',

        'transaction_date',
        'transaction_code',

        'description',
        'amount',

        'source_type',
        'transaction_method',
    ];

    /**
     * Atributos calculados.
     */
    protected $appends = [
        'type',
    ];

    /**
     * Casts automáticos.
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Tipo da transação baseado no valor.
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount < 0
                ? 'expense'
                : 'income'
        );
    }

    /**
     * Usuário dono da transação.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Importação que originou esta transação.
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Contato (contraparte).
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Categoria atribuída.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Indica se é receita.
     */
    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    /**
     * Indica se é despesa.
     */
    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }
}