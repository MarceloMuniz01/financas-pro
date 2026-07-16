<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Remover índices criados para similaridade
        |--------------------------------------------------------------------------
        |
        | Usamos DROP INDEX IF EXISTS porque alguns índices podem
        | não existir em todos os ambientes.
        |
        */

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_similarity_key_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_similarity_signature_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_similarity_prefix_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_normalized_name_trgm_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_normalized_name_gist_trgm_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_document_similarity_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_document_btree_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_id_similarity_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_similarity_candidates_index'
        );

        DB::statement(
            'DROP INDEX IF EXISTS contacts_user_looks_like_index'
        );

        /*
        |--------------------------------------------------------------------------
        | Remover foreign key de looks_like_contact_id
        |--------------------------------------------------------------------------
        |
        | O nome da constraint pode variar dependendo da migration
        | original. No PostgreSQL, usamos um bloco seguro.
        |
        */

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname =
                        'contacts_looks_like_contact_id_foreign'
                ) THEN
                    ALTER TABLE contacts
                    DROP CONSTRAINT
                        contacts_looks_like_contact_id_foreign;
                END IF;
            END
            $$;
        SQL);

        /*
        |--------------------------------------------------------------------------
        | Remover colunas
        |--------------------------------------------------------------------------
        */

        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $columns = [
                    'similarity_key',
                    'similarity_signature',
                    'similarity_prefix',
                    'looks_like_contact_id',
                    'similarity_dismissed_at',
                ];

                foreach ($columns as $column) {
                    if (
                        Schema::hasColumn(
                            'contacts',
                            $column
                        )
                    ) {
                        $table->dropColumn(
                            $column
                        );
                    }
                }
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Manter extensões do PostgreSQL
        |--------------------------------------------------------------------------
        |
        | Não removemos pg_trgm ou fuzzystrmatch automaticamente.
        | Elas podem ser úteis futuramente para pesquisa textual.
        |
        */
    }

    public function down(): void
    {
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                if (
                    !Schema::hasColumn(
                        'contacts',
                        'similarity_key'
                    )
                ) {
                    $table->string(
                        'similarity_key',
                        12
                    )
                        ->nullable()
                        ->after(
                            'normalized_name'
                        );
                }

                if (
                    !Schema::hasColumn(
                        'contacts',
                        'similarity_signature'
                    )
                ) {
                    $table->string(
                        'similarity_signature',
                        24
                    )
                        ->nullable()
                        ->after(
                            'similarity_key'
                        );
                }

                if (
                    !Schema::hasColumn(
                        'contacts',
                        'similarity_prefix'
                    )
                ) {
                    $table->string(
                        'similarity_prefix',
                        12
                    )
                        ->nullable()
                        ->after(
                            'similarity_signature'
                        );
                }

                if (
                    !Schema::hasColumn(
                        'contacts',
                        'looks_like_contact_id'
                    )
                ) {
                    $table->foreignId(
                        'looks_like_contact_id'
                    )
                        ->nullable()
                        ->after(
                            'default_income_category_id'
                        )
                        ->constrained(
                            'contacts'
                        )
                        ->nullOnDelete();
                }

                if (
                    !Schema::hasColumn(
                        'contacts',
                        'similarity_dismissed_at'
                    )
                ) {
                    $table->timestamp(
                        'similarity_dismissed_at'
                    )
                        ->nullable()
                        ->after(
                            'looks_like_contact_id'
                        );
                }
            }
        );

        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $table->index(
                    [
                        'user_id',
                        'similarity_key',
                    ],
                    'contacts_user_similarity_key_index'
                );

                $table->index(
                    [
                        'user_id',
                        'similarity_signature',
                    ],
                    'contacts_user_similarity_signature_index'
                );

                $table->index(
                    [
                        'user_id',
                        'similarity_prefix',
                    ],
                    'contacts_user_similarity_prefix_index'
                );

                $table->index(
                    [
                        'user_id',
                        'looks_like_contact_id',
                    ],
                    'contacts_user_looks_like_index'
                );
            }
        );
    }
};