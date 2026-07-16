<?php

namespace App\Services\BankParsers;

use Illuminate\Support\Facades\Log;

class InterParser implements BankParserInterface
{
    protected string $arquivoCidades;

    protected static ?array $cidadesValidasCache = null;
    protected static ?array $mapaPrefixosCache = null;

    public function __construct()
    {
        $this->arquivoCidades = storage_path('app/cidades.txt');
    }

    /**
     * @param resource $stream
     */
    public function parse($stream): iterable
    {
        $accountNumber = 'unknown';
        $header = null;

        while (($row = fgetcsv($stream, 0, ';')) !== false) {
            $row = array_map('trim', $row);

            if (empty(array_filter($row))) {
                continue;
            }

            $lineText = implode(';', $row);

            if (stripos($lineText, 'Conta ;') === 0 || stripos($row[0] ?? '', 'Conta') === 0) {
                $accountNumber = trim($row[1] ?? 'unknown');
                continue;
            }

            if (preg_match('/(data lançamento|data lancamento|historico|histórico)/i', $lineText)) {
                $header = $this->normalizeHeader($row);
                break;
            }
        }

        if (!$header) {
            Log::error('Não foi possível localizar o cabeçalho válido no CSV do Inter.');
            return;
        }

        $this->inicializarComponentesPesados();

        Log::info('InterParser iniciado.', [
            'header' => $header,
            'account' => $accountNumber,
        ]);

        while (($rowValues = fgetcsv($stream, 0, ';')) !== false) {
            $rowValues = array_map('trim', $rowValues);

            if (empty(array_filter($rowValues))) {
                continue;
            }

            if (count($rowValues) > count($header) && end($rowValues) === '') {
                array_pop($rowValues);
            }

            if (count($header) !== count($rowValues)) {
                if (count($header) === 5 && count($rowValues) === 4) {
                    $rowValues[] = '';
                } else {
                    Log::warning('InterParser ignorou linha com quantidade inválida de colunas.', [
                        'header_count' => count($header),
                        'row_count' => count($rowValues),
                        'row' => $rowValues,
                    ]);

                    continue;
                }
            }

            $row = array_combine($header, $rowValues);

            if ($row === false) {
                continue;
            }

            $description = trim((string) ($row['Descrição'] ?? ''));
            $rawCategory = trim((string) ($row['Categoria_Bruta'] ?? ''));

            if ($description === '' || preg_match('/(saldo|saldos|saldo atual)/i', $description)) {
                continue;
            }

            $descriptionData = $this->parseDescription($description, $rawCategory);

            $amount = $this->parseAmount((string) ($row['Valor'] ?? '0'));

            $rawLineForHash = implode('|', $rowValues);

            yield [
                'transaction_code' => hash(
                    'sha256',
                    $accountNumber . '|' . $rawLineForHash
                ),

                'transaction_date' => $this->parseDate(
                    (string) ($row['Data'] ?? '')
                ),

                'description' => $description,

                'amount' => $amount,

                'type' => $amount < 0
                    ? 'expense'
                    : 'income',

                'source_type' => 'manual_import',

                'counterparty_name' => $descriptionData['counterparty_name'],

                'counterparty_document' => $descriptionData['counterparty_document'],

                'counterparty_contact_type' => $descriptionData['counterparty_contact_type'],

                'transaction_method' => $descriptionData['transaction_method'],
            ];
        }
    }

    public function parseDescription(string $description, string $historico = ''): array
    {
        $description = trim($description);
        $historico = trim($historico);

        $text = trim($historico . ' ' . $description);

        $data = [
            'counterparty_name' => null,
            'counterparty_document' => null,
            'counterparty_contact_type' => null,
            'transaction_method' => 'other',
        ];

        if (preg_match('/(compra no debito|débito|debito)/i', $text)) {
            $data['transaction_method'] = 'debit_card';
        } elseif (preg_match('/(compra no credito|crédito|credito)/i', $text)) {
            $data['transaction_method'] = 'credit_card';
        } elseif (preg_match('/(cartão|cartao)/i', $text)) {
            $data['transaction_method'] = 'card';
        } elseif (preg_match('/\bpix\b/i', $text)) {
            $data['transaction_method'] = 'pix';
        } elseif (preg_match('/\bted\b/i', $text)) {
            $data['transaction_method'] = 'ted';
        } elseif (preg_match('/\bdoc\b/i', $text)) {
            $data['transaction_method'] = 'doc';
        } elseif (preg_match('/boleto/i', $text)) {
            $data['transaction_method'] = 'boleto';
        }

        $cleanText = preg_replace(
            '/^(compra no debito|compra no credito|pix enviado|pix recebido|transferencia enviada|transferencia recebida)\s*-\s*/i',
            '',
            $description
        );

        $cleanText = preg_replace('/\b(debitado|creditado)\b/i', '', $cleanText);
        $cleanText = trim((string) $cleanText);

        $data['counterparty_name'] = $this->extrairNomeContatoComLista($cleanText);

        return $data;
    }

    private function normalizeHeader(array $headerRaw): array
    {
        return array_map(function ($column) {
            $column = trim((string) $column);

            if (preg_match('/data/i', $column)) {
                return 'Data';
            }

            if (preg_match('/(historico|histórico)/i', $column)) {
                return 'Categoria_Bruta';
            }

            if (preg_match('/(descricao|descrição)/i', $column)) {
                return 'Descrição';
            }

            if (preg_match('/valor/i', $column)) {
                return 'Valor';
            }

            return $column;
        }, $headerRaw);
    }

    private function parseAmount(string $amount): float
    {
        $amount = trim($amount);

        $amount = str_replace('.', '', $amount);
        $amount = str_replace(',', '.', $amount);

        return (float) $amount;
    }

