<?php

namespace App\Services\BankParsers;

use RuntimeException;

class BankDetector
{
    /**
     * Quantidade máxima de bytes lidos para identificar o banco.
     */
    private const MAX_HEADER_BYTES = 16 * 1024;

    /**
     * Identifica o banco lendo apenas o início do arquivo.
     *
     * @param resource $stream
     */
    public function detect($stream): string
    {
        if (!is_resource($stream)) {
            throw new RuntimeException(
                'O arquivo informado para detecção não é um stream válido.'
            );
        }

        /*
         * Garante que a leitura comece no início.
         */
        if (fseek($stream, 0) !== 0) {
            throw new RuntimeException(
                'Não foi possível posicionar o arquivo no início para identificar o banco.'
            );
        }

        $header = fread(
            $stream,
            self::MAX_HEADER_BYTES
        );

        if ($header === false) {
            throw new RuntimeException(
                'Não foi possível ler o cabeçalho do arquivo.'
            );
        }

        /*
         * Remove BOM UTF-8, caso exista.
         */
        $header = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $header
        ) ?? $header;

        /*
         * Normaliza finais de linha para facilitar as verificações.
         */
        $header = str_replace(
            ["\r\n", "\r"],
            "\n",
            $header
        );

        /*
        |--------------------------------------------------------------------------
        | Nubank
        |--------------------------------------------------------------------------
        |
        | Cabeçalho esperado:
        |
        | Data,Valor,Identificador,Descrição
        |
        */

        if (
            str_contains(
                $header,
                'Identificador'
            )
            && str_contains(
                $header,
                'Descrição'
            )
            && str_contains(
                $header,
                'Data'
            )
            && str_contains(
                $header,
                'Valor'
            )
        ) {
            $this->rewindStream($stream);

            return 'nubank';
        }

        /*
        |--------------------------------------------------------------------------
        | Banco Inter
        |--------------------------------------------------------------------------
        |
        | Cabeçalho esperado:
        |
        | Data Lançamento;Histórico;Descrição;Valor;Saldo
        |
        */

        if (
            str_contains(
                $header,
                'Data Lançamento;Histórico;Descrição;Valor;Saldo'
            )
        ) {
            $this->rewindStream($stream);

            return 'inter';
        }

        $this->rewindStream($stream);

        throw new RuntimeException(
            'Não foi possível identificar o banco.'
        );
    }

    /**
     * Retorna o stream ao início para o parser.
     *
     * @param resource $stream
     */
    private function rewindStream($stream): void
    {
        if (fseek($stream, 0) !== 0) {
            throw new RuntimeException(
                'Não foi possível retornar ao início do arquivo após identificar o banco.'
            );
        }
    }
}