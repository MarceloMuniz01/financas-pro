<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectContactSimilaritiesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Quantidade de contatos de origem processados
     * em cada consulta.
     */
    private const SOURCE_CHUNK_SIZE = 6000;

    /**
     * Máximo de candidatos pré-selecionados para
     * cada contato de origem.
     */
    private const CANDIDATE_LIMIT = 20;

    /**
     * Quantidade de atualizações por consulta.
     */
    private const UPDATE_BATCH_SIZE = 6000;

    /**
     * Tamanho mínimo para aceitar quando um nome
     * é prefixo do outro.
     */
    private const MINIMUM_PREFIX_LENGTH = 8;

    /**
     * Percentual máximo usado para calcular a
     * distância de edição permitida.
     *
     * 0.15 = até aproximadamente 15% do tamanho
     * do maior nome.
     */
    private const MAXIMUM_EDIT_DISTANCE_PERCENTAGE = 0.15;

    /**
     * Distância máxima absoluta.
     *
     * Evita considerar nomes grandes demais como semelhantes
     * somente porque 15% resultou em um número elevado.
     */
    private const MAXIMUM_EDIT_DISTANCE = 5;

    /**
     * Distância mínima permitida.
     */
    private const MINIMUM_EDIT_DISTANCE = 1;

    /**
     * Tempo máximo do job.
     */
    public int $timeout = 900;

    /**
     * Máximo de tentativas.
     */
    public int $tries = 3;

    /**
     * @param array<int> $contactIds
     */
    public function __construct(
        public int $userId,
        public array $contactIds
    ) {
        $this->contactIds = array_values(
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
    }

    public function handle(): void
    {
        $jobStartedAt = microtime(true);

        if (empty($this->contactIds)) {
            Log::info(
                'Detecção de similaridade ignorada.',
                [
                    'user_id' => $this->userId,
                    'reason' => 'nenhum contato informado',
                ]
            );

            return;
        }

        $requestedContacts = count(
            $this->contactIds
        );

        $processedSources = 0;
        $candidateRows = 0;
        $confirmedMatches = 0;
        $suggestionsChanged = 0;
        $suggestionsCleared = 0;

        try {
            foreach (
                array_chunk(
                    $this->contactIds,
                    self::SOURCE_CHUNK_SIZE
                )
                as $sourceIds
            ) {
                $chunkStartedAt = microtime(true);

                /*
                 * Carrega somente os contatos novos pertencentes
                 * a este job.
                 */
                $sources = $this->loadSources(
                    $sourceIds
                );

                if (empty($sources)) {
                    continue;
                }

                /*
                 * Busca candidatos em uma única consulta:
                 *
                 * - mesmo documento;
                 * - mesma assinatura;
                 * - mesmo prefixo.
                 */
                $candidatesBySource =
                    $this->loadCandidates(
                        $sources
                    );

                $updates = [];

                foreach ($sources as $source) {
                    $processedSources++;

                    $sourceId =
                        (int) $source->id;

                    $bestCandidate = null;
                    $bestScore = PHP_INT_MIN;

                    foreach (
                        $candidatesBySource[
                            $sourceId
                        ] ?? []
                        as $candidate
                    ) {
                        $candidateRows++;

                        $evaluation =
                            $this->evaluateCandidate(
                                source: $source,
                                candidate: $candidate
                            );

                        if (!$evaluation['matched']) {
                            continue;
                        }

                        $confirmedMatches++;

                        $score =
                            $this->calculateCandidateScore(
                                candidate: $candidate,
                                evaluation: $evaluation
                            );

                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestCandidate = $candidate;
                        }
                    }

                    $newSuggestionId =
                        $bestCandidate !== null
                        ? (int) $bestCandidate
                            ->candidate_id
                        : null;

                    $currentSuggestionId =
                        $source
                            ->looks_like_contact_id
                        !== null
                        ? (int) $source
                            ->looks_like_contact_id
                        : null;

                    if (
                        $newSuggestionId
                        === $currentSuggestionId
                    ) {
                        continue;
                    }

                    $updates[] = [
                        'id' => $sourceId,

                        'looks_like_contact_id' =>
                            $newSuggestionId,
                    ];

                    if ($newSuggestionId !== null) {
                        $suggestionsChanged++;
                    } else {
                        $suggestionsCleared++;
                    }

                    if (
                        count($updates)
                        >= self::UPDATE_BATCH_SIZE
                    ) {
                        $this->flushUpdates(
                            $updates
                        );
                    }
                }

                $this->flushUpdates(
                    $updates
                );

                Log::info(
                    'Lote B-tree de similaridade concluído.',
                    [
                        'user_id' =>
                            $this->userId,

                        'requested_sources' =>
                            count($sourceIds),

                        'loaded_sources' =>
                            count($sources),

                        'candidate_rows' =>
                            array_sum(
                                array_map(
                                    'count',
                                    $candidatesBySource
                                )
                            ),

                        'seconds' => round(
                            microtime(true)
                            - $chunkStartedAt,
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
            }

            Log::info(
                'Detecção B-tree de contatos semelhantes concluída.',
                [
                    'user_id' =>
                        $this->userId,

                    'requested_contacts' =>
                        $requestedContacts,

                    'processed_sources' =>
                        $processedSources,

                    'candidate_rows' =>
                        $candidateRows,

                    'confirmed_matches' =>
                        $confirmedMatches,

                    'suggestions_changed' =>
                        $suggestionsChanged,

                    'suggestions_cleared' =>
                        $suggestionsCleared,

                    'seconds' => round(
                        microtime(true)
                        - $jobStartedAt,
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
                'Erro na detecção B-tree de contatos semelhantes.',
                [
                    'user_id' =>
                        $this->userId,

                    'contact_ids_count' =>
                        count($this->contactIds),

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
     * Carrega somente os contatos novos enviados para o job.
     *
     * Contatos cuja sugestão foi ignorada pelo usuário
     * não são reanalisados.
     *
     * @param array<int> $sourceIds
     * @return array<int, object>
     */
    private function loadSources(
        array $sourceIds
    ): array {
        if (empty($sourceIds)) {
            return [];
        }

        return DB::table('contacts')
            ->where(
                'user_id',
                $this->userId
            )
            ->whereIn(
                'id',
                $sourceIds
            )
            ->whereNull(
                'similarity_dismissed_at'
            )
            ->get([
                'id',
                'name',
                'normalized_name',
                'similarity_signature',
                'similarity_prefix',
                'document',
                'contact_type',
                'default_expense_category_id',
                'default_income_category_id',
                'looks_like_contact_id',
            ])
            ->all();
    }

    /**
     * Busca candidatos pelos índices B-tree.
     *
     * A consulta utiliza:
     *
     * - user_id + document;
     * - user_id + similarity_signature;
     * - user_id + similarity_prefix.
     *
     * @param array<int, object> $sources
     * @return array<int, array<int, object>>
     */
    private function loadCandidates(
        array $sources
    ): array {
        if (empty($sources)) {
            return [];
        }

        $sourceValuesSql = [];
        $bindings = [];

        foreach ($sources as $source) {
            $sourceValuesSql[] = <<<'SQL'
        (
            CAST(? AS BIGINT),
            CAST(? AS VARCHAR),
            CAST(? AS VARCHAR),
            CAST(? AS VARCHAR),
            CAST(? AS VARCHAR),
            CAST(? AS VARCHAR),
            CAST(? AS VARCHAR)
        )
    SQL;

            $bindings[] = (int) $source->id;
            $bindings[] = (string) $source->name;
            $bindings[] = (string) $source->normalized_name;
            $bindings[] = (string) ($source->similarity_signature ?? '');
            $bindings[] = (string) ($source->similarity_prefix ?? '');
            $bindings[] = $source->document;
            $bindings[] = $source->contact_type;
        }

        $sourceValues = implode(
            ', ',
            $sourceValuesSql
        );

        $candidateLimit =
            self::CANDIDATE_LIMIT;

        $targetCompletenessSql =
            $this->completenessSql(
                'target'
            );

        $sourceCompletenessSql =
            $this->sourceCompletenessSql(
                'source'
            );

        $sql = <<<SQL
            WITH source_contacts (
                id,
                name,
                normalized_name,
                similarity_signature,
                similarity_prefix,
                document,
                contact_type
            ) AS (
                VALUES
                    {$sourceValues}
            )

            SELECT
                source.id
                    AS source_id,

                target.id
                    AS candidate_id,

                target.name
                    AS candidate_name,

                target.normalized_name
                    AS candidate_normalized_name,

                target.document
                    AS candidate_document,

                target.contact_type
                    AS candidate_contact_type,

                target.default_expense_category_id
                    AS candidate_default_expense_category_id,

                target.default_income_category_id
                    AS candidate_default_income_category_id,

                (
                    source.document IS NOT NULL
                    AND source.document <> ''
                    AND target.document = source.document
                )
                    AS same_document,

                (
                    source.similarity_signature <> ''
                    AND target.similarity_signature =
                        source.similarity_signature
                )
                    AS same_signature,

                (
                    source.similarity_prefix <> ''
                    AND target.similarity_prefix =
                        source.similarity_prefix
                )
                    AS same_prefix,

                {$targetCompletenessSql}
                    AS candidate_completeness,

                {$sourceCompletenessSql}
                    AS source_completeness,

                name_distance.edit_distance,

                name_distance.max_edit_distance

            FROM source_contacts AS source

            CROSS JOIN LATERAL (
                SELECT
                    candidate_pool.*

                FROM (
                    /*
                    |--------------------------------------------------------------------------
                    | Documento igual
                    |--------------------------------------------------------------------------
                    */

                    (
                        SELECT
                            target_by_document.id

                        FROM contacts
                            AS target_by_document

                        WHERE source.document IS NOT NULL

                          AND source.document <> ''

                          AND target_by_document.user_id = ?

                          AND target_by_document.document =
                              source.document

                          AND target_by_document.id <>
                              source.id

                          AND target_by_document
                              .looks_like_contact_id
                              IS NULL

                        ORDER BY
                            target_by_document.id ASC

                        LIMIT {$candidateLimit}
                    )

                    UNION

                    /*
                    |--------------------------------------------------------------------------
                    | Assinatura igual
                    |--------------------------------------------------------------------------
                    */

                    (
                        SELECT
                            target_by_signature.id

                        FROM contacts
                            AS target_by_signature

                        WHERE source.similarity_signature <> ''

                          AND target_by_signature.user_id = ?

                          AND target_by_signature
                              .similarity_signature =
                              source.similarity_signature

                          AND target_by_signature.id <>
                              source.id

                          AND target_by_signature
                              .looks_like_contact_id
                              IS NULL

                        ORDER BY
                            target_by_signature.id ASC

                        LIMIT {$candidateLimit}
                    )

                    UNION

                    /*
                    |--------------------------------------------------------------------------
                    | Prefixo igual
                    |--------------------------------------------------------------------------
                    */

                    (
                        SELECT
                            target_by_prefix.id

                        FROM contacts
                            AS target_by_prefix

                        WHERE source.similarity_prefix <> ''

                          AND target_by_prefix.user_id = ?

                          AND target_by_prefix
                              .similarity_prefix =
                              source.similarity_prefix

                          AND target_by_prefix.id <>
                              source.id

                          AND target_by_prefix
                              .looks_like_contact_id
                              IS NULL

                        ORDER BY
                            target_by_prefix.id ASC

                        LIMIT {$candidateLimit}
                    )
                ) AS candidate_pool

                LIMIT {$candidateLimit}
            ) AS candidate_ids

            INNER JOIN contacts AS target
                ON target.id =
                    candidate_ids.id

               AND target.user_id = ?

            CROSS JOIN LATERAL (
                SELECT
                    GREATEST(
                        1,

                        LEAST(
                            5,

                            CEIL(
                                GREATEST(
                                    CHAR_LENGTH(
                                        source.normalized_name
                                    ),

                                    CHAR_LENGTH(
                                        target.normalized_name
                                    )
                                ) * 0.15
                            )::integer
                        )
                    )
                        AS max_edit_distance,

                    levenshtein_less_equal(
                        LEFT(
                            source.normalized_name,
                            255
                        ),

                        LEFT(
                            target.normalized_name,
                            255
                        ),

                        GREATEST(
                            1,

                            LEAST(
                                5,

                                CEIL(
                                    GREATEST(
                                        CHAR_LENGTH(
                                            source.normalized_name
                                        ),

                                        CHAR_LENGTH(
                                            target.normalized_name
                                        )
                                    ) * 0.15
                                )::integer
                            )
                        )
                    )
                        AS edit_distance
            ) AS name_distance

            WHERE
                /*
                 * Empresa e pessoa não podem ser relacionadas
                 * quando ambos possuem tipo definido.
                 */
                (
                    source.contact_type IS NULL

                    OR source.contact_type = ''

                    OR target.contact_type IS NULL

                    OR target.contact_type = ''

                    OR source.contact_type =
                        target.contact_type
                )

                /*
                 * CPF ou CNPJ completos e diferentes impedem
                 * a sugestão.
                 */
                AND NOT (
                    source.document
                        ~ '^(?:[0-9]{11}|[0-9]{14})$'

                    AND target.document
                        ~ '^(?:[0-9]{11}|[0-9]{14})$'

                    AND source.document <>
                        target.document
                )

                /*
                 * O contato de destino precisa possuir mais
                 * informações que o contato de origem.
                 */
                AND (
                    {$targetCompletenessSql}
                ) > (
                    {$sourceCompletenessSql}
                )

            ORDER BY
                source.id ASC,

                same_document DESC,

                same_signature DESC,

                same_prefix DESC,

                name_distance.edit_distance ASC,

                candidate_completeness DESC,

                target.id ASC
        SQL;

        /*
         * O user_id aparece quatro vezes:
         *
         * - documento;
         * - assinatura;
         * - prefixo;
         * - JOIN final.
         */
        $bindings[] = $this->userId;
        $bindings[] = $this->userId;
        $bindings[] = $this->userId;
        $bindings[] = $this->userId;

        $rows = DB::select(
            $sql,
            $bindings
        );

        $grouped = [];

        foreach ($rows as $row) {
            $sourceId =
                (int) $row->source_id;

            $grouped[$sourceId][] =
                $row;
        }

        return $grouped;
    }

    /**
     * Confirma o candidato usando distância de edição
     * e regras adicionais.
     *
     * @return array{
     *     matched: bool,
     *     exact_name: bool,
     *     prefix_match: bool,
     *     same_document: bool,
     *     same_signature: bool,
     *     same_prefix: bool
     * }
     */
    private function evaluateCandidate(
        object $source,
        object $candidate
    ): array {
        $sourceName =
            (string) $source
                ->normalized_name;

        $candidateName =
            (string) $candidate
                ->candidate_normalized_name;

        if (
            $sourceName === ''
            || $candidateName === ''
        ) {
            return [
                'matched' => false,
                'exact_name' => false,
                'prefix_match' => false,
                'same_document' => false,
                'same_signature' => false,
                'same_prefix' => false,
            ];
        }

        $exactName =
            $sourceName === $candidateName;

        $prefixMatch =
            $this->isPrefixMatch(
                $sourceName,
                $candidateName
            );

        $sameDocument =
            $this->toBoolean(
                $candidate->same_document
            );

        $sameSignature =
            $this->toBoolean(
                $candidate->same_signature
            );

        $samePrefix =
            $this->toBoolean(
                $candidate->same_prefix
            );

        $editDistance =
            (int) $candidate
                ->edit_distance;

        $maxEditDistance =
            (int) $candidate
                ->max_edit_distance;

        /*
         * Quando a distância real supera o limite,
         * levenshtein_less_equal retorna um número maior
         * que maxEditDistance.
         */
        $editDistanceAccepted =
            $editDistance <= $maxEditDistance;

        /*
         * Mesmo documento é sinal forte, mas ainda exigimos
         * alguma relação entre os nomes para evitar colisões
         * em documentos incompletos ou mascarados.
         */
        $sameDocumentAccepted =
            $sameDocument
            && (
                $exactName
                || $prefixMatch
                || $sameSignature
                || $samePrefix
                || $editDistanceAccepted
            );

        /*
         * Assinatura e prefixo somente selecionam candidatos.
         * A confirmação final precisa de distância aceitável,
         * nome exato ou relação por prefixo.
         */
        $nameAccepted =
            $exactName
            || $prefixMatch
            || (
                ($sameSignature || $samePrefix)
                && $editDistanceAccepted
            );

        return [
            'matched' =>
                $sameDocumentAccepted
                || $nameAccepted,

            'exact_name' =>
                $exactName,

            'prefix_match' =>
                $prefixMatch,

            'same_document' =>
                $sameDocument,

            'same_signature' =>
                $sameSignature,

            'same_prefix' =>
                $samePrefix,
        ];
    }

    /**
     * Calcula a prioridade do candidato.
     */
    private function calculateCandidateScore(
        object $candidate,
        array $evaluation
    ): int {
        $score =
            (int) $candidate
                ->candidate_completeness;

        if ($evaluation['same_document']) {
            $score += 10_000;
        }

        if ($evaluation['exact_name']) {
            $score += 8_000;
        }

        if ($evaluation['prefix_match']) {
            $score += 5_000;
        }

        if ($evaluation['same_signature']) {
            $score += 2_000;
        }

        if ($evaluation['same_prefix']) {
            $score += 1_000;
        }

        /*
         * Quanto menor a distância, melhor.
         */
        $score -=
            (int) $candidate
                ->edit_distance
            * 100;

        return $score;
    }

    private function isPrefixMatch(
        string $first,
        string $second
    ): bool {
        $firstLength = mb_strlen(
            $first,
            'UTF-8'
        );

        $secondLength = mb_strlen(
            $second,
            'UTF-8'
        );

        $shortest =
            $firstLength <= $secondLength
            ? $first
            : $second;

        $longest =
            $shortest === $first
            ? $second
            : $first;

        if (
            mb_strlen(
                $shortest,
                'UTF-8'
            )
            < self::MINIMUM_PREFIX_LENGTH
        ) {
            return false;
        }

        return str_starts_with(
            $longest,
            $shortest
        );
    }

    /**
     * Completude de um contato real da tabela contacts.
     */
    private function completenessSql(
        string $alias
    ): string {
        return <<<SQL
            (
                LEAST(
                    CHAR_LENGTH(
                        TRIM({$alias}.name)
                    ),
                    60
                )

                + CASE
                    WHEN {$alias}.document IS NOT NULL
                        AND {$alias}.document <> ''
                    THEN 20
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}.document
                        ~ '^(?:[0-9]{11}|[0-9]{14})$'
                    THEN 50
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}.contact_type IS NOT NULL
                        AND {$alias}.contact_type <> ''
                    THEN 25
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}
                        .default_expense_category_id
                        IS NOT NULL
                    THEN 10
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}
                        .default_income_category_id
                        IS NOT NULL
                    THEN 10
                    ELSE 0
                END
            )
        SQL;
    }

    /**
     * Completude das fontes enviadas pelo VALUES.
     *
     * As fontes não carregam as categorias nesta consulta,
     * portanto consideramos nome, documento e tipo.
     */
    private function sourceCompletenessSql(
        string $alias
    ): string {
        return <<<SQL
            (
                LEAST(
                    CHAR_LENGTH(
                        TRIM({$alias}.name)
                    ),
                    60
                )

                + CASE
                    WHEN {$alias}.document IS NOT NULL
                        AND {$alias}.document <> ''
                    THEN 20
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}.document
                        ~ '^(?:[0-9]{11}|[0-9]{14})$'
                    THEN 50
                    ELSE 0
                END

                + CASE
                    WHEN {$alias}.contact_type IS NOT NULL
                        AND {$alias}.contact_type <> ''
                    THEN 25
                    ELSE 0
                END
            )
        SQL;
    }

    /**
     * Atualiza sugestões em lote usando UPDATE FROM VALUES.
     *
     * @param array<int, array{
     *     id: int,
     *     looks_like_contact_id: int|null
     * }> $updates
     */
    private function flushUpdates(
        array &$updates
    ): void {
        if (empty($updates)) {
            return;
        }

        foreach (
            array_chunk(
                $updates,
                self::UPDATE_BATCH_SIZE
            )
            as $chunk
        ) {
            $valueSql = [];
            $bindings = [];

            foreach ($chunk as $update) {
                $valueSql[] = '
                    (
                        CAST(? AS BIGINT),
                        CAST(? AS BIGINT)
                    )
                ';

                $bindings[] =
                    (int) $update['id'];

                $bindings[] =
                    $update[
                        'looks_like_contact_id'
                    ] !== null
                    ? (int) $update[
                        'looks_like_contact_id'
                    ]
                    : null;
            }

            $values = implode(
                ', ',
                $valueSql
            );

            $sql = <<<SQL
                UPDATE contacts AS contact

                SET
                    looks_like_contact_id =
                        changes.looks_like_contact_id,

                    updated_at =
                        CURRENT_TIMESTAMP

                FROM (
                    VALUES
                        {$values}
                ) AS changes (
                    id,
                    looks_like_contact_id
                )

                WHERE contact.id =
                    changes.id

                  AND contact.user_id = ?
            SQL;

            $bindings[] =
                $this->userId;

            DB::update(
                $sql,
                $bindings
            );
        }

        $updates = [];
    }

    private function toBoolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            $value,
            [
                1,
                '1',
                't',
                'true',
                'TRUE',
            ],
            true
        );
    }
}