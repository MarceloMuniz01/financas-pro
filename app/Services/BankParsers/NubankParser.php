<?php

namespace App\Services\BankParsers;

use Illuminate\Support\Facades\Log;

class NubankParser implements BankParserInterface
{
    /**
     * Processa o CSV do Nubank utilizando stream.
     *
     * @param resource $stream
     */
    public function parse($stream): iterable
    {
        $header = fgetcsv($stream);

        if (!$header) {
            return;
        }

        $header = array_map(
            fn ($column) => trim((string) $column),
            $header
        );

        Log::info('NubankParser iniciado.', [
            'header' => $header,
        ]);

        while (($rowValues = fgetcsv($stream)) !== false) {
            if (empty(array_filter($rowValues))) {
                continue;
            }

            if (count($header) !== count($rowValues)) {
                Log::warning('NubankParser ignorou linha com quantidade inválida de colunas.', [
                    'header_count' => count($header),
                    'row_count' => count($rowValues),
                    'row' => $rowValues,
                ]);

                continue;
            }

            $row = array_combine($header, $rowValues);

            if ($row === false) {
                continue;
            }

            $description = trim((string) ($row['Descrição'] ?? ''));

            if ($description === '') {
                continue;
            }

            $parsed = $this->parseDescription($description);

            $amount = $this->parseAmount(
                (string) ($row['Valor'] ?? '0')
            );

            yield [
                'transaction_code' => trim((string) ($row['Identificador'] ?? '')),

                'transaction_date' => $this->parseDate(
                    (string) ($row['Data'] ?? '')
                ),

                'description' => $description,

                'amount' => $amount,

                'type' => $amount < 0
                    ? 'expense'
                    : 'income',

                'source_type' => 'manual_import',

                'counterparty_name' => $parsed['counterparty_name'],

                'counterparty_document' => $parsed['counterparty_document'],

                'counterparty_contact_type' => $parsed['counterparty_contact_type'],

                'transaction_method' => $parsed['transaction_method'],
            ];
        }
    }

    /**
     * Extrai nome, documento, tipo do contato e método da transação.
     */
    public function parseDescription(string $description): array
    {
        $description = trim($description);

        $data = [
            'counterparty_name' => null,
            'counterparty_document' => null,
            'counterparty_contact_type' => null,
            'transaction_method' => 'other',
        ];

        $lower = mb_strtolower($description, 'UTF-8');

        if (str_contains($lower, 'pix')) {
            $data['transaction_method'] = 'pix';
        } elseif (str_contains($lower, 'ted')) {
            $data['transaction_method'] = 'ted';
        } elseif (str_contains($lower, 'boleto')) {
            $data['transaction_method'] = 'boleto';
        } elseif (
            str_contains($lower, 'cartão') ||
            str_contains($lower, 'cartao')
        ) {
            $data['transaction_method'] = 'card';
        }

        $parts = array_map(
            'trim',
            explode(' - ', $description)
        );

        if (isset($parts[1]) && $parts[1] !== '') {
            $data['counterparty_name'] = $parts[1];
        }

        if (isset($parts[2]) && $parts[2] !== '') {
            $normalizedDocument = $this->normalizeDocument($parts[2]);

            $data['counterparty_document'] = $normalizedDocument;

            if (strlen($normalizedDocument) === 11) {
                $data['counterparty_contact_type'] = 'individual';
            } elseif (strlen($normalizedDocument) === 14) {
                $data['counterparty_contact_type'] = 'company';
            }
        }

        return $data;
    }

    /**
     * Converte documento mascarado/formatado para o formato interno.
     *
     * Ex:
     * •••.349.203-•• => xxx349203xx
     * 11.289.988/0001-77 => 11289988000177
     */
    private function normalizeDocument(string $document): string
    {
        $document = mb_strtolower(
            str_replace('•', 'x', trim($document)),
            'UTF-8'
        );

        return preg_replace('/[^a-z0-9]/', '', $document) ?? '';
    }

    /**
     * Converte valor textual para float.
     *
     * Suporta:
     * -66.00
     * -66,00
     * 1.500,00
     */
    private function parseAmount(string $amount): float
    {
        $amount = trim($amount);

        if (str_contains($amount, ',')) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        }

        return (float) $amount;
    }

    /**
     * Converte DD/MM/AAAA para AAAA-MM-DD.
     */
    private function parseDate(string $date): string
    {
        $date = trim($date);

        $parts = explode('/', $date);

        if (count($parts) !== 3) {
            return $date;
        }

        [$day, $month, $year] = $parts;

        return "{$year}-{$month}-{$day}";
    }
}