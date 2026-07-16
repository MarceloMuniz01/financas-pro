<?php

namespace App\Services\Contacts;

class ContactSimilaritySignature
{
    /**
     * Partículas com pouco valor para comparar nomes.
     */
    private const IGNORED_WORDS = [
        'A',
        'AS',
        'DA',
        'DAS',
        'DE',
        'DO',
        'DOS',
        'E',
    ];

    /**
     * Gera a assinatura principal.
     *
     * Exemplos:
     *
     * Valdivan Santos Almeida → VALDALME
     * Valdivan Santos Alme    → VALDALME
     * Mercado Rural           → MERCRURA
     */
    public static function makeSignature(
        ?string $name
    ): string {
        $words = self::meaningfulWords($name);

        if (empty($words)) {
            return '';
        }

        $firstWord = $words[0];

        $lastWord = $words[
            count($words) - 1
        ];

        /*
         * Em nomes com apenas uma palavra, usamos o começo
         * e uma seção intermediária/final da própria palavra.
         */
        if (count($words) === 1) {
            return self::singleWordSignature(
                $firstWord
            );
        }

        $firstPart = mb_substr(
            $firstWord,
            0,
            4,
            'UTF-8'
        );

        $lastPart = mb_substr(
            $lastWord,
            0,
            4,
            'UTF-8'
        );

        $digits = self::extractTrailingDigits(
            $name
        );

        return mb_substr(
            $firstPart
            . $lastPart
            . $digits,
            0,
            24,
            'UTF-8'
        );
    }

    /**
     * Gera uma chave baseada no começo do nome compacto.
     *
     * Isso ajuda nomes importados sem espaços:
     *
     * mariadosnavegant
     * mariadosnavegante
     */
    public static function makePrefix(
        ?string $name
    ): string {
        $normalized =
            ContactNameNormalizer::normalize(
                $name
            );

        if ($normalized === '') {
            return '';
        }

        return mb_substr(
            $normalized,
            0,
            10,
            'UTF-8'
        );
    }

    /**
     * Retorna todas as chaves necessárias.
     *
     * @return array{
     *     similarity_signature: string,
     *     similarity_prefix: string
     * }
     */
    public static function make(
        ?string $name
    ): array {
        return [
            'similarity_signature' =>
                self::makeSignature($name),

            'similarity_prefix' =>
                self::makePrefix($name),
        ];
    }

    /**
     * Normaliza mantendo a separação entre palavras.
     *
     * @return array<int, string>
     */
    private static function meaningfulWords(
        ?string $name
    ): array {
        if ($name === null) {
            return [];
        }

        $name = mb_strtoupper(
            trim($name),
            'UTF-8'
        );

        if ($name === '') {
            return [];
        }

        $name = self::removeAccents(
            $name
        );

        /*
         * Mantemos espaços para identificar primeiro
         * e último termos.
         */
        $name = preg_replace(
            '/[^A-Z0-9]+/',
            ' ',
            $name
        ) ?? '';

        $name = preg_replace(
            '/\s+/',
            ' ',
            trim($name)
        ) ?? '';

        if ($name === '') {
            return [];
        }

        $words = preg_split(
            '/\s+/',
            $name
        );

        if (!$words) {
            return [];
        }

        $filtered = array_values(
            array_filter(
                $words,
                static function (string $word): bool {
                    if ($word === '') {
                        return false;
                    }

                    return !in_array(
                        $word,
                        self::IGNORED_WORDS,
                        true
                    );
                }
            )
        );

        /*
         * Caso o nome seja composto apenas por palavras
         * ignoradas, usa as palavras originais.
         */
        return !empty($filtered)
            ? $filtered
            : array_values(
                array_filter($words)
            );
    }

    /**
     * Assinatura para nomes sem separação por espaços.
     */
    private static function singleWordSignature(
        string $word
    ): string {
        $length = mb_strlen(
            $word,
            'UTF-8'
        );

        if ($length <= 8) {
            return $word;
        }

        /*
         * Os seis primeiros caracteres permanecem estáveis
         * quando o final do nome veio cortado pela instituição.
         */
        $start = mb_substr(
            $word,
            0,
            6,
            'UTF-8'
        );

        /*
         * Usamos dois caracteres aproximadamente no meio.
         * Evita depender totalmente do final, que pode estar
         * truncado.
         */
        $middlePosition = max(
            0,
            (int) floor($length / 2) - 1
        );

        $middle = mb_substr(
            $word,
            $middlePosition,
            2,
            'UTF-8'
        );

        return $start . $middle;
    }

    /**
     * Ajuda a separar estabelecimentos numerados.
     *
     * Exemplos:
     *
     * Loja Teste 00001
     * Loja Teste 00002
     */
    private static function extractTrailingDigits(
        ?string $name
    ): string {
        if (!$name) {
            return '';
        }

        if (
            !preg_match(
                '/(\d{1,4})\s*$/',
                trim($name),
                $matches
            )
        ) {
            return '';
        }

        return str_pad(
            $matches[1],
            4,
            '0',
            STR_PAD_LEFT
        );
    }

    private static function removeAccents(
        string $value
    ): string {
        return str_replace(
            [
                'Á',
                'À',
                'Â',
                'Ã',
                'Ä',
                'É',
                'È',
                'Ê',
                'Ë',
                'Í',
                'Ì',
                'Î',
                'Ï',
                'Ó',
                'Ò',
                'Ô',
                'Õ',
                'Ö',
                'Ú',
                'Ù',
                'Û',
                'Ü',
                'Ç',
            ],
            [
                'A',
                'A',
                'A',
                'A',
                'A',
                'E',
                'E',
                'E',
                'E',
                'I',
                'I',
                'I',
                'I',
                'O',
                'O',
                'O',
                'O',
                'O',
                'U',
                'U',
                'U',
                'U',
                'C',
            ],
            $value
        );
    }
}