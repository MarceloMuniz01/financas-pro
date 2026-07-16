<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // ==========================================
            // --- DESPESAS / SAÍDAS (expense) ---
            // ==========================================
            'expense' => [
                'Outros' => [
                    'color' => '#64748B',
                    'keywords' => ''
                ],
                'Transporte' => [
                    'color' => '#F59E0B',
                    'keywords' => 'UBER;99APP;99 TAXI;CONFLY;POSTO;BRMANIA;SHELL;IPIRANGA;PETROBRAS;PEDAGIO'
                ],
                'Alimentação' => [
                    'color' => '#EF4444',
                    'keywords' => 'IFOOD;WENDYS;MCDONALDS;BOBS;RESTAURANTE;SUPERMERCADO;PADARIA;PAO DE ACUCAR;CARREFOUR;ULTRABOX;ATACADAO;ATACADO;ASSAI;BURGER KING;BK ;SUBWAY;MERCADINHO;MERCADO;MERCEARIA;ACOUGUE;AÇOUGUE;EMPÓRIO;EMPORIO;CONVENIENCIA'
                ],
                'Serviços & Assinaturas' => [
                    'color' => '#06B6D4',
                    'keywords' => 'NETFLIX;SPOTIFY;PRIME VIDEO;AMAZON PRIME;STEAM;PLAYSTATION;NINTENDO;MICROSOFT;GOOGLE;APPLE;HERD;REPLIT;GITHUB'
                ],
                'Lazer & Estilo de Vida' => [
                    'color' => '#EC4899',
                    'keywords' => 'CINEMA;SHOW;TEATRO;BAR ;PUB ;CERVEJARIA;CHOPP;HOTEL;AIRBNB;VIAGEM'
                ],
                'Saúde' => [
                    'color' => '#10B981',
                    'keywords' => 'FARMACIA;DROGARIA;HOSPITAL;CLINICA;MEDICO;ODONTO;LABORATORIO;PAGUE MENOS;RAIA;DROGASIL'
                ],
                'Educação' => [
                    'color' => '#8B5CF6',
                    'keywords' => 'UDEMY;ALURA;CURSO;FACULDADE;ESCOLA;LIVRARIA;COMPUTAÇÃO;CONCURSO'
                ],
            ],

            // ==========================================
            // --- RECEITAS / ENTRADAS (income) ---
            // ==========================================
            'income' => [
                'Outros' => [
                    'color' => '#64748B',
                    'keywords' => ''
                ],
                'Salário' => [
                    'color' => '#22C55E',
                    'keywords' => 'FOLHA;SALARIO;VENCIMENTO;REMUNERACAO;EMPRESA;LTDA;S.A.'
                ],
                'Rendimentos' => [
                    'color' => '#A855F7',
                    'keywords' => 'RENDIMENTO;TESOURO;DIVIDENDOS;FII;JUROS;APLICACAO'
                ],
                'Freelance / Serviços' => [
                    'color' => '#14B8A6',
                    'keywords' => 'FREELANCE;FREELA;SERVICO;PROJETO;HONORARIOS'
                ],
            ]
        ];

        // Dois laços simples: um para o tipo ('expense'/'income') e outro para as categorias dele
        foreach ($categories as $type => $list) {
            foreach ($list as $name => $data) {
                Category::updateOrCreate(
                    // O banco diferencia pelo par composto: nome + tipo
                    ['user_id' => null, 'name' => $name, 'type' => $type],
                    [
                        'color'    => $data['color'],
                        'keywords' => $data['keywords']
                    ]
                );
            }
        }
    }
}