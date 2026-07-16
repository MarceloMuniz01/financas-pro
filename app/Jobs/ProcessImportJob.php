<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\BankParsers\BankDetector;
use App\Services\BankParsers\BankParserFactory;
use App\Services\Categorizers\StringMatchCategorizer;
use App\Services\Contacts\ContactNameNormalizer;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessImportJob
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Contatos inseridos por consulta.
     *
     * Não aumente exageradamente porque cada coluna de cada
     * registro utiliza um parâmetro no PostgreSQL.
     */
    private const CONTACT_BATCH_SIZE = 1000;

    /**
     * Transações inseridas por consulta.
     */
    private const TRANSACTION_BATCH_SIZE = 2000;

    /**
     * Quantidade máxima de transações aguardando os IDs
     * dos contatos ainda não persistidos.
     */
    private const PENDING_TRANSACTION_LIMIT = 5000;

    public function __construct(
        public Import $import
    ) {
    }

    public function handle(): void
    {
        /*
         * A importação está sendo executada dentro da própria
         * requisição HTTP no MVP.
         *
         * Isso aumenta somente o limite do PHP. A infraestrutura
         * do Laravel Cloud ainda pode possuir timeout próprio.
         */
        set_time_limit(300);

        $startedAt = microtime(true);
        $stream = null;

        $this->import->update([
            'status' => 'processing',
            'processed_at' => null,
            'error_message' => null,
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Abrir arquivo
            |--------------------------------------------------------------------------
            */

            $stream = Storage::readStream(
                $this->import->filename
            );

            if (!is_resource($stream)) {
                throw new RuntimeException(
                    "Não foi possível abrir o arquivo {$this->import->filename}."
                );
            }

            /*
|--------------------------------------------------------------------------
| Identificar o banco
|--------------------------------------------------------------------------
*/

            $detectionStartedAt = microtime(true);

            $fileContent = stream_get_contents($stream);

            if ($fileContent === false) {
                throw new RuntimeException(
                    'Não foi possível ler o conteúdo do arquivo.'
                );
            }

            $bankDetector = app(
                BankDetector::class
            );

            $bank = app(BankDetector::class)
                ->detect($stream);

            $this->import->update([
                'bank' => $bank,
            ]);

            /*
             * O conteúdo foi lido até o fim.
             * O parser precisa começar novamente do início.
             */
            if (fseek($stream, 0) !== 0) {
                throw new RuntimeException(
                    'Não foi possível retornar ao início do arquivo após identificar o banco.'
                );
            }

            $this->import->update([
                'bank' => $bank,
            ]);

            $this->logStep(
                step: 'detect_bank',
                startedAt: $detectionStartedAt,
                extra: [
                    'bank' => $bank,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Criar parser e categorizador
            |--------------------------------------------------------------------------
            */

            $parser = BankParserFactory::make(
                $bank
            );

            $categorizer =
                new StringMatchCategorizer(
                    $this->import->user_id
                );

            /*
            |--------------------------------------------------------------------------
            | Carregar caches
            |--------------------------------------------------------------------------
            */

            $cacheStartedAt = microtime(true);

            $contactCache =
                $this->loadContactCache();

            $aliasCache =
                $this->loadAliasCache();

            $this->logStep(
                step: 'load_caches',
                startedAt: $cacheStartedAt,
                extra: [
                    'contacts_loaded' =>
                        count($contactCache),

                    'aliases_loaded' =>
                        count($aliasCache),
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

            $processedTransactions = 0;
            $newContactCandidates = 0;

            $now = now();

            /*
            |--------------------------------------------------------------------------
            | Processar arquivo em stream
            |--------------------------------------------------------------------------
            */

            $parseStartedAt = microtime(true);

            foreach (
                $parser->parse($stream)
                as $transaction
            ) {
                $processedTransactions++;

                $visibleName =
                    $this->normalizeVisibleName(
                        $transaction[
                            'counterparty_name'
                        ] ?? null
                    );

                $normalizedName =
                    ContactNameNormalizer::normalize(
                        $visibleName
                    );

                if ($normalizedName === '') {
                    $visibleName =
                        'Desconhecido';

                    $normalizedName =
                        ContactNameNormalizer::normalize(
                            $visibleName
                        );
                }

                /*
                 * Procura primeiro pelo nome oficial.
                 */
                $contact =
                    $contactCache[
                        $normalizedName
                    ] ?? null;

                /*
                 * Depois procura pelos apelidos criados
                 * durante mesclagens manuais.
                 */
                if ($contact === null) {
                    $contact =
                        $aliasCache[
                            $normalizedName
                        ] ?? null;
                }

                /*
                 * O contato já existe ou o nome é um alias.
                 */
                if ($contact !== null) {
                    $this->queueTransaction(
                        transaction: $transaction,
                        contact: $contact,
                        categorizer: $categorizer,
                        transactionBatch:
                        $transactionBatch,
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
                 * O contato ainda não existe.
                 */
                $wasNewPendingContact =
                    !isset(
                    $pendingContacts[
                        $normalizedName
                    ]
                );

                $this->queueContact(
                    visibleName: $visibleName,
                    normalizedName:
                    $normalizedName,
                    transaction: $transaction,
                    categorizer: $categorizer,
                    pendingContacts:
                    $pendingContacts,
                    now: $now
                );

                if ($wasNewPendingContact) {
                    $newContactCandidates++;
                }

                /*
                 * A transação aguarda o contato ser salvo
                 * e receber um ID.
                 */
                $pendingTransactions[] = [
                    'transaction' =>
                        $transaction,

                    'normalized_name' =>
                        $normalizedName,

                    'visible_name' =>
                        $visibleName,
                ];

                if (
                    count($pendingContacts)
                    >= self::CONTACT_BATCH_SIZE
                    ||
                    count($pendingTransactions)
                    >= self::PENDING_TRANSACTION_LIMIT
                ) {
                    $this
                        ->flushContactsAndPendingTransactions(
                            pendingContacts:
                            $pendingContacts,

                            pendingTransactions:
                            $pendingTransactions,

                            transactionBatch:
                            $transactionBatch,

                            contactCache:
                            $contactCache,

                            aliasCache:
                            $aliasCache,

                            categorizer:
                            $categorizer,

                            now:
                            $now
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

            $finalFlushStartedAt =
                microtime(true);

            $this
                ->flushContactsAndPendingTransactions(
                    pendingContacts:
                    $pendingContacts,

                    pendingTransactions:
                    $pendingTransactions,

                    transactionBatch:
                    $transactionBatch,

                    contactCache:
                    $contactCache,

                    aliasCache:
                    $aliasCache,

                    categorizer:
                    $categorizer,

                    now:
                    $now
                );

            $this->flushTransactions(
                $transactionBatch
            );

            $this->logStep(
                step: 'final_flush',
                startedAt:
                $finalFlushStartedAt
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

            Log::info(
                "Importação {$this->import->id} concluída.",
                [
                    'user_id' =>
                        $this->import->user_id,

                    'bank' =>
                        $bank,

                    'transactions_processed' =>
                        $processedTransactions,

                    'new_contact_candidates' =>
                        $newContactCandidates,

                    'total_seconds' => round(
                        microtime(true)
                        - $startedAt,
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
                "Erro na importação {$this->import->id}.",
                [
                    'user_id' =>
                        $this->import->user_id,

                    'bank' =>
                        $this->import->bank,

                    'message' =>
                        $exception->getMessage(),

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

            /*
             * O ImportController captura esta exceção e
             * devolve um redirect Inertia com mensagem.
             */
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Carrega todos os contatos do usuário em memória.
     *
     * A chave do array é o normalized_name.
     *
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
                    foreach (
                        $contacts
                        as $contact
                    ) {
                        $cache[
                            $contact
                                ->normalized_name
                        ] = $this
                                ->contactRowToArray(
                                    $contact
                                );
                    }
                },
                'id'
            );

        return $cache;
    }

    /**
     * Carrega aliases e resolve o contato principal.
     *
     * Quando um nome antigo aparecer em um novo extrato,
     * a transação será vinculada ao contato mantido na
     * mesclagem.
     *
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
                'contact_aliases.id
                    as alias_id',

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
            ->orderBy(
                'contact_aliases.id'
            )
            ->chunkById(
                5000,
                function ($aliases) use (&$cache): void {
                    foreach (
                        $aliases
                        as $alias
                    ) {
                        $cache[
                            $alias
                                ->alias_normalized_name
                        ] = $this
                                ->contactRowToArray(
                                    $alias
                                );
                    }
                },
                'contact_aliases.id',
                'alias_id'
            );

        return $cache;
    }

    /**
     * Prepara um contato para inserção.
     *
     * @param array<string, array<string, mixed>> $pendingContacts
     */
    private function queueContact(
        string $visibleName,
        string $normalizedName,
        array $transaction,
        StringMatchCategorizer $categorizer,
        array &$pendingContacts,
        mixed $now
    ): void {
        /*
         * Mais de uma transação no mesmo arquivo pode
         * possuir exatamente o mesmo contato.
         */
        if (
            isset(
            $pendingContacts[
                $normalizedName
            ]
        )
        ) {
            $this->enrichPendingContact(
                pendingContact:
                $pendingContacts[
                    $normalizedName
                ],

                transaction:
                $transaction
            );

            return;
        }

        $contactType =
            $transaction[
                'counterparty_contact_type'
            ]
            ?? $transaction[
                'counterparty_type'
            ]
            ?? null;

        if (!$contactType) {
            $contactType =
                $categorizer->guessContactType(
                    $visibleName
                );
        }

        $pendingContacts[
            $normalizedName
        ] = [
            'user_id' =>
                $this->import->user_id,

            'name' =>
                $visibleName,

            'normalized_name' =>
                $normalizedName,

            'document' =>
                $transaction[
                    'counterparty_document'
                ] ?? null,

            'contact_type' =>
                $contactType,

            'default_expense_category_id' =>
                $categorizer
                    ->guessCategoryId(
                        $visibleName,
                        'expense'
                    ),

            'default_income_category_id' =>
                $categorizer
                    ->guessCategoryId(
                        $visibleName,
                        'income'
                    ),

            'created_at' =>
                $now,

            'updated_at' =>
                $now,
        ];
    }

    /**
     * Enriquece um contato ainda pendente com dados
     * encontrados em outra transação do mesmo arquivo.
     */
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
            empty(
            $pendingContact[
                'document'
            ]
        )
            && !empty($document)
        ) {
            $pendingContact[
                'document'
            ] = $document;
        }

        if (
            empty(
            $pendingContact[
                'contact_type'
            ]
        )
            && !empty($contactType)
        ) {
            $pendingContact[
                'contact_type'
            ] = $contactType;
        }
    }

    /**
     * Persiste os contatos e depois prepara as transações
     * que estavam aguardando seus IDs.
     *
     * @param array<string, array<string, mixed>> $pendingContacts
     * @param array<int, array<string, mixed>> $pendingTransactions
     * @param array<int, array<string, mixed>> $transactionBatch
     * @param array<string, array<string, mixed>> $contactCache
     * @param array<string, array<string, mixed>> $aliasCache
     */
    private function flushContactsAndPendingTransactions(
        array &$pendingContacts,
        array &$pendingTransactions,
        array &$transactionBatch,
        array &$contactCache,
        array $aliasCache,
        StringMatchCategorizer $categorizer,
        mixed $now
    ): void {
        if (!empty($pendingContacts)) {
            $this->flushContacts(
                pendingContacts:
                $pendingContacts,

                contactCache:
                $contactCache
            );
        }

        if (empty($pendingTransactions)) {
            return;
        }

        foreach (
            $pendingTransactions
            as $pending
        ) {
            $normalizedName =
                $pending[
                    'normalized_name'
                ];

            $contact =
                $contactCache[
                    $normalizedName
                ]
                ?? $aliasCache[
                    $normalizedName
                ]
                ?? null;

            if ($contact === null) {
                throw new RuntimeException(
                    "Não foi possível localizar o contato \"{$pending['visible_name']}\" após sua inserção."
                );
            }

            $this->queueTransaction(
                transaction:
                $pending[
                    'transaction'
                ],

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

    /**
     * Persiste contatos em lote.
     *
     * @param array<string, array<string, mixed>> $pendingContacts
     * @param array<string, array<string, mixed>> $contactCache
     */
    private function flushContacts(
        array &$pendingContacts,
        array &$contactCache
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
                $this
                    ->insertContactsReturning(
                        $chunk
                    );

            foreach (
                $insertedContacts
                as $contact
            ) {
                $normalizedName =
                    (string) $contact
                        ->normalized_name;

                $contactCache[
                    $normalizedName
                ] = $this
                        ->contactRowToArray(
                            $contact
                        );

                $resolvedNormalizedNames[
                    $normalizedName
                ] = true;
            }
        }

        /*
         * ON CONFLICT DO NOTHING não retorna os contatos
         * que já existiam. Buscamos somente os faltantes.
         */
        $missingNormalizedNames =
            array_values(
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

            $existingContacts =
                DB::table('contacts')
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

            foreach (
                $existingContacts
                as $contact
            ) {
                $contactCache[
                    $contact
                        ->normalized_name
                ] = $this
                        ->contactRowToArray(
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
                    count(
                        $resolvedNormalizedNames
                    ),

                'conflicting_contacts' =>
                    count(
                        $missingNormalizedNames
                    ),
            ]
        );

        $pendingContacts = [];
    }

    /**
     * Executa um INSERT em lote com RETURNING.
     *
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
            'document',
            'contact_type',
            'default_expense_category_id',
            'default_income_category_id',
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

        $rowPlaceholder =
            '('
            . implode(
                ', ',
                array_fill(
                    0,
                    count($columns),
                    '?'
                )
            )
            . ')';

        $valuePlaceholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $valuePlaceholders[] =
                $rowPlaceholder;

            foreach ($columns as $column) {
                $bindings[] =
                    $row[$column]
                    ?? null;
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

    /**
     * Prepara uma transação para inserção.
     *
     * @param array<string, mixed> $transaction
     * @param array<string, mixed> $contact
     * @param array<int, array<string, mixed>> $transactionBatch
     */
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
                $categorizer
                    ->guessCategoryId(
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
                ?? $this
                    ->fallbackTransactionCode(
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
                $transaction[
                    'amount'
                ],

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

    /**
     * Insere transações em lote.
     *
     * @param array<int, array<string, mixed>> $transactionBatch
     */
    private function flushTransactions(
        array &$transactionBatch
    ): void {
        if (empty($transactionBatch)) {
            return;
        }

        $startedAt = microtime(true);

        $total = count(
            $transactionBatch
        );

        foreach (
            array_chunk(
                $transactionBatch,
                self::TRANSACTION_BATCH_SIZE
            )
            as $chunk
        ) {
            DB::table('transactions')
                ->insertOrIgnore(
                    $chunk
                );
        }

        $this->logStep(
            step: 'flush_transactions',
            startedAt: $startedAt,
            extra: [
                'batch_size' =>
                    $total,
            ]
        );

        $transactionBatch = [];
    }

    /**
     * Converte uma linha do Query Builder em array.
     *
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
                (string) $contact
                    ->normalized_name,

            'document' =>
                $contact->document
                ?? null,

            'contact_type' =>
                $contact->contact_type
                ?? null,

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

    /**
     * Gera um identificador quando o parser não fornece
     * um código próprio da transação.
     */
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

    /**
     * Registra duração e memória de uma etapa.
     */
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