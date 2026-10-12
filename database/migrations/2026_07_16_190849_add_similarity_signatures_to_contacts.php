<?php

use App\Services\Contacts\ContactSimilaritySignature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /*
         * Começam nullable para permitir o preenchimento
         * dos contatos existentes.
         */
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $table->string(
                    'similarity_signature',
                    24
                )
                    ->nullable()
                    ->after('similarity_key');

                $table->string(
                    'similarity_prefix',
                    12
                )
                    ->nullable()
                    ->after(
                        'similarity_signature'
                    );
            }
        );

        /*
         * Preenche contatos existentes.
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
                    $updates = [];

                    foreach ($contacts as $contact) {
                        $keys =
                            ContactSimilaritySignature::make(
                                $contact->name
                            );

                        $updates[] = [
                            'id' =>
                                (int) $contact->id,

                            'similarity_signature' =>
                                $keys[
                                    'similarity_signature'
                                ],

                            'similarity_prefix' =>
                                $keys[
                                    'similarity_prefix'
                                ],
                        ];
                    }

                    foreach ($updates as $update) {
                        DB::table('contacts')
                            ->where(
                                'id',
                                $update['id']
                            )
                            ->update([
                                'similarity_signature' =>
                                    $update[
                                        'similarity_signature'
                                    ],

                                'similarity_prefix' =>
                                    $update[
                                        'similarity_prefix'
                                    ],
                            ]);
                    }
                },
                'id'
            );

        /*
         * Índices B-tree compostos pelo usuário.
         */
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
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
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'contacts_user_similarity_signature_index'
                );

                $table->dropIndex(
                    'contacts_user_similarity_prefix_index'
                );

                $table->dropColumn([
                    'similarity_signature',
                    'similarity_prefix',
                ]);
            }
        );
    }
};