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
                $table
                    ->foreignId(
                        'merged_into_contact_id'
                    )
                    ->nullable()
                    ->after(
                        'default_income_category_id'
                    )
                    ->constrained(
                        'contacts'
                    )
                    ->nullOnDelete();

                $table
                    ->timestamp(
                        'merged_at'
                    )
                    ->nullable()
                    ->after(
                        'merged_into_contact_id'
                    );

                $table->index([
                    'user_id',
                    'merged_into_contact_id',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'contacts',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'user_id',
                    'merged_into_contact_id',
                ]);

                $table->dropConstrainedForeignId(
                    'merged_into_contact_id'
                );

                $table->dropColumn(
                    'merged_at'
                );
            }
        );
    }
};