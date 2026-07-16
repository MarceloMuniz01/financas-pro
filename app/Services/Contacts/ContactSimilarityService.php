<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Collection;

class ContactSimilarityService
{
    private const MINIMUM_SIMILARITY = 85.0;

    private const SAME_DOCUMENT_MINIMUM_SIMILARITY = 70.0;

    private const MINIMUM_PREFIX_LENGTH = 8;

    /**
     * Reanalisa todos os contatos do usuário.
     */
    public function detectForUser(int $userId): void
    {
        $contacts = Contact::query()
            ->where('user_id', $userId)
            ->get();

        if ($contacts->count() < 2) {
            return;
        }

        /*
         * Apenas contatos que não apontam para outro contato
         * podem ser considerados destinos principais.
         */
        $candidates = $contacts
            ->filter(
                fn(Contact $contact): bool =>
                $contact->looks_like_contact_id === null
            )
            ->values();

        foreach ($contacts as $contact) {
            /*
             * Se o usuário já ignorou uma sugestão para este contato,
             * não recriamos automaticamente.
             */
            if ($contact->similarity_dismissed_at !== null) {
                continue;
            }

            $this->detectForContact(
                contact: $contact,
                candidates: $candidates
            );
        }
    }

    /**
     * Analisa um contato específico.
     */
    public function detectForContact(
        Contact $contact,
        ?Collection $candidates = null
    ): ?Contact {
        if ($contact->similarity_dismissed_at !== null) {
            return null;
        }

        $candidates ??= Contact::query()
            ->where('user_id', $contact->user_id)
            ->whereNull('looks_like_contact_id')
            ->get();

        $sourceName = ContactNameNormalizer::normalize(
            $contact->name
        );

        if (
            mb_strlen($sourceName, 'UTF-8')
            < self::MINIMUM_PREFIX_LENGTH
        ) {
            $this->clearSuggestion($contact);

            return null;
        }

        $sourceCompleteness = $this->completenessScore(
            $contact
        );

        $bestCandidate = null;
        $bestScore = PHP_INT_MIN;

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Contact) {
                continue;
            }

            if ($candidate->id === $contact->id) {
                continue;
            }

            if ($candidate->user_id !== $contact->user_id) {
                continue;
            }

            /*
             * Evita criar ciclo:
             *
             * A aponta para B
             * B aponta para A
             */
            if (
                $candidate->looks_like_contact_id
                === $contact->id
            ) {
                continue;
            }

            if (
                $this->contactTypesConflict(
                    $contact,
                    $candidate
                )
            ) {
                continue;
            }

            if (
                $this->completeDocumentsConflict(
                    $contact,
                    $candidate
                )
            ) {
                continue;
            }

            $candidateCompleteness =
                $this->completenessScore(
                    $candidate
                );

            /*
             * O contato sugerido precisa ser mais completo.
             */
            if (
                $candidateCompleteness
                <= $sourceCompleteness
            ) {
                continue;
            }

            $candidateName =
                ContactNameNormalizer::normalize(
                    $candidate->name
                );

            if ($candidateName === '') {
                continue;
            }

            /*
             * Evita comparar nomes com diferença excessiva
             * de tamanho.
             */
            if (
                abs(
                    mb_strlen($sourceName, 'UTF-8')
                    -
                    mb_strlen(
                        $candidateName,
                        'UTF-8'
                    )
                ) > 12
            ) {
                continue;
            }

            $similarity =
                $this->calculateSimilarity(
                    $sourceName,
                    $candidateName
                );

            $sameDocument =
                $this->sameDocument(
                    $contact,
                    $candidate
                );

            $prefixMatch =
                $this->isPrefixMatch(
                    $sourceName,
                    $candidateName
                );

            $exactNormalizedName =
                $sourceName === $candidateName;

            $isMatch =
                $exactNormalizedName
                || $prefixMatch
                || $similarity
                >= self::MINIMUM_SIMILARITY
                || (
                    $sameDocument
                    && $similarity
                    >= self::SAME_DOCUMENT_MINIMUM_SIMILARITY
                );

            if (!$isMatch) {
                continue;
            }

            /*
             * Pontuação usada para escolher o melhor candidato.
             */
            $score =
                (int) round($similarity * 10)
                + $candidateCompleteness;

            if ($sameDocument) {
                $score += 1000;
            }

            if ($prefixMatch) {
                $score += 500;
            }

            if ($exactNormalizedName) {
                $score += 1500;
            }

