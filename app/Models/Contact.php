<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $default_expense_category_id
 * @property int|null $default_income_category_id
 * @property int|null $merged_into_contact_id
 * @property string $name
 * @property string $normalized_name
 * @property string|null $document
 * @property string|null $contact_type
 * @property Carbon|null $merged_at
 * @property-read int|null $transactions_count
 */
class Contact extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'normalized_name',
        'document',
        'contact_type',

        'default_expense_category_id',
        'default_income_category_id',

        'merged_into_contact_id',
        'merged_at',
    ];

    /**
     * Casts automáticos.
     */
    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
        ];
    }

    /**
     * Usuário dono do contato.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    /**
     * Categoria padrão para despesas.
     *
     * @return BelongsTo<Category, $this>
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
     *
     * @return BelongsTo<Category, $this>
     */
    public function defaultIncomeCategory(): BelongsTo
    {
        return $this->belongsTo(
            Category::class,
            'default_income_category_id'
        );
    }

    /**
     * Contato principal no qual este contato foi mesclado.
     *
     * Quando este campo estiver preenchido, este contato é
     * considerado um contato secundário.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function mergedIntoContact(): BelongsTo
    {
        return $this->belongsTo(
            Contact::class,
            'merged_into_contact_id'
        );
    }

    /**
     * Contatos secundários mesclados neste contato principal.
     *
     * @return HasMany<Contact, $this>
     */
    public function mergedContacts(): HasMany
    {
        return $this->hasMany(
            Contact::class,
            'merged_into_contact_id'
        );
    }

    /**
     * Apelidos associados diretamente a este contato.
     *
     * @return HasMany<ContactAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(
            ContactAlias::class
        );
    }

    /**
     * Transações vinculadas diretamente ao contato.
     *
     * As transações não são movidas durante a mesclagem.
     * Elas continuam vinculadas ao contato original.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(
            Transaction::class
        );
    }

    /**
     * Verifica se este contato é principal.
     */
    public function isMainContact(): bool
    {
        return $this->merged_into_contact_id === null;
    }

    /**
     * Verifica se este contato está mesclado em outro.
     */
    public function isMergedContact(): bool
    {
        return $this->merged_into_contact_id !== null;
    }

    /**
     * Retorna o contato principal efetivo.
     *
     * Como a regra impede cadeias de mesclagem, há somente:
     *
     * secundário -> principal
     */
    public function effectiveContact(): Contact
    {
        if ($this->merged_into_contact_id === null) {
            return $this;
        }

        $this->loadMissing(
            'mergedIntoContact'
        );

        return $this->mergedIntoContact
            ?? $this;
    }

    /**
     * Retorna o ID do contato principal efetivo.
     */
    public function effectiveContactId(): int
    {
        return $this->merged_into_contact_id
            ?? $this->id;
    }

    /**
     * Categoria efetiva de despesa.
     *
     * Quando o contato estiver mesclado, a categoria do
     * contato principal sempre prevalece.
     */
    public function effectiveExpenseCategoryId(): ?int
    {
        return $this
            ->effectiveContact()
            ->default_expense_category_id;
    }

    /**
     * Categoria efetiva de receita.
     *
     * Quando o contato estiver mesclado, a categoria do
     * contato principal sempre prevalece.
     */
    public function effectiveIncomeCategoryId(): ?int
    {
        return $this
            ->effectiveContact()
            ->default_income_category_id;
    }

    /**
     * Retorna a categoria efetiva conforme o tipo da transação.
     */
    public function effectiveCategoryId(
        string $transactionType
    ): ?int {
        return match ($transactionType) {
            'expense' => $this->effectiveExpenseCategoryId(),

            'income' => $this->effectiveIncomeCategoryId(),

            default => null,
        };
    }

    /**
     * Retorna a categoria de despesa efetiva.
     */
    public function effectiveExpenseCategory(): ?Category
    {
        $contact = $this->effectiveContact();

        $contact->loadMissing(
            'defaultExpenseCategory'
        );

        return $contact->defaultExpenseCategory;
    }

    /**
     * Retorna a categoria de receita efetiva.
     */
    public function effectiveIncomeCategory(): ?Category
    {
        $contact = $this->effectiveContact();

        $contact->loadMissing(
            'defaultIncomeCategory'
        );

        return $contact->defaultIncomeCategory;
    }
}
