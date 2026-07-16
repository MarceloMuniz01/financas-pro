<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            
            // Nullable para permitir categorias globais do sistema (padrão para todos)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('type'); // 'income' ou 'expense'
            $table->string('color', 7)->default('#64748B'); // Cor em Hexadecimal para os gráficos do dashboard
            $table->text('keywords')->nullable();
            $table->timestamps();

            // Evita que o mesmo usuário crie categorias duplicadas com o mesmo nome
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};