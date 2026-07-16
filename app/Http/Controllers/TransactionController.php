<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $userId = $request->user()->id;

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
                'contact:id,name,contact_type',
                'category:id,name,type,color',
                'import:id,bank',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Busca por descrição ou contato
        |--------------------------------------------------------------------------
        */

        $query->when(
            $filters['search'] ?? null,
            function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where(
                            'description',
                            'ilike',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'contact',
                            function ($query) use ($search) {
                                $query->where(
                                    'name',
                                    'ilike',
                                    "%{$search}%"
                                );
                            }
                        );
                });
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Receita ou despesa
        |--------------------------------------------------------------------------
        */

        $query->when(
            ($filters['type'] ?? null) === 'expense',
            fn ($query) => $query->where('amount', '<', 0)
        );

        $query->when(
            ($filters['type'] ?? null) === 'income',
            fn ($query) => $query->where('amount', '>=', 0)
        );

        /*
        |--------------------------------------------------------------------------
        | Categoria
        |--------------------------------------------------------------------------
        */

        $query->when(
            $filters['category_id'] ?? null,
            fn ($query, $categoryId) => $query->where(
                'category_id',
                $categoryId
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Período
        |--------------------------------------------------------------------------
        */

        $query->when(
            $filters['date_from'] ?? null,
            fn ($query, $dateFrom) => $query->whereDate(
                'transaction_date',
                '>=',
                $dateFrom
            )
        );

        $query->when(
            $filters['date_to'] ?? null,
            fn ($query, $dateTo) => $query->whereDate(
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
         * Remove eager loads desnecessários da consulta agregada.
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
        |--------------------------------------------------------------------------
        | Categorias disponíveis
        |--------------------------------------------------------------------------
        */

        $categories = Category::query()
            ->where(function ($query) use ($userId) {
                $query
                    ->whereNull('user_id')
                    ->orWhere('user_id', $userId);
            })
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

                'category_id' => isset($filters['category_id'])
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
        $userId = $request->user()->id;

        /*
         * Garante que a transação pertence ao usuário autenticado.
         */
        if ($transaction->user_id !== $userId) {
            abort(403);
        }

        $validated = $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id'),
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
            ->where('id', $validated['category_id'])
            ->where(function ($query) use ($userId) {
                $query
                    ->whereNull('user_id')
                    ->orWhere('user_id', $userId);
            })
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
         * Os escopos que afetam outras transações ou o futuro
         * exigem um contato associado.
         */
        if (
            $validated['scope'] !== 'current'
            && !$transaction->contact_id
        ) {
            throw ValidationException::withMessages([
                'scope' => 'Esta transação não possui um contato associado.',
            ]);
        }

        DB::transaction(function () use (
            $validated,
            $transaction,
            $transactionType
        ) {
            /*
            |--------------------------------------------------------------------------
            | Somente esta transação
            |--------------------------------------------------------------------------
            */

            if ($validated['scope'] === 'current') {
                $transaction->update([
                    'category_id' => $validated['category_id'],
                ]);

                return;
            }

            $contact = Contact::query()
                ->where('id', $transaction->contact_id)
                ->where('user_id', $transaction->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
            |--------------------------------------------------------------------------
            | Todas as transações deste contato
            |--------------------------------------------------------------------------
            |
            | Atualiza todo o histórico do mesmo contato e mesmo tipo.
            | Também atualiza a categoria padrão para futuras importações.
            |
            */

            if ($validated['scope'] === 'all_from_contact') {
                Transaction::query()
                    ->where('user_id', $transaction->user_id)
                    ->where('contact_id', $transaction->contact_id)
                    ->when(
                        $transactionType === 'expense',
                        fn ($query) => $query->where('amount', '<', 0)
                    )
                    ->when(
                        $transactionType === 'income',
                        fn ($query) => $query->where('amount', '>=', 0)
                    )
                    ->update([
                        'category_id' => $validated['category_id'],
                        'updated_at' => now(),
                    ]);

                $this->updateContactDefaultCategory(
                    contact: $contact,
                    transactionType: $transactionType,
                    categoryId: $validated['category_id']
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Esta e as próximas transações
            |--------------------------------------------------------------------------
            |
            | Atualiza a atual e a categoria padrão do contato.
            | O histórico anterior permanece inalterado.
            |
            */

            $transaction->update([
                'category_id' => $validated['category_id'],
            ]);

            $this->updateContactDefaultCategory(
                contact: $contact,
                transactionType: $transactionType,
                categoryId: $validated['category_id']
            );
        });

        return back()->with(
            'success',
            'Categoria atualizada com sucesso.'
        );
    }

    /**
     * Atualiza a categoria padrão do contato conforme
     * o tipo da transação.
     */
    private function updateContactDefaultCategory(
        Contact $contact,
        string $transactionType,
        int $categoryId
    ): void {
        if ($transactionType === 'expense') {
            $contact->update([
                'default_expense_category_id' => $categoryId,
            ]);

            return;
        }

        $contact->update([
            'default_income_category_id' => $categoryId,
        ]);
    }
}