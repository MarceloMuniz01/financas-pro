<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactMergeService
{
    /**
     * Mescla o contato de origem no contato de destino.
     *
     * Origem:
     * - perde suas transações;
     * - tem seus aliases transferidos;
     * - seu nome vira alias do destino;
     * - é excluída.
     *
     * Destino:
     * - permanece;
     * - recebe dados ausentes;
     * - recebe transações e aliases.
     */
    public function merge(
        int $userId,
        int $sourceContactId,
        int $targetContactId
    ): Contact {
        if ($sourceContactId === $targetContactId) {
            throw ValidationException::withMessages([
                'contacts' =>
                    'Não é possível mesclar um contato nele mesmo.',
            ]);
        }

        $startedAt = microtime(true);

        try {
            $resultContactId = DB::transaction(
                function () use ($userId, $sourceContactId, $targetContactId): int {
                    /*
                    |--------------------------------------------------------------------------
                    | 1. Carregar e bloquear apenas os dois contatos
                    |--------------------------------------------------------------------------
                    */

                    $contacts = DB::table('contacts')
                        ->where('user_id', $userId)
                        ->whereIn('id', [
                            $sourceContactId,
                            $targetContactId,
                        ])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get([
                            'id',
                            'name',
                            'normalized_name',
                            'document',
                            'contact_type',
                            'default_expense_category_id',
                            'default_income_category_id',
                            'looks_like_contact_id',
                        ])
                        ->keyBy('id');

                    $source = $contacts->get(
                        $sourceContactId
                    );

                    $target = $contacts->get(
                        $targetContactId
                    );

                    if (!$source || !$target) {
                        throw ValidationException::withMessages([
                            'contacts' =>
                                'Um dos contatos não existe ou não pertence ao usuário.',
                        ]);
                    }

                    $this->ensureContactsCanBeMerged(
                        source: $source,
                        target: $target
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 2. Preparar os aliases
                    |--------------------------------------------------------------------------
                    */

                    $sourceNormalizedName =
                        ContactNameNormalizer::normalize(
                            $source->name
                        );

                    $targetNormalizedName =
                        ContactNameNormalizer::normalize(
                            $target->name
                        );

                    $aliasRows = [];

                    /*
                     * O nome antigo da origem passa a ser
                     * um apelido do destino.
                     */
                    if (
                        $sourceNormalizedName !== ''
                        && $sourceNormalizedName
                        !== $targetNormalizedName
                    ) {
                        $aliasRows[] = [
                            'user_id' =>
                                $userId,

                            'contact_id' =>
                                $targetContactId,

                            'name' =>
                                trim($source->name),

                            'normalized_name' =>
                                $sourceNormalizedName,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ];
                    }

                    /*
                     * Carrega somente os aliases da origem.
                     */
                    $sourceAliases = DB::table(
                        'contact_aliases'
                    )
                        ->where('user_id', $userId)
                        ->where(
                            'contact_id',
                            $sourceContactId
                        )
                        ->lockForUpdate()
                        ->get([
                            'name',
                            'normalized_name',
                            'created_at',
                        ]);

                    foreach ($sourceAliases as $alias) {
                        /*
                         * O nome oficial do destino não precisa
                         * existir também como alias.
                         */
                        if (
                            $alias->normalized_name
                            === $targetNormalizedName
                        ) {
                            continue;
                        }

                        $aliasRows[] = [
                            'user_id' =>
                                $userId,

                            'contact_id' =>
                                $targetContactId,

                            'name' =>
                                trim($alias->name),

                            'normalized_name' =>
                                $alias->normalized_name,

                            'created_at' =>
                                $alias->created_at
                                ?? now(),

                            'updated_at' =>
                                now(),
                        ];
                    }

                    $aliasRows = $this->uniqueAliasRows(
                        $aliasRows
                    );

                    /*
                     * Um alias da origem ou do destino é permitido.
                     * Só existe conflito se pertencer a um terceiro contato.
                     */
                    $this->validateAliasConflicts(
                        userId: $userId,
                        sourceContactId: $sourceContactId,
                        targetContactId: $targetContactId,
                        aliasRows: $aliasRows
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 3. Atualizar o destino uma única vez
                    |--------------------------------------------------------------------------
                    */

                    DB::table('contacts')
                        ->where('user_id', $userId)
                        ->where('id', $targetContactId)
                        ->update([
                            'document' =>
                                $this->firstFilled(
                                    $target->document,
                                    $source->document
                                ),

                            'contact_type' =>
                                $this->firstFilled(
                                    $target->contact_type,
                                    $source->contact_type
                                ),

                            'default_expense_category_id' =>
                                $target
                                    ->default_expense_category_id
                                ?? $source
                                    ->default_expense_category_id,

                            'default_income_category_id' =>
                                $target
                                    ->default_income_category_id
                                ?? $source
                                    ->default_income_category_id,

                            /*
                             * A sugestão já foi resolvida
                             * pela própria mesclagem.
                             */
                            'looks_like_contact_id' =>
                                null,

                            'similarity_dismissed_at' =>
                                null,

                            'updated_at' =>
                                now(),
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 4. Mover todas as transações em uma query
                    |--------------------------------------------------------------------------
                    */

                    $movedTransactions = DB::table(
                        'transactions'
                    )
                        ->where('user_id', $userId)
                        ->where(
                            'contact_id',
                            $sourceContactId
                        )
                        ->update([
                            'contact_id' =>
                                $targetContactId,

                            'updated_at' =>
                                now(),
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 5. Mover os aliases
                    |--------------------------------------------------------------------------
                    */

                    if (!empty($aliasRows)) {
                        DB::table('contact_aliases')
                            ->insertOrIgnore(
                                $aliasRows
                            );
                    }

                    /*
                     * Os aliases já foram copiados para o destino.
                     */
                    DB::table('contact_aliases')
                        ->where('user_id', $userId)
                        ->where(
                            'contact_id',
                            $sourceContactId
                        )
                        ->delete();

                    /*
                     * Remove um eventual alias que seja igual
                     * ao nome oficial do destino.
                     */
                    DB::table('contact_aliases')
                        ->where('user_id', $userId)
                        ->where(
                            'contact_id',
                            $targetContactId
                        )
                        ->where(
                            'normalized_name',
                            $targetNormalizedName
                        )
                        ->delete();

                    /*
                    |--------------------------------------------------------------------------
                    | 6. Limpar referências à origem
                    |--------------------------------------------------------------------------
                    |
                    | Só toca nos contatos que apontavam diretamente
                    | para a origem. Não executa nova análise global.
                    |
                    */

                    $clearedSuggestions = DB::table(
                        'contacts'
                    )
                        ->where('user_id', $userId)
                        ->where(
                            'looks_like_contact_id',
                            $sourceContactId
                        )
                        ->update([
                            'looks_like_contact_id' =>
                                null,

                            'updated_at' =>
                                now(),
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 7. Excluir a origem
                    |--------------------------------------------------------------------------
                    */

                    $deleted = DB::table('contacts')
                        ->where('user_id', $userId)
                        ->where('id', $sourceContactId)
                        ->delete();

                    if ($deleted !== 1) {
                        throw ValidationException::withMessages([
                            'contacts' =>
                                'Não foi possível excluir o contato de origem.',
                        ]);
                    }

                    Log::info(
                        'Dados da mesclagem de contatos.',
                        [
                            'user_id' =>
                                $userId,

                            'source_contact_id' =>
                                $sourceContactId,

                            'target_contact_id' =>
                                $targetContactId,

                            'moved_transactions' =>
                                $movedTransactions,

                            'source_aliases' =>
                                $sourceAliases->count(),

                            'aliases_inserted_or_ignored' =>
                                count($aliasRows),

                            'cleared_similarity_references' =>
                                $clearedSuggestions,
                        ]
                    );

                    return $targetContactId;
                },
                attempts: 3
            );

            /*
             * Carrega o contato final somente após o commit.
             */
            $contact = Contact::query()
                ->where('user_id', $userId)
                ->with([
                    'aliases',
                    'defaultExpenseCategory',
                    'defaultIncomeCategory',
                ])
                ->findOrFail($resultContactId);

            Log::info(
                'Mesclagem de contatos concluída.',
                [
                    'user_id' =>
                        $userId,

                    'source_contact_id' =>
                        $sourceContactId,

                    'target_contact_id' =>
                        $targetContactId,

                    'seconds' => round(
                        microtime(true) - $startedAt,
                        3
                    ),
                ]
            );

            return $contact;
        } catch (Throwable $exception) {
            Log::error(
                'Erro ao mesclar contatos.',
                [
                    'user_id' =>
                        $userId,

                    'source_contact_id' =>
                        $sourceContactId,

                    'target_contact_id' =>
                        $targetContactId,

                    'message' =>
                        $exception->getMessage(),

                    'exception' =>
                        $exception,
                ]
            );

            throw $exception;
        }
    }

    /**
     * Verifica se os dois contatos podem ser mesclados.
     */
    private function ensureContactsCanBeMerged(
        object $source,
        object $target
    ): void {
        if (
            $this->filled($source->contact_type)
            && $this->filled($target->contact_type)
            && $source->contact_type
            !== $target->contact_type
        ) {
            throw ValidationException::withMessages([
                'contact_type' =>
                    'Não é possível mesclar uma pessoa com uma empresa.',
            ]);
        }

        if (
            $this->hasCompleteDocument(
                $source->document
            )
            && $this->hasCompleteDocument(
                $target->document
            )
            && $source->document
            !== $target->document
        ) {
            throw ValidationException::withMessages([
                'document' =>
                    'Não é possível mesclar contatos com documentos completos diferentes.',
            ]);
        }
    }

    /**
     * Verifica conflitos de aliases em uma única consulta.
     *
     * Aliases pertencentes à origem ou ao destino são válidos.
     * Apenas aliases de terceiros impedem a mesclagem.
     *
     * @param array<int, array<string, mixed>> $aliasRows
     */
    private function validateAliasConflicts(
        int $userId,
        int $sourceContactId,
        int $targetContactId,
        array $aliasRows
    ): void {
        if (empty($aliasRows)) {
            return;
        }

        $normalizedNames = array_values(
            array_unique(
                array_column(
                    $aliasRows,
                    'normalized_name'
                )
            )
        );

        $conflict = DB::table('contact_aliases')
            ->where('user_id', $userId)
            ->whereIn(
                'normalized_name',
                $normalizedNames
            )
            ->whereNotIn('contact_id', [
                $sourceContactId,
                $targetContactId,
            ])
            ->first([
                'name',
                'contact_id',
            ]);

        if (!$conflict) {
            return;
        }

        throw ValidationException::withMessages([
            'aliases' =>
                "O apelido \"{$conflict->name}\" já pertence a outro contato.",
        ]);
    }

    /**
     * Remove aliases repetidos do lote.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueAliasRows(
        array $rows
    ): array {
        $unique = [];

        foreach ($rows as $row) {
            $normalizedName =
                $row['normalized_name']
                ?? '';

            if ($normalizedName === '') {
                continue;
            }

            $unique[$normalizedName] =
                $row;
        }

        return array_values($unique);
    }

    private function hasCompleteDocument(
        ?string $document
    ): bool {
        if (!$document) {
            return false;
        }

        return preg_match(
            '/^(\d{11}|\d{14})$/',
            $document
        ) === 1;
    }

    private function firstFilled(
        mixed $preferred,
        mixed $fallback
    ): mixed {
        return $this->filled($preferred)
            ? $preferred
            : $fallback;
    }

    private function filled(
        mixed $value
    ): bool {
        return $value !== null
            && $value !== '';
    }
}