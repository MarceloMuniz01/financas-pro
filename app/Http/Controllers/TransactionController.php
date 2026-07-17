<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    /**
     * Exibe a listagem paginada das transações.
     */
    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'type' => [
                'nullable',
                Rule::in([
                    'income',
                    'expense',
                ]),
            ],

            'category_id' => [
                'nullable',
                'integer',
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
        ]);

        $query = Transaction::query()
            ->where('user_id', $userId)
            ->with([
                /*
                 * Contato diretamente armazenado em transactions.contact_id.
                 */
                'contact' => fn($query) => $query
                    ->select([
                        'id',
                        'user_id',
                        'name',
                        'normalized_name',
                        'document',
                        'contact_type',
                        'merged_into_contact_id',
                    ])
                    ->with([
                        'aliases:id,user_id,contact_id,name,normalized_name',

                        /*
                         * Caso o contato da transação seja secundário,
                         * carrega o contato principal.
                         */
                        'mergedIntoContact' => fn($query) => $query
                            ->select([
                                'id',
                                'user_id',
                                'name',
                                'normalized_name',
                                'document',
                                'contact_type',
                                'merged_into_contact_id',
                            ])
                            ->with([
                                'aliases:id,user_id,contact_id,name,normalized_name',
                            ]),

                        /*
                         * Caso a transação já pertença ao principal,
                         * carrega os contatos vinculados ao grupo.
                         */
                        'mergedContacts' => fn($query) => $query
                            ->select([
                                'id',
                                'user_id',
                                'name',
                                'normalized_name',
                                'document',
                                'contact_type',
                                'merged_into_contact_id',
                                'merged_at',
                            ])
                            ->with([
                                'aliases:id,user_id,contact_id,name,normalized_name',
                            ]),
                    ]),

                'category:id,name,type,color',

                'import:id,bank',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Busca
        |--------------------------------------------------------------------------
        |
        | Busca pela descrição, contato original, contato principal,
        | contatos vinculados e aliases do grupo.
        |
        */

        $query->when(
            $filters['search'] ?? null,
            function (Builder $query, string $search): void {
                $search = trim($search);

                $query->where(
                    function (Builder $query) use ($search): void {
                        $query
                            ->where(
                                'description',
                                'ilike',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'contact',
                                function (Builder $contactQuery) use ($search): void {
                                    $contactQuery->where(
                                        function (Builder $contactQuery) use ($search): void {
                                            /*
                                             * Nome ou documento do contato
                                             * diretamente associado.
                                             */
                                            $contactQuery
                                                ->where(
                                                    'name',
                                                    'ilike',
                                                    "%{$search}%"
                                                )
                                                ->orWhere(
                                                    'document',
                                                    'ilike',
                                                    "%{$search}%"
                                                )

                                                /*
                                                 * Aliases do contato diretamente
                                                 * associado.
                                                 */
                                                ->orWhereHas(
                                                    'aliases',
                                                    fn(
                                                    Builder $aliasQuery
                                                ) => $aliasQuery->where(
                                                        'name',
                                                        'ilike',
                                                        "%{$search}%"
                                                    )
                                                )

                                                /*
                                                 * Se for um contato secundário,
                                                 * pesquisa também no principal.
                                                 */
                                                ->orWhereHas(
                                                    'mergedIntoContact',
                                                    function (Builder $mainQuery) use ($search): void {
                                                        $mainQuery
                                                            ->where(
                                                                'name',
                                                                'ilike',
                                                                "%{$search}%"
                                                            )
                                                            ->orWhere(
                                                                'document',
                                                                'ilike',
                                                                "%{$search}%"
                                                            )
                                                            ->orWhereHas(
                                                                'aliases',
                                                                fn(
                                                                Builder $aliasQuery
                                                            ) => $aliasQuery
                                                                    ->where(
                                                                        'name',
                                                                        'ilike',
                                                                        "%{$search}%"
                                                                    )
                                                            );
                                                    }
                                                )

                                                /*
                                                 * Se for o contato principal,
                                                 * pesquisa nos contatos vinculados.
                                                 */
                                                ->orWhereHas(
                                                    'mergedContacts',
                                                    function (Builder $linkedQuery) use ($search): void {
                                                        $linkedQuery
                                                            ->where(
                                                                'name',
                                                                'ilike',
                                                                "%{$search}%"
                                                            )
                                                            ->orWhere(
                                                                'document',
                                                                'ilike',
                                                                "%{$search}%"
                                                            )
                                                            ->orWhereHas(
                                                                'aliases',
                                                                fn(
                                                                Builder $aliasQuery
                                                            ) => $aliasQuery
                                                                    ->where(
                                                                        'name',
                                                                        'ilike',
                                                                        "%{$search}%"
                                                                    )
                                                            );
                                                    }
                                                );
                                        }
                                    );
                                }
                            );
                    }
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Receita ou despesa
        |--------------------------------------------------------------------------
        */

        $query->when(
            ($filters['type'] ?? null) === 'expense',
            fn(Builder $query) => $query->where(
                'amount',
                '<',
                0
            )
        );

        $query->when(
            ($filters['type'] ?? null) === 'income',
            fn(Builder $query) => $query->where(
                'amount',
                '>=',
                0
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Categoria
        |--------------------------------------------------------------------------
        */

        $query->when(
            $filters['category_id'] ?? null,
            fn(
            Builder $query,
            int|string $categoryId
        ) => $query->where(
                'category_id',
                (int) $categoryId
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Período
        |--------------------------------------------------------------------------
        */

        $query->when(
            $filters['date_from'] ?? null,
            fn(
            Builder $query,
            string $dateFrom
        ) => $query->whereDate(
                'transaction_date',
                '>=',
                $dateFrom
            )
        );

        $query->when(
            $filters['date_to'] ?? null,
            fn(
            Builder $query,
            string $dateTo
        ) => $query->whereDate(
                'transaction_date',
                '<=',
                $dateTo
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Totais considerando os filtros
        |--------------------------------------------------------------------------
        */

        $totalsQuery = clone $query;

        /*
         * Remove os eager loads da consulta agregada.
         */
        $totalsQuery->setEagerLoads([]);

        $totals = $totalsQuery
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN amount > 0
                            THEN amount
                            ELSE 0
                        END
                    ),
                    0
                ) AS income,

                COALESCE(
                    SUM(
                        CASE
                            WHEN amount < 0
                            THEN ABS(amount)
                            ELSE 0
                        END
                    ),
                    0
                ) AS expense,

                COALESCE(
                    SUM(amount),
                    0
                ) AS balance
                '
            )
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Paginação
        |--------------------------------------------------------------------------
        */

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        /*
         * Converte cada transação para uma estrutura própria para o frontend.
         *
         * A paginação é preservada.
         */
        $transactions->through(
            fn(Transaction $transaction): array =>
            $this->serializeTransaction($transaction)
        );

        /*
        |--------------------------------------------------------------------------
        | Categorias disponíveis
        |--------------------------------------------------------------------------
        */

        $categories = Category::query()
            ->where(
                function (Builder $query) use ($userId): void {
                    $query
                        ->whereNull('user_id')
                        ->orWhere(
                            'user_id',
                            $userId
                        );
                }
            )
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'color',
            ]);

        return Inertia::render('transactions/index', [
            'transactions' => $transactions,

            'categories' => $categories,

            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $filters['type'] ?? '',

                'category_id' => isset(
                    $filters['category_id']
                )
                    ? (string) $filters['category_id']
                    : '',

                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],

            'totals' => [
                'income' => (float) ($totals->income ?? 0),
                'expense' => (float) ($totals->expense ?? 0),
                'balance' => (float) ($totals->balance ?? 0),
            ],
        ]);
    }

    /**
     * Altera a categoria de uma transação.
     */
    public function updateCategory(
        Request $request,
        Transaction $transaction
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        /*
         * Garante que a transação pertence ao usuário autenticado.
         */
        if ((int) $transaction->user_id !== $userId) {
            abort(403);
        }

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists(
                    'categories',
                    'id'
                ),
            ],

            'scope' => [
                'required',
                Rule::in([
                    'current',
                    'all_from_contact',
                    'current_and_future',
                ]),
            ],
        ]);

        /*
         * A categoria precisa ser global ou pertencer ao usuário.
         */
        $category = Category::query()
            ->where(
                'id',
                $validated['category_id']
            )
            ->where(
                function (Builder $query) use ($userId): void {
                    $query
                        ->whereNull('user_id')
                        ->orWhere(
                            'user_id',
                            $userId
                        );
                }
            )
            ->firstOrFail();

        $transactionType = $transaction->amount < 0
            ? 'expense'
            : 'income';

        /*
         * Impede categoria de receita em despesa e vice-versa.
         */
        if ($category->type !== $transactionType) {
            throw ValidationException::withMessages([
                'category_id' => $transactionType === 'expense'
                    ? 'Uma despesa só pode receber uma categoria de despesa.'
                    : 'Uma receita só pode receber uma categoria de receita.',
            ]);
        }

        /*
         * Escopos que afetam o grupo ou futuras transações
         * exigem um contato associado.
         */
        if (
            $validated['scope'] !== 'current'
            && !$transaction->contact_id
        ) {
            throw ValidationException::withMessages([
                'scope' =>
                    'Esta transação não possui um contato associado.',
            ]);
        }

        DB::transaction(
            function () use ($validated, $transaction, $transactionType, $userId): void {
                /*
                |--------------------------------------------------------------------------
                | Somente esta transação
                |--------------------------------------------------------------------------
                */

                if ($validated['scope'] === 'current') {
                    $transaction->update([
                        'category_id' =>
                            $validated['category_id'],
                    ]);

                    return;
                }

                /*
                 * Contato diretamente associado à transação.
                 */
                $originalContact = Contact::query()
                    ->where(
                        'id',
                        $transaction->contact_id
                    )
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Resolve o principal do grupo.
                 *
                 * Caso o contato original já seja principal,
                 * ele mesmo será utilizado.
                 */
                $mainContact = $this->resolveMainContact(
                    contact: $originalContact,
                    userId: $userId,
                    lockForUpdate: true
                );

                /*
                |--------------------------------------------------------------------------
                | Todas as transações deste grupo de contatos
                |--------------------------------------------------------------------------
                |
                | Atualiza o histórico do principal e de todos os contatos
                | vinculados, sem mover transactions.contact_id.
                |
                */

                if (
                    $validated['scope']
                    === 'all_from_contact'
                ) {
                    $contactIds = $this->getContactGroupIds(
                        mainContact: $mainContact,
                        userId: $userId
                    );

                    Transaction::query()
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'contact_id',
                            $contactIds
                        )
                        ->when(
                            $transactionType === 'expense',
                            fn(Builder $query) => $query->where(
                                'amount',
                                '<',
                                0
                            )
                        )
                        ->when(
                            $transactionType === 'income',
                            fn(Builder $query) => $query->where(
                                'amount',
                                '>=',
                                0
                            )
                        )
                        ->update([
                            'category_id' =>
                                $validated['category_id'],

                            'updated_at' => now(),
                        ]);

                    /*
                     * A categoria padrão sempre fica no principal.
                     */
                    $this->updateContactDefaultCategory(
                        contact: $mainContact,
                        transactionType: $transactionType,
                        categoryId:
                        (int) $validated['category_id']
                    );

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | Esta transação e futuras transações
                |--------------------------------------------------------------------------
                |
                | Atualiza somente a transação atual e a categoria padrão
                | do principal. O histórico anterior permanece intacto.
                |
                */

                $transaction->update([
                    'category_id' =>
                        $validated['category_id'],
                ]);

                $this->updateContactDefaultCategory(
                    contact: $mainContact,
                    transactionType: $transactionType,
                    categoryId:
                    (int) $validated['category_id']
                );
            }
        );

        return back()->with(
            'success',
            'Categoria atualizada com sucesso.'
        );
    }

    /**
     * Serializa uma transação para o frontend.
     *
     * O campo "contact" sempre representa o contato principal
     * efetivo do grupo.
     *
     * O campo "original_contact" representa o contato armazenado
     * originalmente em transactions.contact_id.
     */
    private function serializeTransaction(
        Transaction $transaction
    ): array {
        $originalContact = $transaction->contact;

        $effectiveContact = $originalContact
            ? (
                $originalContact->mergedIntoContact
                ?? $originalContact
            )
            : null;

        $isFromMergedContact =
            $originalContact !== null
            && $effectiveContact !== null
            && (int) $originalContact->id
            !== (int) $effectiveContact->id;

        return [
            'id' => (int) $transaction->id,
            'user_id' => (int) $transaction->user_id,

            'import_id' => $transaction->import_id
                ? (int) $transaction->import_id
                : null,

            /*
             * O ID real continua disponível para operações internas.
             */
            'contact_id' => $transaction->contact_id
                ? (int) $transaction->contact_id
                : null,

            'category_id' => $transaction->category_id
                ? (int) $transaction->category_id
                : null,

            'transaction_date' =>
                $transaction->transaction_date
                        ?->format('Y-m-d'),

            'description' => $transaction->description,

            'amount' => (float) $transaction->amount,

            'type' => $transaction->amount < 0
                ? 'expense'
                : 'income',

            'source_type' =>
                $transaction->source_type,

            'counterparty_name' =>
                $transaction->counterparty_name,

            'counterparty_document' =>
                $transaction->counterparty_document,

            'counterparty_type' =>
                $transaction->counterparty_type,

            'transaction_method' =>
                $transaction->transaction_method,

            /*
             * Contato exibido normalmente pelo frontend.
             *
             * Sempre será o principal do grupo.
             */
            'contact' => $effectiveContact
                ? $this->serializeEffectiveContact(
                    contact: $effectiveContact,
                    originalContact: $originalContact
                )
                : null,

            /*
             * Contato realmente armazenado na transação.
             *
             * Só é retornado quando for diferente do principal.
             */
            'original_contact' => $isFromMergedContact
                ? [
                    'id' => (int) $originalContact->id,
                    'name' => $originalContact->name,
                    'normalized_name' =>
                        $originalContact->normalized_name,
                    'document' =>
                        $originalContact->document,
                    'contact_type' =>
                        $originalContact->contact_type,
                    'merged_into_contact_id' =>
                        (int) $effectiveContact->id,
                ]
                : null,

            'is_from_merged_contact' =>
                $isFromMergedContact,

            'category' => $transaction->category
                ? [
                    'id' =>
                        (int) $transaction->category->id,

                    'name' =>
                        $transaction->category->name,

                    'type' =>
                        $transaction->category->type,

                    'color' =>
                        $transaction->category->color,
                ]
                : null,

            'import' => $transaction->import
                ? [
                    'id' =>
                        (int) $transaction->import->id,

                    'bank' =>
                        $transaction->import->bank,
                ]
                : null,

            'created_at' =>
                $transaction->created_at
                        ?->toISOString(),

            'updated_at' =>
                $transaction->updated_at
                        ?->toISOString(),
        ];
    }

    /**
     * Serializa o contato efetivo da transação.
     */
    private function serializeEffectiveContact(
        Contact $contact,
        ?Contact $originalContact
    ): array {
        $linkedContacts = $contact
            ->mergedContacts
            ->map(
                static fn(
                Contact $linkedContact
            ): array => [
                    'id' =>
                        (int) $linkedContact->id,

                    'name' =>
                        $linkedContact->name,

                    'normalized_name' =>
                        $linkedContact->normalized_name,

                    'document' =>
                        $linkedContact->document,

                    'contact_type' =>
                        $linkedContact->contact_type,

                    'merged_at' =>
                        $linkedContact->merged_at
                                ?->toISOString(),
                ]
            )
            ->values();

        /*
         * Quando a transação pertence a um contato secundário,
         * mergedContacts pode não estar carregado no principal.
         *
         * Nesse caso, ainda informamos corretamente o vínculo
         * da transação atual.
         */
        $linkedContactsCount = $contact
            ->relationLoaded('mergedContacts')
            ? $contact->mergedContacts->count()
            : (
                $originalContact
                && (int) $originalContact->id
                !== (int) $contact->id
                ? 1
                : 0
            );

        return [
            'id' => (int) $contact->id,
            'user_id' => (int) $contact->user_id,
            'name' => $contact->name,

            'normalized_name' =>
                $contact->normalized_name,

            'document' => $contact->document,

            'contact_type' =>
                $contact->contact_type,

            'linked_contacts_count' =>
                $linkedContactsCount,

            'linked_contacts' =>
                $linkedContacts,
        ];
    }

    /**
     * Resolve o contato principal de um contato.
     */
    private function resolveMainContact(
        Contact $contact,
        int $userId,
        bool $lockForUpdate = false
    ): Contact {
        if (!$contact->merged_into_contact_id) {
            return $contact;
        }

        $query = Contact::query()
            ->where(
                'id',
                $contact->merged_into_contact_id
            )
            ->where(
                'user_id',
                $userId
            )
            ->whereNull(
                'merged_into_contact_id'
            );

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Retorna os IDs do principal e de todos os contatos
     * vinculados diretamente a ele.
     */
    private function getContactGroupIds(
        Contact $mainContact,
        int $userId
    ): Collection {
        $linkedContactIds = Contact::query()
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'merged_into_contact_id',
                $mainContact->id
            )
            ->pluck('id');

        return $linkedContactIds
            ->prepend(
                (int) $mainContact->id
            )
            ->map(
                static fn($id): int =>
                (int) $id
            )
            ->unique()
            ->values();
    }

    /**
     * Atualiza a categoria padrão do contato principal
     * conforme o tipo da transação.
     */
    private function updateContactDefaultCategory(
        Contact $contact,
        string $transactionType,
        int $categoryId
    ): void {
        if ($transactionType === 'expense') {
            $contact->update([
                'default_expense_category_id' =>
                    $categoryId,
            ]);

            return;
        }

        $contact->update([
            'default_income_category_id' =>
                $categoryId,
        ]);
    }
}