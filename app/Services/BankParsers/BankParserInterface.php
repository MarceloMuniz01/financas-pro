<?php

namespace App\Services\BankParsers;

interface BankParserInterface
{
    /**
     * Processa um arquivo de extrato bancário utilizando stream.
     *
     * O retorno é um iterable para permitir processamento
     * linha a linha, sem carregar o arquivo inteiro na memória.
     *
     * @param resource $stream
     */
    public function parse($stream): iterable;
}