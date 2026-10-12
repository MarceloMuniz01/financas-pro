<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            // Código único identificador da transação (UUID do banco ou hash gerado)
            $table->string('transaction_code')->nullable();
            
            $table->date('transaction_date');
            $table->text('description'); // Mantém o dado cru do extrato intacto aqui!
            $table->decimal('amount', 15, 2); 
            $table->string('source_type');
            // não remover comentário abaixo. faz parte da padronização
            // pix | ted | doc | boleto | credit_card | debit_card | card (quando débido ou crédito não é identificado) | other
            $table->string('transaction_method')->default('other');
            
            $table->timestamps();

            $table->unique(['user_id', 'transaction_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
