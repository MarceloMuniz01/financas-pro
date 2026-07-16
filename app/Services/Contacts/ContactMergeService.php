<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactMergeService
{
    /**
     * Compatibilidade com mesclagens de apenas dois contatos.
     */
    public function merge(
        int $userId,
        int $sourceContactId,
        int $targetContactId
    ): Contact {
        return $this->mergeMany(
            userId: $userId,
            contactIds: [
                $sourceContactId,
                $targetContactId,
            ],
            targetContactId: $targetContactId
        );
    }

    /**
     * Mescla dois ou mais contatos em um único contato.
     *
     * O contato indicado por $targetContactId permanece.
     * Todos os outros contatos selecionados são removidos.
     *
     * O contato mantido recebe:
     *
     * - todas as transações;
     * - todos os aliases;
     * - os nomes oficiais dos contatos removidos como aliases;
     * - dados ausentes, como documento, tipo e categorias.
     *
     * @param array<int> $contactIds
     */
    public function mergeMany(
        int $userId,
        array $contactIds,
        int $targetContactId
    ): Contact {
        $startedAt = microtime(true);

        $contactIds = array_values(
            array_unique(
                array_map(
                    'intval',
                    array_filter(
                        $contactIds,
                        static fn(mixed $id): bool =>
                        is_numeric($id)
                        && (int) $id > 0
                    )
                )
            )
        );

        if (count($contactIds) < 2) {
            throw ValidationException::withMessages([
                'contact_ids' =>
                    'Selecione pelo menos dois contatos para mesclar.',
            ]);
        }

        if (
            !in_array(
                $targetContactId,
                $contactIds,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'target_contact_id' =>
                    'O contato mantido deve estar entre os contatos selecionados.',
            ]);
        }

        $sourceContactIds = array_values(
            array_filter(
                $contactIds,
                static fn(int $contactId): bool =>
                $contactId !== $targetContactId
            )
        );

        try {
            $resultContactId = DB::transaction(
                function () use ($userId, $contactIds, $sourceContactIds, $targetContactId): int {
                    /*
                    |--------------------------------------------------------------------------
                    | 1. Carregar e bloquear os contatos selecionados
                    |--------------------------------------------------------------------------
                    */

                    $contacts = DB::table('contacts')
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'id',
                            $contactIds
                        )
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get([
                            'id',
                            'user_id',
                            'name',
                            'normalized_name',
                            'document',
                            'contact_type',
                            'default_expense_category_id',
                            'default_income_category_id',
                        ])
                        ->keyBy('id');

                    if (
                        $contacts->count()
                        !== count($contactIds)
                    ) {
                        throw ValidationException::withMessages([
                            'contact_ids' =>
                                'Um ou mais contatos não existem ou não pertencem ao usuário.',
                        ]);
                    }

                    $target = $contacts->get(
                        $targetContactId
                    );

                    if (!$target) {
                        throw ValidationException::withMessages([
                            'target_contact_id' =>
                                'O contato que deveria permanecer não foi encontrado.',
                        ]);
                    }

                    $sources = $contacts->only(
                        $sourceContactIds
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 2. Validar compatibilidade
                    |--------------------------------------------------------------------------
                    */

                    $this->ensureContactsCanBeMerged(
                        contacts: $contacts
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 3. Calcular os dados finais do contato mantido
                    |--------------------------------------------------------------------------
                    */

                    $finalDocument =
                        $this->resolveDocument(
                            contacts: $contacts,
                            target: $target
                        );

                    $finalContactType =
                        $this->resolveContactType(
                            contacts: $contacts,
                            target: $target
                        );

                    $finalExpenseCategoryId =
                        $this->resolveCategoryId(
                            contacts: $contacts,
                            targetValue:
                            $target
                                ->default_expense_category_id,
                            column:
                            'default_expense_category_id'
                        );

                    $finalIncomeCategoryId =
                        $this->resolveCategoryId(
                            contacts: $contacts,
                            targetValue:
                            $target
                                ->default_income_category_id,
                            column:
                            'default_income_category_id'
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | 4. Preparar aliases
                    |--------------------------------------------------------------------------
                    |
                    | Reúne:
                    |
                    | - os nomes oficiais dos contatos removidos;
                    | - todos os aliases dos contatos removidos.
                    |
                    */

                    $targetNormalizedName =
                        ContactNameNormalizer::normalize(
                            (string) $target->name
                        );

                    $now = now();

                    $aliasRows = [];

                    foreach ($sources as $source) {
                        $sourceName = trim(
                            (string) $source->name
                        );

                        $sourceNormalizedName =
                            ContactNameNormalizer::normalize(
                                $sourceName
                            );

                        if (
                            $sourceName !== ''
                            && $sourceNormalizedName !== ''
                            && $sourceNormalizedName
                            !== $targetNormalizedName
                        ) {
                            $aliasRows[] = [
                                'user_id' =>
                                    $userId,

                                'contact_id' =>
                                    $targetContactId,

                                'name' =>
                                    $sourceName,

                                'normalized_name' =>
                                    $sourceNormalizedName,

                                'created_at' =>
                                    $now,

                                'updated_at' =>
                                    $now,
                            ];
                        }
                    }

                    $sourceAliases = DB::table(
                        'contact_aliases'
                    )
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'contact_id',
                            $sourceContactIds
                        )
                        ->lockForUpdate()
                        ->get([
                            'id',
                            'contact_id',
                            'name',
                            'normalized_name',
                            'created_at',
                        ]);

                    foreach ($sourceAliases as $alias) {
                        $normalizedName = (string) 
                            $alias->normalized_name;

                        if (
                            $normalizedName === ''
                            || $normalizedName
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
                                trim(
                                    (string) $alias->name
                                ),

                            'normalized_name' =>
                                $normalizedName,

                            'created_at' =>
                                $alias->created_at
                                ?? $now,

                            'updated_at' =>
                                $now,
                        ];
                    }

                    $aliasRows = $this->uniqueAliasRows(
                        $aliasRows
                    );

                    /*
                     * Um alias pertencente a um contato que não
                     * faz parte da seleção impede a mesclagem.
                     */
                    $this->validateAliasConflicts(
                        userId: $userId,
                        selectedContactIds: $contactIds,
                        aliasRows: $aliasRows
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | 5. Atualizar o contato mantido
                    |--------------------------------------------------------------------------
                    */

                    DB::table('contacts')
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->where(
                            'id',
                            $targetContactId
                        )
                        ->update([
                            'document' =>
                                $finalDocument,

                            'contact_type' =>
                                $finalContactType,

                            'default_expense_category_id' =>
                                $finalExpenseCategoryId,

                            'default_income_category_id' =>
                                $finalIncomeCategoryId,

                            'updated_at' =>
                                $now,
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 6. Mover todas as transações em uma query
                    |--------------------------------------------------------------------------
                    */

                    $movedTransactions = DB::table(
                        'transactions'
                    )
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'contact_id',
                            $sourceContactIds
                        )
                        ->update([
                            'contact_id' =>
                                $targetContactId,

                            'updated_at' =>
                                $now,
                        ]);

                    /*
                    |--------------------------------------------------------------------------
                    | 7. Transferir aliases
                    |--------------------------------------------------------------------------
                    |
                    | Primeiro removemos os aliases das origens.
                    |
                    | Isso é importante porque pode existir uma restrição
                    | UNIQUE(user_id, normalized_name). Se tentássemos inserir
                    | primeiro, o insertOrIgnore ignoraria o alias ainda
                    | pertencente ao contato de origem.
                    |
                    */

                    $deletedSourceAliases = DB::table(
                        'contact_aliases'
                    )
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'contact_id',
                            $sourceContactIds
                        )
                        ->delete();

                    if (!empty($aliasRows)) {
                        DB::table(
                            'contact_aliases'
                        )->insertOrIgnore(
                                $aliasRows
                            );
                    }

                    /*
                     * O nome oficial do contato mantido não precisa
                     * também existir como alias.
                     */
                    DB::table('contact_aliases')
                        ->where(
                            'user_id',
                            $userId
                        )
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
                    | 8. Excluir os contatos de origem
                    |--------------------------------------------------------------------------
                    */

                    $deletedContacts = DB::table(
                        'contacts'
                    )
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->whereIn(
                            'id',
                            $sourceContactIds
                        )
                        ->delete();

                    if (
                        $deletedContacts
                        !== count($sourceContactIds)
                    ) {
                        throw ValidationException::withMessages([
                            'contact_ids' =>
                                'Não foi possível remover todos os contatos de origem.',
                        ]);
                    }

                    Log::info(
                        'Dados da mesclagem múltipla de contatos.',
                        [
                            'user_id' =>
                                $userId,

                            'target_contact_id' =>
                                $targetContactId,

                            'source_contact_ids' =>
                                $sourceContactIds,

                            'selected_contacts' =>
                                count($contactIds),

                            'removed_contacts' =>
                                $deletedContacts,

                            'moved_transactions' =>
                                $movedTransactions,

                            'source_aliases' =>
                                $sourceAliases->count(),

                            'deleted_source_aliases' =>
                                $deletedSourceAliases,

                            'aliases_prepared' =>
                                count($aliasRows),
                        ]
                    );

                    return $targetContactId;
                },
                attempts: 3
            );

            /*
            |--------------------------------------------------------------------------
            | Carregar resultado após o commit
            |--------------------------------------------------------------------------
            */

            $contact = Contact::query()
                ->where(
                    'user_id',
                    $userId
                )
                ->with([
                    'aliases',
                    'defaultExpenseCategory',
                    'defaultIncomeCategory',
                ])
                ->withCount(
                    'transactions'
                )
                ->findOrFail(
                    $resultContactId
                );

            Log::info(
                'Mesclagem múltipla de contatos concluída.',
                [
                    'user_id' =>
                        $userId,

                    'target_contact_id' =>
                        $targetContactId,

                    'source_contact_ids' =>
                        $sourceContactIds,

                    'contacts_count' =>
                        count($contactIds),

                    'seconds' => round(
                        microtime(true) - $startedAt,
                        3
                    ),

                    'memory_mb' => round(
                        memory_get_usage(true)
                        / 1024
                        / 1024,
                        2
                    ),
                ]
            );

            return $contact;
        } catch (Throwable $exception) {
            Log::error(
                'Erro na mesclagem múltipla de contatos.',
                [
                    'user_id' =>
                        $userId,

                    'target_contact_id' =>
                        $targetContactId,

                    'contact_ids' =>
                        $contactIds,

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
     * Impede mesclagem de pessoa com empresa e de documentos
     * completos diferentes.
     *
     * @param Collection<int, object> $contacts
     */
    private function ensureContactsCanBeMerged(
        Collection $contacts
    ): void {
        $types = $contacts
            ->pluck('contact_type')
            ->filter(
                static fn(mixed $type): bool =>
                $type !== null
                && $type !== ''
            )
            ->unique()
            ->values();

        if ($types->count() > 1) {
            throw ValidationException::withMessages([
                'contact_ids' =>
                    'Não é possível mesclar pessoas e empresas na mesma operação.',
            ]);
        }

        $completeDocuments = $contacts
            ->pluck('document')
            ->filter(
                fn(mixed $document): bool =>
                $this->hasCompleteDocument(
                    is_string($document)
                    ? $document
                    : null
                )
            )
            ->unique()
            ->values();

        if ($completeDocuments->count() > 1) {
            throw ValidationException::withMessages([
                'contact_ids' =>
                    'Não é possível mesclar contatos com documentos completos diferentes.',
            ]);
        }
    }

    /**
     * Mantém o documento do contato principal.
     *
     * Se ele estiver vazio, usa o primeiro documento disponível
     * entre os demais contatos.
     */
    private function resolveDocument(
        Collection $contacts,
        object $target
    ): ?string {
        if ($this->filled($target->document)) {
            return (string) $target->document;
        }

        $document = $contacts
            ->pluck('document')
            ->first(
                fn(mixed $value): bool =>
                $this->filled($value)
            );

        return $document !== null
            ? (string) $document
            : null;
    }

    /**
     * Mantém o tipo do contato principal.
     *
     * Se ele estiver vazio, usa o primeiro tipo disponível.
     */
    private function resolveContactType(
        Collection $contacts,
        object $target
    ): ?string {
        if ($this->filled($target->contact_type)) {
            return (string) $target->contact_type;
        }

        $type = $contacts
            ->pluck('contact_type')
            ->first(
                fn(mixed $value): bool =>
                $this->filled($value)
            );

        return $type !== null
            ? (string) $type
            : null;
    }

    /**
     * Mantém a categoria do contato principal.
     *
     * Se estiver vazia, utiliza a primeira categoria encontrada.
     */
    private function resolveCategoryId(
        Collection $contacts,
        mixed $targetValue,
        string $column
    ): ?int {
        if ($targetValue !== null) {
            return (int) $targetValue;
        }

        $categoryId = $contacts
            ->pluck($column)
            ->first(
                static fn(mixed $value): bool =>
                $value !== null
            );

        return $categoryId !== null
            ? (int) $categoryId
            : null;
    }

    /**
     * Verifica se os aliases já pertencem a contatos externos
     * à seleção atual.
     *
     * @param array<int> $selectedContactIds
     * @param array<int, array<string, mixed>> $aliasRows
     */
    private function validateAliasConflicts(
        int $userId,
        array $selectedContactIds,
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

        $conflict = DB::table(
            'contact_aliases'
        )
            ->where(
                'user_id',
                $userId
            )
            ->whereIn(
                'normalized_name',
                $normalizedNames
            )
            ->whereNotIn(
                'contact_id',
                $selectedContactIds
            )
            ->first([
                'name',
                'normalized_name',
                'contact_id',
            ]);

        if (!$conflict) {
            return;
        }

        throw ValidationException::withMessages([
            'contact_ids' =>
                "O apelido \"{$conflict->name}\" já pertence a outro contato que não foi selecionado.",
        ]);
    }

    /**
     * Remove aliases duplicados do lote pelo nome normalizado.
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
                trim(
                    (string) (
                        $row['normalized_name']
                        ?? ''
                    )
                );

            if ($normalizedName === '') {
                continue;
            }

            $row['normalized_name'] =
                $normalizedName;

            $unique[$normalizedName] =
                $row;
        }

        return array_values(
            $unique
        );
    }

    /**
     * Considera completos CPF com 11 dígitos
     * ou CNPJ com 14 dígitos.
     */
    private function hasCompleteDocument(
        ?string $document
    ): bool {
        if (!$document) {
            return false;
        }

        return preg_match(
            '/^(?:\d{11}|\d{14})$/',
            $document
        ) === 1;
    }

    private function filled(
        mixed $value
    ): bool {
        return $value !== null
            && trim((string) $value) !== '';
    }
}