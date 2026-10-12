<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $import_id
 * @property int|null $contact_id
 * @property int|null $category_id
 * @property string|null $transaction_code
 * @property Carbon $transaction_date
 * @property string $description
 * @property string $amount
 * @property string $source_type
 * @property string $transaction_method
 * @property-read 'income'|'expense' $type
 */
class Transaction extends Model
{
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

    protected $appends = [
        'type',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return Attribute<'income'|'expense', never>
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->amount < 0
                ? 'expense'
                : 'income',
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isIncome(): bool
    {
        return $this->type === 'income';
    }

    public function isExpense(): bool
    {
        return $this->type === 'expense';
    }
}
