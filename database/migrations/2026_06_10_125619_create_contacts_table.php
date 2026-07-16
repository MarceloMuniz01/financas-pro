<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            /*
             * Usuário dono do contato.
             */
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->index();

            /*
             * Categoria padrão para despesas.
             */
            $table->foreignId('default_expense_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            /*
             * Categoria padrão para receitas.
             */
            $table->foreignId('default_income_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            /*
             * Contato semelhante (uso futuro para deduplicação).
             */
            $table->foreignId('looks_like_contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            /*
             * Dados da contraparte.
             */
            $table->string('name');

            // CPF/CNPJ (formatado ou normalizado)
            $table->string('document', 20)->nullable();

            // company | individual
            $table->string('contact_type')->nullable();

            $table->timestamps();

            /*
             * Não permite dois contatos com o mesmo nome
             * para o mesmo usuário.
             */
            $table->unique([
                'user_id',
                'name',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};