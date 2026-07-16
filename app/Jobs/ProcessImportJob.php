<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\BankParsers\BankParserFactory;
use App\Services\Categorizers\StringMatchCategorizer;
use App\Services\Contacts\ContactNameNormalizer;
use App\Services\Contacts\ContactSimilaritySignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CONTACT_BATCH_SIZE = 3000;

    private const TRANSACTION_BATCH_SIZE = 5000;

    private const PENDING_TRANSACTION_LIMIT = 6000;

    private const SIMILARITY_JOB_BATCH_SIZE = 6000;

    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(
        public Import $import
    ) {
    }

    public function handle(): void
    {
        $jobStartedAt = microtime(true);
        $stream = null;

        $this->import->update([
            'status' => 'processing',
            'processed_at' => null,
            'error_message' => null,
        ]);

        try {
            $stream = Storage::readStream(
                $this->import->filename
            );

            if (!is_resource($stream)) {
                throw new RuntimeException(
                    "Não foi possível abrir o arquivo {$this->import->filename}."
                );
            }

            $parser = BankParserFactory::make(
                $this->import->bank
            );

            $categorizer = new StringMatchCategorizer(
                $this->import->user_id
            );

            /*
            |--------------------------------------------------------------------------
            | Caches
            |--------------------------------------------------------------------------
            */

            $cacheStartedAt = microtime(true);

            $contactCache = $this->loadContactCache();
            $aliasCache = $this->loadAliasCache();

            $this->logStep(
                step: 'load_caches',
                startedAt: $cacheStartedAt,
                extra: [
                    'contacts_loaded' => count($contactCache),
                    'aliases_loaded' => count($aliasCache),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Estruturas temporárias
            |--------------------------------------------------------------------------
            */

            $pendingContacts = [];

            $pendingTransactions = [];

            $transactionBatch = [];

            /*
             * Somente IDs efetivamente criados nesta importação.
             */
            $newContactIds = [];

            $processedTransactions = 0;

            $newContactCandidates = 0;

            $now = now();

            /*
            |--------------------------------------------------------------------------
            | Processamento em stream
            |--------------------------------------------------------------------------
            */

            $parseStartedAt = microtime(true);

            foreach ($parser->parse($stream) as $transaction) {
                $processedTransactions++;

                $visibleName = $this->normalizeVisibleName(
                    $transaction['counterparty_name'] ?? null
                );

                $normalizedName = ContactNameNormalizer::normalize(
                    $visibleName
                );

                if ($normalizedName === '') {
                    $visibleName = 'Desconhecido';

                    $normalizedName = ContactNameNormalizer::normalize(
                        $visibleName
                    );
                }

                /*
                 * Busca primeiro pelo nome oficial.
                 */
                $contact = $contactCache[$normalizedName] ?? null;

                /*
                 * Depois busca pelos aliases.
                 */
                if ($contact === null) {
                    $contact = $aliasCache[$normalizedName] ?? null;
                }

                /*
                 * Contato já existente.
                 */
                if ($contact !== null) {
                    $this->queueTransaction(
                        transaction: $transaction,
                        contact: $contact,
                        categorizer: $categorizer,
                        transactionBatch: $transactionBatch,
                        now: $now
                    );

                    if (
                        count($transactionBatch)
                        >= self::TRANSACTION_BATCH_SIZE
                    ) {
                        $this->flushTransactions(
                            $transactionBatch
                        );
                    }

                    continue;
                }

                /*
                 * Novo contato pendente.
                 */
                $wasNewPendingContact = !isset(
                    $pendingContacts[$normalizedName]
                );

                $this->queueContact(
                    visibleName: $visibleName,
                    normalizedName: $normalizedName,
                    transaction: $transaction,
                    categorizer: $categorizer,
                    pendingContacts: $pendingContacts,
                    now: $now
                );

                if ($wasNewPendingContact) {
                    $newContactCandidates++;
                }

                /*
                 * Aguarda o ID do contato.
                 */
                $pendingTransactions[] = [
                    'transaction' => $transaction,
                    'normalized_name' => $normalizedName,
                    'visible_name' => $visibleName,
                ];

                if (
                    count($pendingContacts)
                    >= self::CONTACT_BATCH_SIZE
                    ||
                    count($pendingTransactions)
                    >= self::PENDING_TRANSACTION_LIMIT
                ) {
                    $this->flushContactsAndPendingTransactions(
                        pendingContacts: $pendingContacts,
                        pendingTransactions: $pendingTransactions,
                        transactionBatch: $transactionBatch,
                        contactCache: $contactCache,
                        aliasCache: $aliasCache,
                        newContactIds: $newContactIds,
                        categorizer: $categorizer,
                        now: $now
                    );
                }
            }

            $this->logStep(
                step: 'parse_stream',
                startedAt: $parseStartedAt,
                extra: [
                    'transactions_read' =>
                        $processedTransactions,

                    'new_contact_candidates' =>
                        $newContactCandidates,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Flush final
            |--------------------------------------------------------------------------
            */

            $finalFlushStartedAt = microtime(true);

            $this->flushContactsAndPendingTransactions(
                pendingContacts: $pendingContacts,
                pendingTransactions: $pendingTransactions,
                transactionBatch: $transactionBatch,
                contactCache: $contactCache,
                aliasCache: $aliasCache,
                newContactIds: $newContactIds,
                categorizer: $categorizer,
                now: $now
            );

            $this->flushTransactions(
                $transactionBatch
            );

            $this->logStep(
                step: 'final_flush',
                startedAt: $finalFlushStartedAt
            );

            if ($processedTransactions === 0) {
                $this->import->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'error_message' =>
                        'Nenhuma transação foi encontrada no arquivo.',
                ]);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Finalizar importação
            |--------------------------------------------------------------------------
            */

            $this->import->update([
                'status' => 'done',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Detecção de similaridade
            |--------------------------------------------------------------------------
            */

            $newContactIds = array_values(
                array_unique(
                    array_map(
                        'intval',
                        $newContactIds
                    )
                )
            );

            foreach (
                array_chunk(
                    $newContactIds,
                    self::SIMILARITY_JOB_BATCH_SIZE
                )
                as $contactIds
            ) {
                DetectContactSimilaritiesJob::dispatch(
                    userId: $this->import->user_id,
                    contactIds: $contactIds
                );
            }

            Log::info(
                "Importação {$this->import->id} concluída.",
                [
                    'user_id' =>
                        $this->import->user_id,

                    'bank' =>
                        $this->import->bank,

                    'transactions_processed' =>
                        $processedTransactions,

                    'contact_candidates' =>
                        $newContactCandidates,

                    'new_contacts_created' =>
                        count($newContactIds),

                    'similarity_jobs_dispatched' =>
                        (int) ceil(
                            count($newContactIds)
                            / self::SIMILARITY_JOB_BATCH_SIZE
                        ),

                    'total_seconds' => round(
                        microtime(true) - $jobStartedAt,
                        3
                    ),

                    'peak_memory_mb' => round(
                        memory_get_peak_usage(true)
                        / 1024
                        / 1024,
                        2
                    ),
                ]
            );
        } catch (Throwable $exception) {
            Log::error(
                "Erro na importação {$this->import->id}: {$exception->getMessage()}",
                [
                    'user_id' =>
                        $this->import->user_id,

                    'bank' =>
                        $this->import->bank,

                    'exception' =>
                        $exception,
                ]
            );

            $this->import->update([
                'status' => 'failed',
                'processed_at' => now(),

                'error_message' => mb_substr(
                    $exception->getMessage(),
                    0,
                    2000
                ),
            ]);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadContactCache(): array
    {
        $cache = [];

        DB::table('contacts')
            ->where(
                'user_id',
                $this->import->user_id
            )
            ->select([
                'id',
                'name',
                'normalized_name',
                'document',
                'contact_type',
                'default_expense_category_id',
                'default_income_category_id',
            ])
            ->orderBy('id')
            ->chunkById(
                5000,
                function ($contacts) use (&$cache): void {
                    foreach ($contacts as $contact) {
                        $cache[
                            $contact->normalized_name
                        ] = $this->contactRowToArray(
                                    $contact
                                );
                    }
                },
                'id'
            );

        return $cache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadAliasCache(): array
    {
        $cache = [];

        DB::table('contact_aliases')
            ->join(
                'contacts',
                'contacts.id',
                '=',
                'contact_aliases.contact_id'
            )
            ->where(
                'contact_aliases.user_id',
                $this->import->user_id
            )
            ->where(
                'contacts.user_id',
                $this->import->user_id
            )
            ->select([
                'contact_aliases.id as alias_id',

                'contact_aliases.normalized_name
                    as alias_normalized_name',

                'contacts.id',
                'contacts.name',
                'contacts.normalized_name',
                'contacts.document',
                'contacts.contact_type',
                'contacts.default_expense_category_id',
                'contacts.default_income_category_id',
            ])
            ->orderBy('contact_aliases.id')
            ->chunkById(
                5000,
                function ($aliases) use (&$cache): void {
                    foreach ($aliases as $alias) {
                        $cache[
                            $alias->alias_normalized_name
                        ] = $this->contactRowToArray(
                                    $alias
                                );
                    }
                },
                'contact_aliases.id',
                'alias_id'
            );

        return $cache;
    }

    private function queueContact(
        string $visibleName,
        string $normalizedName,
        array $transaction,
        StringMatchCategorizer $categorizer,
        array &$pendingContacts,
        mixed $now
    ): void {
        if (isset($pendingContacts[$normalizedName])) {
            $this->enrichPendingContact(
                pendingContact:
                $pendingContacts[$normalizedName],

                transaction:
                $transaction
            );

            return;
        }

        $contactType =
            $transaction['counterparty_contact_type']
            ?? $transaction['counterparty_type']
            ?? null;

        if (!$contactType) {
            $contactType =
                $categorizer->guessContactType(
                    $visibleName
                );
        }

        $similarityKeys =
            ContactSimilaritySignature::make(
                $visibleName
            );

        $pendingContacts[$normalizedName] = [
            'user_id' =>
                $this->import->user_id,

            'name' =>
                $visibleName,

            'normalized_name' =>
                $normalizedName,

            /*
             * Mantido por compatibilidade com o banco atual.
             * O novo detector usará signature e prefix.
             */
            'similarity_key' =>
                $this->makeLegacySimilarityKey(
                    $normalizedName
                ),

            'similarity_signature' =>
                $similarityKeys[
                    'similarity_signature'
                ],

            'similarity_prefix' =>
                $similarityKeys[
                    'similarity_prefix'
                ],

            'document' =>
                $transaction[
                    'counterparty_document'
                ] ?? null,

            'contact_type' =>
                $contactType,

            'default_expense_category_id' =>
                $categorizer->guessCategoryId(
                    $visibleName,
                    'expense'
                ),

            'default_income_category_id' =>
                $categorizer->guessCategoryId(
                    $visibleName,
                    'income'
                ),

            'looks_like_contact_id' =>
                null,

            'similarity_dismissed_at' =>
                null,

            'created_at' =>
                $now,

            'updated_at' =>
                $now,
        ];
    }

    private function enrichPendingContact(
        array &$pendingContact,
        array $transaction
    ): void {
        $document =
            $transaction[
                'counterparty_document'
            ] ?? null;

        $contactType =
            $transaction[
                'counterparty_contact_type'
            ]
            ?? $transaction[
                'counterparty_type'
            ]
            ?? null;

        if (
            empty($pendingContact['document'])
            && !empty($document)
        ) {
            $pendingContact['document'] =
                $document;
        }

        if (
            empty($pendingContact['contact_type'])
            && !empty($contactType)
        ) {
            $pendingContact['contact_type'] =
                $contactType;
        }
    }

    private function flushContactsAndPendingTransactions(
        array &$pendingContacts,
        array &$pendingTransactions,
        array &$transactionBatch,
        array &$contactCache,
        array $aliasCache,
        array &$newContactIds,
        StringMatchCategorizer $categorizer,
        mixed $now
    ): void {
        if (!empty($pendingContacts)) {
            $this->flushContacts(
                pendingContacts: $pendingContacts,
                contactCache: $contactCache,
                newContactIds: $newContactIds
            );
        }

        if (empty($pendingTransactions)) {
            return;
        }

        foreach ($pendingTransactions as $pending) {
            $normalizedName =
                $pending['normalized_name'];

            $contact =
                $contactCache[$normalizedName]
                ?? $aliasCache[$normalizedName]
                ?? null;

            if ($contact === null) {
                throw new RuntimeException(
                    "Não foi possível localizar o contato \"{$pending['visible_name']}\" após sua inserção."
                );
            }

            $this->queueTransaction(
                transaction:
                $pending['transaction'],

                contact:
                $contact,

                categorizer:
                $categorizer,

                transactionBatch:
                $transactionBatch,

                now:
                $now
            );

            if (
                count($transactionBatch)
                >= self::TRANSACTION_BATCH_SIZE
            ) {
                $this->flushTransactions(
                    $transactionBatch
                );
            }
        }

        $pendingTransactions = [];
    }

    private function flushContacts(
        array &$pendingContacts,
        array &$contactCache,
        array &$newContactIds
    ): void {
        if (empty($pendingContacts)) {
            return;
        }

        $startedAt = microtime(true);

        $rows = array_values(
            $pendingContacts
        );

        $normalizedNames = array_keys(
            $pendingContacts
        );

        $resolvedNormalizedNames = [];

        foreach (
            array_chunk(
                $rows,
                self::CONTACT_BATCH_SIZE
            )
            as $chunk
        ) {
            $insertedContacts =
                $this->insertContactsReturning(
                    $chunk
                );

            foreach ($insertedContacts as $contact) {
                $normalizedName =
                    (string) $contact->normalized_name;

                $contactCache[$normalizedName] =
                    $this->contactRowToArray(
                        $contact
                    );

                $resolvedNormalizedNames[
                    $normalizedName
                ] = true;

                $newContactIds[] =
                    (int) $contact->id;
            }
        }

        /*
         * Busca somente conflitos ou registros criados
         * concorrentemente.
         */
        $missingNormalizedNames = array_values(
            array_filter(
                $normalizedNames,
                static fn(
                string $normalizedName
            ): bool =>
                !isset(
                $resolvedNormalizedNames[
                    $normalizedName
                ]
            )
            )
        );

        foreach (
            array_chunk(
                $missingNormalizedNames,
                self::CONTACT_BATCH_SIZE
            )
            as $missingChunk
        ) {
            if (empty($missingChunk)) {
                continue;
            }

            $existingContacts = DB::table('contacts')
                ->where(
                    'user_id',
                    $this->import->user_id
                )
                ->whereIn(
                    'normalized_name',
                    $missingChunk
                )
                ->get([
                    'id',
                    'name',
                    'normalized_name',
                    'document',
                    'contact_type',
                    'default_expense_category_id',
                    'default_income_category_id',
                ]);

            foreach ($existingContacts as $contact) {
                $contactCache[
                    $contact->normalized_name
                ] = $this->contactRowToArray(
                            $contact
                        );
            }
        }

        $this->logStep(
            step: 'flush_contacts',
            startedAt: $startedAt,
            extra: [
                'batch_size' =>
                    count($normalizedNames),

                'inserted_contacts' =>
                    count($resolvedNormalizedNames),

                'conflicting_contacts' =>
                    count($missingNormalizedNames),
            ]
        );

        $pendingContacts = [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, object>
     */
    private function insertContactsReturning(
        array $rows
    ): array {
        if (empty($rows)) {
            return [];
        }

        $columns = [
            'user_id',
            'name',
            'normalized_name',
            'similarity_key',
            'similarity_signature',
            'similarity_prefix',
            'document',
            'contact_type',
            'default_expense_category_id',
            'default_income_category_id',
            'looks_like_contact_id',
            'similarity_dismissed_at',
            'created_at',
            'updated_at',
        ];

        $quotedColumns = implode(
            ', ',
            array_map(
                static fn(
                string $column
            ): string =>
                "\"{$column}\"",
                $columns
            )
        );

        $rowPlaceholder = '(' . implode(
            ', ',
            array_fill(
                0,
                count($columns),
                '?'
            )
        ) . ')';

        $valuePlaceholders = [];

        $bindings = [];

        foreach ($rows as $row) {
            $valuePlaceholders[] =
                $rowPlaceholder;

            foreach ($columns as $column) {
                $bindings[] =
                    $row[$column] ?? null;
            }
        }

        $valuesSql = implode(
            ', ',
            $valuePlaceholders
        );

        $sql = <<<SQL
            INSERT INTO contacts (
                {$quotedColumns}
            )
            VALUES
                {$valuesSql}

            ON CONFLICT (
                user_id,
                normalized_name
            )
            DO NOTHING

            RETURNING
                id,
                name,
                normalized_name,
                document,
                contact_type,
                default_expense_category_id,
                default_income_category_id
        SQL;

        return DB::select(
            $sql,
            $bindings
        );
    }

    private function queueTransaction(
        array $transaction,
        array $contact,
        StringMatchCategorizer $categorizer,
        array &$transactionBatch,
        mixed $now
    ): void {
        $transactionType =
            $transaction['type'];

        $categoryId =
            $transactionType === 'expense'
            ? $contact[
                'default_expense_category_id'
            ]
            : $contact[
                'default_income_category_id'
            ];

        if (!$categoryId) {
            $categoryId =
                $categorizer->guessCategoryId(
                    $contact['name'],
                    $transactionType
                );
        }

        $transactionBatch[] = [
            'user_id' =>
                $this->import->user_id,

            'import_id' =>
                $this->import->id,

            'contact_id' =>
                $contact['id'],

            'category_id' =>
                $categoryId,

            'transaction_code' =>
                $transaction[
                    'transaction_code'
                ]
                ?? $this->fallbackTransactionCode(
                    $transaction
                ),

            'transaction_date' =>
                $transaction[
                    'transaction_date'
                ],

            'description' =>
                $transaction[
                    'description'
                ],

            'amount' =>
                $transaction['amount'],

            'source_type' =>
                $transaction[
                    'source_type'
                ]
                ?? 'manual_import',

            'transaction_method' =>
                $transaction[
                    'transaction_method'
                ]
                ?? 'other',

            'created_at' =>
                $now,

            'updated_at' =>
                $now,
        ];
    }

    private function flushTransactions(
        array &$transactionBatch
    ): void {
        if (empty($transactionBatch)) {
            return;
        }

        $startedAt = microtime(true);

        $total = count($transactionBatch);

        foreach (
            array_chunk(
                $transactionBatch,
                self::TRANSACTION_BATCH_SIZE
            )
            as $chunk
        ) {
            DB::table('transactions')
                ->insertOrIgnore($chunk);
        }

        $this->logStep(
            step: 'flush_transactions',
            startedAt: $startedAt,
            extra: [
                'batch_size' => $total,
            ]
        );

        $transactionBatch = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRowToArray(
        object $contact
    ): array {
        return [
            'id' =>
                (int) $contact->id,

            'name' =>
                (string) $contact->name,

            'normalized_name' =>
                (string) $contact->normalized_name,

            'document' =>
                $contact->document ?? null,

            'contact_type' =>
                $contact->contact_type ?? null,

            'default_expense_category_id' =>
                $contact
                    ->default_expense_category_id
                !== null
                ? (int) $contact
                    ->default_expense_category_id
                : null,

            'default_income_category_id' =>
                $contact
                    ->default_income_category_id
                !== null
                ? (int) $contact
                    ->default_income_category_id
                : null,
        ];
    }

    private function makeLegacySimilarityKey(
        string $normalizedName
    ): string {
        return mb_substr(
            $normalizedName,
            0,
            12,
            'UTF-8'
        );
    }

    private function normalizeVisibleName(
        ?string $name
    ): string {
        $name = trim(
            (string) $name
        );

        return $name !== ''
            ? $name
            : 'Desconhecido';
    }

    private function fallbackTransactionCode(
        array $transaction
    ): string {
        return hash(
            'sha256',
            implode('|', [
                $this->import->user_id,

                $transaction[
                    'transaction_date'
                ] ?? '',

                $transaction[
                    'description'
                ] ?? '',

                $transaction[
                    'amount'
                ] ?? '',

                $transaction[
                    'source_type'
                ] ?? 'manual_import',
            ])
        );
    }

    private function logStep(
        string $step,
        float $startedAt,
        array $extra = []
    ): void {
        Log::info(
            "Importação {$this->import->id}: {$step}.",
            array_merge(
                [
                    'seconds' => round(
                        microtime(true)
                        - $startedAt,
                        3
                    ),

                    'memory_mb' => round(
                        memory_get_usage(true)
                        / 1024
                        / 1024,
                        2
                    ),
                ],
                $extra
            )
        );
    }
}