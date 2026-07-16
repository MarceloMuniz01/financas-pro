<?php

// 1. Cabeçalho exatamente como você pediu
$rows = ["Data,Valor,Identificador,Descrição"];

// Data de início para as 1000 linhas fictícias
$timestamp = strtotime('2026-05-02 08:00:00');

$nomes = [
    'COMERCIAL SAO BERNARDO LTDA', 
    'Leandro Oliveira Cardoso', 
    'Valdivan Santos Almeida', 
    'Maria Silva Santos', 
    'Mercadinho Preço Bom', 
    'Ana Paula Lima'
];

$bancos = [
    'BCO DO BRASIL S.A. (0001) Agência: 2555 Conta: 39716-4', 
    'NU PAGAMENTOS - IP (0260) Agência: 1 Conta: 77345795-4', 
    'ITAU UNIBANCO S.A. (0341) Agência: 0100 Conta: 12345-6', 
    'BANCO BRADESCO S.A. (0237) Agência: 1234 Conta: 56789-0'
];

echo "Gerando 1000 linhas de transações no formato exato...\n";

for ($i = 0; $i < 1000; $i++) {
    // Incrementa o tempo para mudar as datas/horas
    $current_time = $timestamp + ($i * 15 * 60);
    $date = date('d/m/Y', $current_time);
    
    // Define valores reais
    $isNegative = rand(0, 100) > 30; 
    $valor = ($isNegative ? '-' : '') . number_format(rand(10, 500) + (rand(0, 99) / 100), 2, '.', '');
    
    // Gera um UUID/Hash idêntico ao do seu exemplo
    $identificador = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        rand(0, 0xffff), rand(0, 0xffff),
        rand(0, 0xffff),
        rand(0, 0x0fff) | 0x4000,
        rand(0, 0x3fff) | 0x8000,
        rand(0, 0xffff), rand(0, 0xffff), rand(0, 0xffff)
    );
    
    $nome = $nomes[array_rand($nomes)];
    $banco = $bancos[array_rand($bancos)];
    $doc = (rand(0, 1) === 0) ? '11.289.988/0001-77' : '•••.349.203-••';
    
    $metodo = "Transferência " . ($isNegative ? "enviada" : "recebida") . " pelo Pix";
    
    // Monta a string de Descrição com os hífens exatos
    $desc = "{$metodo} - {$nome} - {$doc} - {$banco}";
    
    // Junta as 4 colunas protegendo a descrição com aspas
    $rows[] = "{$date},{$valor},{$identificador},{$desc}";
}

// Junta todas as linhas
$content = implode("\n", $rows);

// Força o arquivo a ser salvo estritamente em UTF-8 para evitar problemas de encoding
$content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));

file_put_contents('import_teste_1000.csv', $content);

echo "Sucesso! Arquivo 'import_teste_1000.csv' gerado com 1000 linhas idênticas ao seu modelo.\n";