<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        /*
         * Extensão PostgreSQL para similaridade textual.
         */
        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS pg_trgm'
        );

        /*
         * Índice GIN usado nas pesquisas por similaridade.
         *
         * O user_id continua sendo validado separadamente
         * em todas as consultas.
         */
        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_normalized_name_trgm_index
            ON contacts
            USING gin (
                normalized_name gin_trgm_ops
            )
            '
        );

        /*
         * Índice para buscas por usuário e documento.
         */
        DB::statement(
            '
            CREATE INDEX IF NOT EXISTS
                contacts_user_document_similarity_index
            ON contacts (
                user_id,
                document
            )
            '
        );
    }

    public function down(): void
    {
        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_normalized_name_trgm_index
            '
        );

        DB::statement(
            '
            DROP INDEX IF EXISTS
                contacts_user_document_similarity_index
            '
        );

        /*
         * Não removemos pg_trgm porque outras tabelas ou
         * funcionalidades podem passar a utilizá-la.
         */
    }
};