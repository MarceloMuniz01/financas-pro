<?php

namespace App\Services\Contacts;

class ContactNameNormalizer
{
    /**
     * Normaliza um nome para comparação e busca por alias.
     *
     * Exemplos:
     *
     * "Maria dos Navegantes"
     * "maria-dos-navegantes"
     * "MARIA DOS NAVEGANTES"
     *
     * viram:
     *
     * "MARIADOSNAVEGANTES"
     */
    public static function normalize(
        ?string $name
    ): string {
        if ($name === null) {
            return '';
        }

        $name = mb_strtoupper(
            trim($name),
            'UTF-8'
        );

        if ($name === '') {
            return '';
        }

        $name = str_replace(
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
            $name
        );

        /*
         * Mantém apenas letras e números.
         *
         * Remove:
         *
         * espaços;
         * hífens;
         * pontos;
         * barras;
         * símbolos;
         * pontuação.
         */
        $normalized = preg_replace(
            '/[^A-Z0-9]/',
            '',
            $name
        );

        return $normalized ?? '';
    }

    /**
     * Verifica se dois nomes representam exatamente
     * a mesma chave normalizada.
     */
    public static function equals(
        ?string $first,
        ?string $second
    ): bool {
        $firstNormalized = self::normalize(
            $first
        );

        $secondNormalized = self::normalize(
            $second
        );

        if (
            $firstNormalized === ''
            || $secondNormalized === ''
        ) {
            return false;
        }

        return $firstNormalized
            === $secondNormalized;
    }
}