            if ($score > $bestScore) {
                $bestCandidate = $candidate;
                $bestScore = $score;
            }
        }

        if (!$bestCandidate) {
            $this->clearSuggestion($contact);

            return null;
        }

        $contact->update([
            'looks_like_contact_id' =>
                $bestCandidate->id,
        ]);

        return $bestCandidate;
    }

    /**
     * Limpa uma sugestão antiga quando ela não é mais válida.
     */
    private function clearSuggestion(
        Contact $contact
    ): void {
        if ($contact->looks_like_contact_id === null) {
            return;
        }

        $contact->update([
            'looks_like_contact_id' => null,
        ]);
    }

    /**
     * Mede o quanto um contato está completo.
     */
    private function completenessScore(
        Contact $contact
    ): int {
        $score = min(
            mb_strlen(
                trim($contact->name),
                'UTF-8'
            ),
            60
        );

        if ($this->hasAnyDocument($contact)) {
            $score += 20;
        }

        if ($this->hasCompleteDocument($contact)) {
            $score += 50;
        }

        if ($contact->contact_type !== null) {
            $score += 25;
        }

        if (
            $contact->default_expense_category_id
            !== null
        ) {
            $score += 10;
        }

        if (
            $contact->default_income_category_id
            !== null
        ) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Calcula similaridade percentual por Levenshtein.
     */
    private function calculateSimilarity(
        string $first,
        string $second
    ): float {
        if ($first === $second) {
            return 100.0;
        }

        $maximumLength = max(
            strlen($first),
            strlen($second)
        );

        if ($maximumLength === 0) {
            return 100.0;
        }

        $distance = levenshtein(
            $first,
            $second
        );

        return max(
            0,
            (
                1 - ($distance / $maximumLength)
            ) * 100
        );
    }

    /**
     * Detecta quando um nome é continuação do outro.
     */
    private function isPrefixMatch(
        string $first,
        string $second
    ): bool {
        $firstLength = mb_strlen(
            $first,
            'UTF-8'
        );

        $secondLength = mb_strlen(
            $second,
            'UTF-8'
        );

        $shortest = $firstLength <= $secondLength
            ? $first
            : $second;

        $longest = $shortest === $first
            ? $second
            : $first;

        if (
            mb_strlen($shortest, 'UTF-8')
            < self::MINIMUM_PREFIX_LENGTH
        ) {
            return false;
        }

        return str_starts_with(
            $longest,
            $shortest
        );
    }

    /**
     * Normaliza documentos completos ou censurados.
     */
    private function normalizeDocument(
        ?string $document
    ): ?string {
        if (!$document) {
            return null;
        }

        $document = mb_strtolower(
            str_replace(
                '•',
                'x',
                trim($document)
            ),
            'UTF-8'
        );

        $document = preg_replace(
            '/[^a-z0-9]/',
            '',
            $document
        );

        return $document !== ''
            ? $document
            : null;
    }

    private function sameDocument(
        Contact $first,
        Contact $second
    ): bool {
        $firstDocument =
            $this->normalizeDocument(
                $first->document
            );

        $secondDocument =
            $this->normalizeDocument(
                $second->document
            );

        if (
            $firstDocument === null
            || $secondDocument === null
        ) {
            return false;
        }

        return $firstDocument === $secondDocument;
    }

    private function hasAnyDocument(
        Contact $contact
    ): bool {
        return $this->normalizeDocument(
            $contact->document
        ) !== null;
    }

    private function hasCompleteDocument(
        Contact $contact
    ): bool {
        if (!$contact->document) {
            return false;
        }

        return preg_match(
            '/^(\d{11}|\d{14})$/',
            $contact->document
        ) === 1;
    }

    /**
     * Bloqueia contatos com documentos completos diferentes.
     */
    private function completeDocumentsConflict(
        Contact $first,
        Contact $second
    ): bool {
        if (
            !$this->hasCompleteDocument($first)
            || !$this->hasCompleteDocument($second)
        ) {
            return false;
        }

        return $first->document !== $second->document;
    }

    /**
     * Bloqueia empresa versus pessoa quando ambos
     * já possuem tipo definido.
     */
    private function contactTypesConflict(
        Contact $first,
        Contact $second
    ): bool {
        if (
            $first->contact_type === null
            || $second->contact_type === null
        ) {
            return false;
        }

        return $first->contact_type
            !== $second->contact_type;
    }
}