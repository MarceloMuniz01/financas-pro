<?php

namespace App\Services\Categorizers;

use App\Models\Category;
use Illuminate\Support\Collection;

class StringMatchCategorizer
{
    private const EXPENSE_FALLBACK_ID = 1;
    private const INCOME_FALLBACK_ID = 8;

    /**
     * Keywords curtas usam correspondência por palavra completa
     * para evitar falsos positivos.
     *
     * Exemplo:
     * BAR não deve encontrar BARATAO.
     */
    private const STRICT_KEYWORD_MAX_LENGTH = 5;

    private Collection $userCategories;

    private Collection $globalCategories;

    public function __construct(
        private readonly int $userId
    ) {
        /*
         * Categorias personalizadas pelo usuário.
         */
        $this->userCategories = Category::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('keywords')
            ->get();

        /*
         * Categorias globais do sistema.
         */
        $this->globalCategories = Category::query()
            ->whereNull('user_id')
            ->whereNotNull('keywords')
            ->get();
    }

    /**
     * Descobre a categoria da transação usando:
     *
     * 1. Categorias personalizadas do usuário;
     * 2. Categorias globais;
     * 3. Categoria Outros como fallback.
     */
    public function guessCategoryId(
        string $rawName,
        string $transactionType
    ): int {
        $name = $this->normalize($rawName);

        $transactionType = mb_strtolower(
            trim($transactionType),
            'UTF-8'
        );

        if ($name === '' || $name === 'DESCONHECIDO') {
            return $this->fallback($transactionType);
        }

        /*
         * Prioridade 1: categorias do usuário.
         */
        $categoryId = $this->matchCategory(
            name: $name,
            transactionType: $transactionType,
            categories: $this->userCategories
        );

        if ($categoryId !== null) {
            return $categoryId;
        }

        /*
         * Prioridade 2: categorias globais.
         */
        $categoryId = $this->matchCategory(
            name: $name,
            transactionType: $transactionType,
            categories: $this->globalCategories
        );

        if ($categoryId !== null) {
            return $categoryId;
        }

        return $this->fallback($transactionType);
    }

    /**
     * Tenta inferir o tipo do contato usando as keywords globais.
     *
     * Se o nome corresponder a alguma keyword global,
     * considera que provavelmente é uma empresa.
     *
     * Quando não houver correspondência, retorna null,
     * pois ausência de match não prova que seja uma pessoa.
     */
    public function guessContactType(string $rawName): ?string
    {
        $name = $this->normalize($rawName);

        if ($name === '' || $name === 'DESCONHECIDO') {
            return null;
        }

        $expenseMatch = $this->matchCategory(
            name: $name,
            transactionType: 'expense',
            categories: $this->globalCategories
        );

        if ($expenseMatch !== null) {
            return 'company';
        }

        $incomeMatch = $this->matchCategory(
            name: $name,
            transactionType: 'income',
            categories: $this->globalCategories
        );

        if ($incomeMatch !== null) {
            return 'company';
        }

        return null;
    }

    /**
     * Percorre as categorias e retorna o ID da primeira
     * categoria cuja keyword corresponda ao nome.
     */
    private function matchCategory(
        string $name,
        string $transactionType,
        Collection $categories
    ): ?int {
        foreach ($categories as $category) {
            if ($category->type !== $transactionType) {
                continue;
            }

            $keywords = $this->parseKeywords(
                $category->keywords
            );

            foreach ($keywords as $keyword) {
                if ($this->matchesKeyword($name, $keyword)) {
                    return $category->id;
                }
            }
        }

        return null;
    }

    /**
     * Decide qual estratégia de comparação utilizar.
     *
     * Keywords curtas:
     * correspondência por palavra inteira.
     *
     * Keywords maiores:
     * str_contains(), que é mais rápido.
     */
    private function matchesKeyword(
        string $name,
        string $keyword
    ): bool {
        if ($keyword === '') {
            return false;
        }

        if (
            mb_strlen($keyword, 'UTF-8')
            <= self::STRICT_KEYWORD_MAX_LENGTH
        ) {
            return $this->containsCompleteKeyword(
                $name,
                $keyword
            );
        }

        return str_contains($name, $keyword);
    }

    /**
     * Verifica uma keyword como palavra ou expressão isolada.
     *
     * Exemplos:
     *
     * BAR em "BAR CENTRAL"       => true
     * BAR em "BAR-CENTRAL"       => true
     * BAR em "PAGAMENTO BAR"     => true
     * BAR em "BARATAO"           => false
     * BAR em "EMBARQUE"          => false
     */
    private function containsCompleteKeyword(
        string $name,
        string $keyword
    ): bool {
        $escapedKeyword = preg_quote(
            $keyword,
            '/'
        );

        $pattern = '/(?<![\pL\pN])'
            . $escapedKeyword
            . '(?![\pL\pN])/u';

        return preg_match($pattern, $name) === 1;
    }

    /**
     * Transforma a string de keywords em array.
     *
     * Aceita:
     *
     * UBER;POSTO;SHELL
     *
     * ou:
     *
     * UBER,POSTO,SHELL
     */
    private function parseKeywords(?string $keywords): array
    {
        if ($keywords === null || trim($keywords) === '') {
            return [];
        }

        $items = preg_split(
            '/[;,]/',
            $keywords
        );

        if ($items === false) {
            return [];
        }

        $normalizedKeywords = array_map(
            fn (string $keyword): string => $this->normalize($keyword),
            $items
        );

        $normalizedKeywords = array_filter(
            $normalizedKeywords,
            fn (string $keyword): bool => $keyword !== ''
        );

        /*
         * Remove duplicadas causadas pela normalização.
         *
         * Exemplo:
         * AÇOUGUE e ACOUGUE viram ACOUGUE.
         */
        return array_values(
            array_unique($normalizedKeywords)
        );
    }

    /**
     * Retorna a categoria Outros correspondente ao tipo.
     */
    private function fallback(string $transactionType): int
    {
        return $transactionType === 'income'
            ? self::INCOME_FALLBACK_ID
            : self::EXPENSE_FALLBACK_ID;
    }

    /**
     * Normaliza nomes e keywords:
     *
     * - caixa alta;
     * - remove acentos;
     * - remove espaços duplicados;
     * - remove espaços das extremidades.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtoupper(
            trim($value),
            'UTF-8'
        );

        $value = str_replace(
            [
                'Á', 'À', 'Â', 'Ã', 'Ä',
                'É', 'È', 'Ê', 'Ë',
                'Í', 'Ì', 'Î', 'Ï',
                'Ó', 'Ò', 'Ô', 'Õ', 'Ö',
                'Ú', 'Ù', 'Û', 'Ü',
                'Ç',
            ],
            [
                'A', 'A', 'A', 'A', 'A',
                'E', 'E', 'E', 'E',
                'I', 'I', 'I', 'I',
                'O', 'O', 'O', 'O', 'O',
                'U', 'U', 'U', 'U',
                'C',
            ],
            $value
        );

        return preg_replace(
            '/\s+/',
            ' ',
            $value
        ) ?? $value;
    }
}