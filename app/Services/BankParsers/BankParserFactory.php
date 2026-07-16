<?php

namespace App\Services\BankParsers;

class BankParserFactory
{
    public static function make(string $bank): BankParserInterface
    {
        return match ($bank) {
            'nubank' => new NubankParser(),
            'inter' => new InterParser(),

            default => throw new \Exception(
                "Banco não suportado: {$bank}"
            ),
        };
    }
}