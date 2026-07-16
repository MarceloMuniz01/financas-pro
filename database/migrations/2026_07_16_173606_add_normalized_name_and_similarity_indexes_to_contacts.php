<?php

use App\Services\Contacts\ContactNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Novas colunas
        |--------------------------------------------------------------------------
        |
        | Primeiro criamos como nullable para permitir preencher os contatos
        | que já existem antes de aplicar a restrição NOT NULL.
        |
        */

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('normalized_name')
                ->nullable()
                ->after('name');

            $table->string('similarity_key', 12)
                ->nullable()
                ->after('normalized_name');
        });

        /*
        |--------------------------------------------------------------------------
        | Preencher contatos existentes
        |--------------------------------------------------------------------------
        */

        DB::table('contacts')
            ->select([
                'id',
                'name',
            ])
            ->orderBy('id')
            ->chunkById(
                1000,
                function ($contacts): void {
                    foreach ($contacts as $contact) {
                        $normalizedName =
                            ContactNameNormalizer::normalize(
                                $contact->name
                            );

                        DB::table('contacts')
                            ->where('id', $contact->id)
                            ->update([
                                'normalized_name' =>
                                    $normalizedName,

                                'similarity_key' =>
                                    mb_substr(
                                        $normalizedName,
                                        0,
                                        12,
                                        'UTF-8'
                                    ),
                            ]);
                    }
                }
            );

        /*
        |--------------------------------------------------------------------------
        | Aplicar NOT NULL
        |--------------------------------------------------------------------------
        */

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('normalized_name')
                ->nullable(false)
                ->change();

            $table->string('similarity_key', 12)
                ->nullable(false)
                ->change();
        });

        /*
        |--------------------------------------------------------------------------
        | Remover índice único antigo
        |--------------------------------------------------------------------------
        |
        | Sua migration original criou:
        |
        | unique(['user_id', 'name'])
        |
        | Agora a unicidade será baseada no nome normalizado.
        |
        */

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(
                'contacts_user_id_name_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Novos índices
        |--------------------------------------------------------------------------
        */

        Schema::table('contacts', function (Blueprint $table) {
            /*
             * Impede contatos duplicados com variações de caixa,
             * espaços, hífens e acentos.
             */
            $table->unique(
                [
                    'user_id',
                    'normalized_name',
                ],
                'contacts_user_normalized_name_unique'
            );

            /*
             * Busca rápida dos candidatos à similaridade.
             */
            $table->index(
                [
                    'user_id',
                    'similarity_key',
                ],
                'contacts_user_similarity_key_index'
            );

            /*
             * Busca por documento durante detecção e mesclagem.
             */
            $table->index(
                [
                    'user_id',
                    'document',
                ],
                'contacts_user_document_index'
            );

            /*
             * Busca de contatos que apontam para uma possível duplicidade.
             */
            $table->index(
                [
                    'user_id',
                    'looks_like_contact_id',
                ],
                'contacts_user_looks_like_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Índices de transações
        |--------------------------------------------------------------------------
        */

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(
                [
                    'user_id',
                    'contact_id',
                ],
                'transactions_user_contact_index'
            );

            /*
             * insertOrIgnore só evita duplicidade se existir uma restrição
             * única correspondente.
             */
            $table->unique(
                [
                    'user_id',
                    'transaction_code',
                ],
                'transactions_user_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(
                'transactions_user_code_unique'
            );

            $table->dropIndex(
                'transactions_user_contact_index'
            );
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(
                'contacts_user_normalized_name_unique'
            );

            $table->dropIndex(
                'contacts_user_similarity_key_index'
            );

            $table->dropIndex(
                'contacts_user_document_index'
            );

            $table->dropIndex(
                'contacts_user_looks_like_index'
            );

            $table->dropColumn([
                'normalized_name',
                'similarity_key',
            ]);

            $table->unique(
                [
                    'user_id',
                    'name',
                ],
                'contacts_user_id_name_unique'
            );
        });
    }
};