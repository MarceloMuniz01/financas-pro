<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table
                ->string('transaction_fingerprint', 64)
                ->nullable()
                ->after('transaction_code');

            $table
                ->string('duplicate_status')
                ->nullable()
                ->after('transaction_fingerprint');

            $table
                ->timestamp('ignored_at')
                ->nullable()
                ->after('duplicate_status');

            $table->index([
                'user_id',
                'transaction_fingerprint',
            ]);

            $table->index([
                'user_id',
                'duplicate_status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex([
                'user_id',
                'transaction_fingerprint',
            ]);

            $table->dropIndex([
                'user_id',
                'duplicate_status',
            ]);

            $table->dropColumn([
                'transaction_fingerprint',
                'duplicate_status',
                'ignored_at',
            ]);
        });
    }
};
