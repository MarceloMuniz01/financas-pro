<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Controle de sugestões ignoradas
        |--------------------------------------------------------------------------
        |
        | Quando o usuário ignora uma sugestão de duplicidade, registramos
        | o momento para que o detector não recrie a mesma sugestão em toda
        | importação.
        |
        | Quando o contato for editado, esse campo poderá ser limpo para
        | permitir uma nova análise.
        |
        */

        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('similarity_dismissed_at')
                ->nullable()
                ->after('looks_like_contact_id');

            $table->index(
                [
                    'user_id',
                    'similarity_dismissed_at',
                ],
                'contacts_user_similarity_dismissed_index'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Apelidos dos contatos
        |--------------------------------------------------------------------------
        |
        | Exemplo:
        |
        | Contato principal:
        | Maria dos Navegantes
        |
        | Apelido:
        | mariadosnavegant
        |
        | Nas próximas importações, quando o parser retornar o apelido,
        | a transação será vinculada diretamente ao contato principal.
        |
        */

        Schema::create('contact_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->cascadeOnDelete();

            /*
             * Nome original que apareceu no extrato ou que pertencia
             * ao contato removido durante a mesclagem.
             */
            $table->string('name');

            /*
             * Versão usada para comparação.
             *
             * Exemplo:
             *
             * "Maria dos Navegantes"
             * vira
             * "MARIADOSNAVEGANTES"
             */
            $table->string('normalized_name');

            $table->timestamps();

            /*
             * Um mesmo nome normalizado só pode apontar para um contato
             * dentro da conta do usuário.
             */
            $table->unique(
                [
                    'user_id',
                    'normalized_name',
                ],
                'contact_aliases_user_normalized_unique'
            );

            /*
             * Facilita carregar todos os aliases de um contato.
             */
            $table->index(
                'contact_id',
                'contact_aliases_contact_index'
            );

            /*
             * Facilita carregar todos os aliases de um usuário
             * durante uma importação.
             */
            $table->index(
                [
                    'user_id',
                    'contact_id',
                ],
                'contact_aliases_user_contact_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_aliases');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(
                'contacts_user_similarity_dismissed_index'
            );

            $table->dropColumn(
                'similarity_dismissed_at'
            );
        });
    }
};