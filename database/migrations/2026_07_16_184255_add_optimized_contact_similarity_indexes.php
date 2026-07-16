<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Extensões necessárias
        |--------------------------------------------------------------------------
        |
        | pg_trgm:
        | - busca aproximada por trigramas;
        | - operador de distância <->;
        | - índice GiST para nearest-neighbor.
        |
        | fuzzystrmatch:
        | - função levenshtein_less_equal().
        |
        */

        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS pg_trgm'
        );

        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS fuzzystrmatch'
        );

        /*
        |--------------------------------------------------------------------------
        | Índice GiST para os nomes normalizados
        |--------------------------------------------------------------------------
        |
        | Usaremos consultas deste tipo:
        |
        | ORDER BY normalized_name <-> ?
        | LIMIT 5
        |
        | siglen=64 aumenta a precisão da assinatura do índice,
        | reduzindo candidatos falsos em bases maiores.
        |
        */

        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_normalized_name_gist_trgm_index
            ON contacts
            USING gist (
                normalized_name gist_trgm_ops(siglen=64)
            )
            '
        );

        /*
        |--------------------------------------------------------------------------
        | Índice B-tree para documentos
        |--------------------------------------------------------------------------
        |
        | Busca por igualdade de CPF, CNPJ ou documento censurado.
        |
        */

        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_user_document_btree_index
            ON contacts (
                user_id,
                document
            )
            WHERE document IS NOT NULL
            '
        );

        /*
        |--------------------------------------------------------------------------
        | Índice para filtrar por usuário
        |--------------------------------------------------------------------------
        |
        | O índice GiST trabalha sobre o nome. Este índice auxilia
        | o filtro obrigatório pelo proprietário do contato.
        |
        */

        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_user_id_similarity_index
            ON contacts (
                user_id,
                id
            )
            '
        );

        /*
        |--------------------------------------------------------------------------
        | Índice parcial dos possíveis contatos principais
        |--------------------------------------------------------------------------
        |
        | Contatos que já apontam para outro contato não devem ser
        | escolhidos como destino de uma nova sugestão.
        |
        */

        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_similarity_candidates_index
            ON contacts (
                user_id,
                id
            )
            WHERE looks_like_contact_id IS NULL
              AND similarity_dismissed_at IS NULL
            '
        );
    }

    public function down(): void
    {
        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_similarity_candidates_index
            '
        );

        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_user_id_similarity_index
            '
        );

        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_user_document_btree_index
            '
        );

        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_normalized_name_gist_trgm_index
            '
        );

        /*
         * Não removemos pg_trgm nem fuzzystrmatch.
         *
         * Outras partes da aplicação podem passar a depender
         * dessas extensões.
         */
    }
};