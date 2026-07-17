<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                if (
                    Schema::hasColumn(
                        'contacts',
                        'looks_like_contact_id'
                    )
                ) {
                    $table->dropConstrainedForeignId(
                        'looks_like_contact_id'
                    );
                }

                $columns = [];

                foreach ([
                    'similarity_dismissed_at',
                    'similarity_key',
                    'similarity_signature',
                    'similarity_prefix',
                ] as $column) {
                    if (
                        Schema::hasColumn(
                            'contacts',
                            $column
                        )
                    ) {
                        $columns[] = $column;
                    }
                }

                if ($columns !== []) {
                    $table->dropColumn(
                        $columns
                    );
                }
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $table
                    ->foreignId(
                        'looks_like_contact_id'
                    )
                    ->nullable()
                    ->constrained(
                        'contacts'
                    )
                    ->nullOnDelete();

                $table
                    ->timestamp(
                        'similarity_dismissed_at'
                    )
                    ->nullable();

                $table
                    ->string(
                        'similarity_key',
                        12
                    )
                    ->nullable();

                $table
                    ->string(
                        'similarity_signature'
                    )
                    ->nullable();

                $table
                    ->string(
                        'similarity_prefix'
                    )
                    ->nullable();
            }
        );
    }
};