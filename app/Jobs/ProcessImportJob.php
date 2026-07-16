<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\BankParsers\BankParserFactory;
use App\Services\Categorizers\StringMatchCategorizer;
use App\Services\Contacts\ContactNameNormalizer;
use App\Services\Contacts\ContactSimilarityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CONTACT_BATCH_SIZE = 100;

    private const TRANSACTION_BATCH_SIZE = 500;

    public function __construct(
        public Import $import
    ) {
    }

    public function handle(): void
    {
        $this->import->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        $stream = null;

        try {
            $stream = Storage::readStream(
                $this->import->filename
            );

            if (!is_resource($stream)) {
                throw new \RuntimeException(
                    "Não foi possível ler o arquivo {$this->import->filename}."
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
            | Cache dos contatos
            |--------------------------------------------------------------------------
            |
            | Chave:
            | nome normalizado
            |
            | Valor:
            | model Contact
            |
            */

            $contactCache = $this->loadContactCache();

            /*
            |--------------------------------------------------------------------------
            | Cache dos aliases
            |--------------------------------------------------------------------------
            |
            | Chave:
            | normalized_name
            |
            | Valor:
            | model Contact principal
            |
            */

            $aliasCache = $this->loadAliasCache();

            $pendingContacts = [];

            $pendingTransactions = [];

            $transactionBatch = [];

            $processedCount = 0;

            $now = now();

            foreach (
                $parser->parse($stream)
                as $transaction
            ) {
                $processedCount++;

                $rawCounterpartyName =
                    $this->normalizeVisibleName(
                        $transaction[
                            'counterparty_name'
                        ] ?? null
                    );

                $normalizedCounterpartyName =
                    ContactNameNormalizer::normalize(
                        $rawCounterpartyName
                    );

                /*
                |--------------------------------------------------------------------------
                | 1. Procurar contato pelo nome oficial
                |--------------------------------------------------------------------------
                */

                $contact = $contactCache->get(
                    $normalizedCounterpartyName
                );

                /*
                |--------------------------------------------------------------------------
                | 2. Procurar contato por alias
                |--------------------------------------------------------------------------
                */

                if (!$contact) {
                    $contact = $aliasCache->get(
                        $normalizedCounterpartyName
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | 3. Criar contato quando não existe nome nem alias
                |--------------------------------------------------------------------------
                */

                if (!$contact) {
                    $this->queueContact(
                        visibleName:
                        $rawCounterpartyName,

                        normalizedName:
                        $normalizedCounterpartyName,

                        transaction:
                        $transaction,

                        categorizer:
                        $categorizer,

                        pendingContacts:
                        $pendingContacts,

                        now:
                        $now
                    );

                    $pendingTransactions[] = [
                        'transaction' =>
                            $transaction,

                        'raw_counterparty_name' =>
                            $rawCounterpartyName,

                        'normalized_counterparty_name' =>
                            $normalizedCounterpartyName,
                    ];

                    if (
                        count($pendingContacts)
                        >= self::CONTACT_BATCH_SIZE
                    ) {
                        $this->flushContacts(
                            pendingContacts:
                            $pendingContacts,

                            contactCache:
                            $contactCache
                        );

                        $this->flushPendingTransactions(
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

                    continue;
                }

                /*
                 * Quando o contato veio de alias, usamos o nome oficial
                 * para a categoria e para as regras futuras.
                 */
                $this->queueTransaction(
                    transaction:
                    $transaction,

                    rawCounterpartyName:
                    $rawCounterpartyName,

                    contact:
                    $contact,

                    transactionBatch:
                    $transactionBatch,

                    categorizer:
                    $categorizer,

                    now:
                    $now
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Flush final de contatos pendentes
            |--------------------------------------------------------------------------
            */

            $this->flushContacts(
                pendingContacts:
                $pendingContacts,

                contactCache:
                $contactCache
            );

            /*
            |--------------------------------------------------------------------------
            | Flush final de transações aguardando contatos
            |--------------------------------------------------------------------------
            */

            $this->flushPendingTransactions(
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

            /*
            |--------------------------------------------------------------------------
            | Flush final de transações
            |--------------------------------------------------------------------------
            */

            $this->flushTransactions(
                $transactionBatch
            );

            if ($processedCount === 0) {
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
            | Reavaliar todos os contatos do usuário
            |--------------------------------------------------------------------------
            |
            | Isso permite detectar:
            |
            | - contato novo semelhante a antigo;
            | - contato antigo semelhante a novo;
            | - contato que ficou mais completo após edição;
            | - sugestões que deixaram de ser válidas.
            |
            */

            (new ContactSimilarityService())
                ->detectForUser(
                    $this->import->user_id
                );

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
                        $this->import->bank,

                    'transactions_processed' =>
                        $processedCount,
                ]
            );
        } catch (\Throwable $exception) {
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

                'error_message' =>
                    $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Carrega os contatos do usuário usando o nome normalizado
     * como chave.
     *
     * @return Collection<string, Contact>
     */
    private function loadContactCache(): Collection
    {
        return Contact::query()
            ->where(
                'user_id',
                $this->import->user_id
            )
            ->get()
            ->keyBy(
                fn(Contact $contact): string =>
                ContactNameNormalizer::normalize(
                    $contact->name
                )
            );
    }

    /**
     * Carrega os aliases apontando diretamente para
     * seus contatos principais.
     *
     * @return Collection<string, Contact>
     */
    private function loadAliasCache(): Collection
    {
        return ContactAlias::query()
            ->where(
                'user_id',
                $this->import->user_id
            )
            ->with([
                'contact' => function ($query) {
                    $query->where(
                        'user_id',
                        $this->import->user_id
                    );
                },
            ])
            ->get()
            ->filter(
                fn(ContactAlias $alias): bool =>
                $alias->contact !== null
            )
            ->mapWithKeys(
                fn(ContactAlias $alias): array => [
                    $alias->normalized_name =>
                        $alias->contact,
                ]
            );
    }

    /**
     * Prepara um novo contato para inserção em lote.
     */
    private function queueContact(
        string $visibleName,
        string $normalizedName,
        array $transaction,
        StringMatchCategorizer $categorizer,
        array &$pendingContacts,
        $now
    ): void {
        if ($normalizedName === '') {
            $normalizedName =
                ContactNameNormalizer::normalize(
                    'Desconhecido'
                );

            $visibleName = 'Desconhecido';
        }

        if (
            isset(
            $pendingContacts[
                $normalizedName
            ]
        )
        ) {
            /*
             * Se o primeiro lançamento não possuía documento ou tipo,
             * mas outro lançamento do mesmo arquivo possui, enriquecemos
             * o contato ainda pendente.
             */
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

    /**
     * Enriquece um contato pendente quando outra transação
     * trouxer mais dados.
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
            !$pendingContact['document']
            && $document
        ) {
            $pendingContact['document'] =
                $document;
        }

        if (
            !$pendingContact['contact_type']
            && $contactType
        ) {
            $pendingContact['contact_type'] =
                $contactType;
        }
    }

    /**
     * Insere os contatos pendentes e atualiza o cache.
     */
    private function flushContacts(
        array &$pendingContacts,
        Collection &$contactCache
    ): void {
        if (empty($pendingContacts)) {
            return;
        }

        $pendingNames = array_keys(
            $pendingContacts
        );

        foreach (
            array_chunk(
                array_values(
                    $pendingContacts
                ),
                self::CONTACT_BATCH_SIZE
            )
            as $chunk
        ) {
            Contact::insertOrIgnore(
                $chunk
            );
        }

        /*
         * Busca os registros efetivamente persistidos.
         *
         * Isso contempla contatos inseridos agora ou registros
         * que já existiam por concorrência.
         */
        $createdContacts = Contact::query()
            ->where(
                'user_id',
                $this->import->user_id
            )
            ->get()
            ->filter(
                fn(Contact $contact): bool =>
                in_array(
                    ContactNameNormalizer::normalize(
                        $contact->name
                    ),
                    $pendingNames,
                    true
                )
            );

        foreach (
            $createdContacts
            as $contact
        ) {
            $contactCache->put(
                ContactNameNormalizer::normalize(
                    $contact->name
                ),
                $contact
            );
        }

        $pendingContacts = [];
    }

    /**
     * Converte as transações pendentes em registros
     * após a criação dos contatos.
     */
    private function flushPendingTransactions(
        array &$pendingTransactions,
        array &$transactionBatch,
        Collection $contactCache,
        Collection $aliasCache,
        StringMatchCategorizer $categorizer,
        $now
    ): void {
        if (empty($pendingTransactions)) {
            return;
        }

        foreach (
            $pendingTransactions
            as $pending
        ) {
            $normalizedName =
                $pending[
                    'normalized_counterparty_name'
                ];

            $contact =
                $contactCache->get(
                    $normalizedName
                )
                ?? $aliasCache->get(
                    $normalizedName
                );

            if (!$contact) {
                throw new \RuntimeException(
                    "Não foi possível localizar o contato \"{$pending['raw_counterparty_name']}\" após a inserção."
                );
            }

            $this->queueTransaction(
                transaction:
                $pending['transaction'],

                rawCounterpartyName:
                $pending[
                    'raw_counterparty_name'
                ],

                contact:
                $contact,

                transactionBatch:
                $transactionBatch,

                categorizer:
                $categorizer,

                now:
                $now
            );
        }

        $pendingTransactions = [];
    }

    /**
     * Prepara uma transação para inserção em lote.
     */
    private function queueTransaction(
        array $transaction,
        string $rawCounterpartyName,
        Contact $contact,
        array &$transactionBatch,
        StringMatchCategorizer $categorizer,
        $now
    ): void {
        $transactionType =
            $transaction['type'];

        /*
         * Categoria padrão do contato tem prioridade.
         */
        $categoryId =
            $transactionType === 'expense'
            ? $contact
                ->default_expense_category_id
            : $contact
                ->default_income_category_id;

        /*
         * Quando não houver padrão, aplica o categorizador.
         *
         * Usa o nome oficial do contato, não o alias.
         */
        if (!$categoryId) {
            $categoryId =
                $categorizer->guessCategoryId(
                    $contact->name,
                    $transactionType
                );
        }

        $transactionBatch[] = [
            'user_id' =>
                $this->import->user_id,

            'import_id' =>
                $this->import->id,

            'contact_id' =>
                $contact->id,

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

        if (
            count($transactionBatch)
            >= self::TRANSACTION_BATCH_SIZE
        ) {
            $this->flushTransactions(
                $transactionBatch
            );
        }
    }

    /**
     * Insere as transações em lote.
     */
    private function flushTransactions(
        array &$transactionBatch
    ): void {
        if (empty($transactionBatch)) {
            return;
        }

        foreach (
            array_chunk(
                $transactionBatch,
                self::TRANSACTION_BATCH_SIZE
            )
            as $chunk
        ) {
            Transaction::insertOrIgnore(
                $chunk
            );
        }

        $transactionBatch = [];
    }

    /**
     * Retorna um nome visível seguro.
     */
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
     * Cria um código de contingência quando o parser
     * não fornece identificador.
     */
    private function fallbackTransactionCode(
        array $transaction
    ): string {
        return hash(
            'sha256',
            implode(
                '|',
                [
                    $this->import->id,

                    $transaction[
                        'transaction_date'
                    ] ?? '',

                    $transaction[
                        'description'
                    ] ?? '',

                    $transaction[
                        'amount'
                    ] ?? '',
                ]
            )
        );
    }
}