    private function inicializarComponentesPesados(): void
    {
        if (self::$cidadesValidasCache !== null && self::$mapaPrefixosCache !== null) {
            return;
        }

        $this->garantirListaCidadesLocal();

        if (!file_exists($this->arquivoCidades)) {
            self::$cidadesValidasCache = [];
            self::$mapaPrefixosCache = [];
            return;
        }

        $linhas = file($this->arquivoCidades, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $cidades = [];

        foreach ($linhas as $linha) {
            $cidades[trim($linha)] = true;
        }

        self::$cidadesValidasCache = $cidades;

        $listaCidades = array_keys($cidades);

        usort($listaCidades, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        $mapa = [];

        foreach ($listaCidades as $cidade) {
            if (strlen($cidade) >= 5) {
                $prefixo = mb_substr($cidade, 0, 5);
                $mapa[$prefixo][] = $cidade;
            }
        }

        self::$mapaPrefixosCache = $mapa;
    }

    private function extrairNomeContatoComLista(string $texto): string
    {
        $texto = str_replace("\t", '   ', $texto);
        $texto = str_replace(["\xA0", '&nbsp;'], ' ', $texto);
        $texto = preg_replace('/\s{2,}/', ' ', $texto);
        $texto = trim((string) $texto);

        $texto = preg_replace('/^(pag|mp|picpay|stone|sumup|rede|cielo)\*?/i', '', $texto);
        $texto = preg_replace('/\b(BRA|BR)$/i', '', (string) $texto);
        $texto = trim((string) $texto);

        $textoTratado = $this->removerAcentos(
            mb_strtoupper($texto, 'UTF-8')
        );

        $palavrasOriginais = explode(' ', $texto);
        $palavrasTratadas = explode(' ', $textoTratado);

        $qtdPalavras = count($palavrasTratadas);

        if ($qtdPalavras > 1) {
            $removeuLocalizacao = false;

            if ($qtdPalavras >= 2) {
                $duasUltimas = $palavrasTratadas[$qtdPalavras - 2] . ' ' . $palavrasTratadas[$qtdPalavras - 1];
                $prefixoAlvo = mb_substr($duasUltimas, 0, 5);

                if (isset(self::$mapaPrefixosCache[$prefixoAlvo])) {
                    foreach (self::$mapaPrefixosCache[$prefixoAlvo] as $cidadeCandidata) {
                        if (str_starts_with($cidadeCandidata, $duasUltimas)) {
                            unset($palavrasOriginais[$qtdPalavras - 1]);
                            unset($palavrasOriginais[$qtdPalavras - 2]);
                            $removeuLocalizacao = true;
                            break;
                        }
                    }
                }
            }

            if (!$removeuLocalizacao) {
                $ultimaPalavra = $palavrasTratadas[$qtdPalavras - 1];

                if (isset(self::$cidadesValidasCache[$ultimaPalavra])) {
                    unset($palavrasOriginais[$qtdPalavras - 1]);
                } elseif (strlen($ultimaPalavra) >= 5) {
                    $prefixoAlvo = mb_substr($ultimaPalavra, 0, 5);

                    if (isset(self::$mapaPrefixosCache[$prefixoAlvo])) {
                        foreach (self::$mapaPrefixosCache[$prefixoAlvo] as $cidadeCandidata) {
                            if (str_starts_with($cidadeCandidata, $ultimaPalavra)) {
                                unset($palavrasOriginais[$qtdPalavras - 1]);
                                break;
                            }
                        }
                    }
                }
            }

            $texto = implode(' ', $palavrasOriginais);
        }

        return trim($texto, " \t\n\r\0\x0B*-,.");
    }

    private function removerAcentos(string $string): string
    {
        return preg_replace(
            [
                '/[ÁÀÂÃÄáàâãä]/u',
                '/[ÉÈÊËéèêë]/u',
                '/[ÍÌÎÏíìîï]/u',
                '/[ÓÒÔÕÖóòôõö]/u',
                '/[ÚÙÛÜúùûü]/u',
                '/[Çç]/u',
            ],
            ['A', 'E', 'I', 'O', 'U', 'C'],
            $string
        );
    }

    private function garantirListaCidadesLocal(): void
    {
        if (file_exists($this->arquivoCidades)) {
            return;
        }

        try {
            $url = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            $response = curl_exec($ch);

            curl_close($ch);

            if (!$response) {
                return;
            }

            $municipios = json_decode($response, true);

            if (!is_array($municipios)) {
                return;
            }

            $cidadesLimpas = [];

            foreach ($municipios as $municipio) {
                if (!isset($municipio['nome'])) {
                    continue;
                }

                $nomeCidade = mb_strtoupper($municipio['nome'], 'UTF-8');
                $nomeCidade = $this->removerAcentos($nomeCidade);
                $nomeCidade = preg_replace('/[^A-Z0-9 ]/', ' ', $nomeCidade);
                $nomeCidade = preg_replace('/\s{2,}/', ' ', (string) $nomeCidade);

                $cidadesLimpas[] = trim((string) $nomeCidade);
            }

            $cidadesLimpas = array_unique($cidadesLimpas);
            sort($cidadesLimpas);

            file_put_contents(
                $this->arquivoCidades,
                implode("\n", $cidadesLimpas)
            );
        } catch (\Throwable $e) {
            Log::error('Erro ao gerar base de municípios do IBGE: ' . $e->getMessage());
        }
    }

    private function parseDate(string $date): string
    {
        $parts = array_map(
            'trim',
            explode('/', trim($date))
        );

        if (count($parts) !== 3) {
            return $date;
        }

        [$day, $month, $year] = $parts;

        if (strlen($year) === 2) {
            $year = '20' . $year;
        }

        return "{$year}-{$month}-{$day}";
    }
}