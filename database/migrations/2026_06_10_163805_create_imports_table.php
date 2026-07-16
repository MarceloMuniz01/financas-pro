<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->index();

            $table->string('source');

            $table->string('bank')->nullable();

            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('file_hash', 64)->nullable();

            $table->enum('status', [
                'pending',
                'processing',
                'done',
                'failed'
            ])->default('pending');

            $table->text('error_message')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
        DB::statement("
            CREATE UNIQUE INDEX imports_user_id_file_hash_active_unique 
            ON imports (user_id, file_hash) 
            WHERE status IN ('pending', 'processing')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
