<?php

namespace App\Services\BankParsers;

class BankDetector
{
    public function detect(string $fileContent): string
    {
        if (
            str_contains($fileContent, 'Identificador')
            && str_contains($fileContent, 'Descrição')
        ) {
            return 'nubank';
        }

        if (str_contains($fileContent, 'Data Lançamento;Histórico;Descrição;Valor;Saldo')) {
            return 'inter';
        }

        throw new \Exception(
            'Não foi possível identificar o banco.'
        );
    }
}