<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Cria o usuário padrão de testes para o ambiente local (ID 1 fixo para evitar erros no curl/trabalho do Job)
        User::updateOrCreate(
            ['id' => 1],
            [
                'name'     => 'Marcelo Teste',
                'email'    => 'marcelo@teste.com',
                'password' => bcrypt('password'),
            ]
        );

        // 2. Chama o seeder de Categorias Globais que acabamos de criar
        $this->call([
            CategorySeeder::class,
        ]);
    }
}