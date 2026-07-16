<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactMergeService
{
    /**
     * Mescla o contato de origem no contato de destino.
     *
     * O contato de origem será removido.
     * O contato de destino permanecerá.
     */
    public function merge(
        int $userId,
        int $sourceContactId,
        int $targetContactId
    ): Contact {
        if ($sourceContactId === $targetContactId) {
            throw ValidationException::withMessages([
                'contacts' =>
                    'Não é possível mesclar um contato nele mesmo.',
            ]);
        }

        return DB::transaction(function () use ($userId, $sourceContactId, $targetContactId): Contact {
            /*
             * Bloqueia os dois registros durante a mesclagem.
             *
             * A ordenação por ID ajuda a manter sempre a mesma
             * ordem de bloqueio e reduz risco de deadlock.
             */
            $contacts = Contact::query()
                ->where('user_id', $userId)
                ->whereIn('id', [
                    $sourceContactId,
                    $targetContactId,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $contacts->get(
                $sourceContactId
            );

            $target = $contacts->get(
                $targetContactId
            );

            if (!$source || !$target) {
                throw ValidationException::withMessages([
                    'contacts' =>
                        'Um dos contatos selecionados não existe ou não pertence ao usuário.',
                ]);
            }

            $this->ensureContactsCanBeMerged(
                source: $source,
                target: $target
            );

            /*
            |--------------------------------------------------------------------------
            | 1. Herdar dados ausentes
            |--------------------------------------------------------------------------
            |
            | O destino mantém seus próprios dados.
            | Apenas campos vazios são preenchidos a partir da origem.
            |
            */

            $target->update([
                'document' =>
                    $target->document
                    ?: $source->document,

                'contact_type' =>
                    $target->contact_type
                    ?: $source->contact_type,

                'default_expense_category_id' =>
                    $target->default_expense_category_id
                    ?: $source->default_expense_category_id,

                'default_income_category_id' =>
                    $target->default_income_category_id
                    ?: $source->default_income_category_id,

                'looks_like_contact_id' => null,
                'similarity_dismissed_at' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 2. Mover todas as transações
            |--------------------------------------------------------------------------
            */

            Transaction::query()
                ->where('user_id', $userId)
                ->where(
                    'contact_id',
                    $source->id
                )
                ->update([
                    'contact_id' => $target->id,
                    'updated_at' => now(),
                ]);

            /*
            |--------------------------------------------------------------------------
            | 3. Transformar o nome antigo em alias
            |--------------------------------------------------------------------------
            */

            $this->createAliasFromName(
                userId: $userId,
                contactId: $target->id,
                name: $source->name
            );

            /*
            |--------------------------------------------------------------------------
            | 4. Mover aliases antigos da origem
            |--------------------------------------------------------------------------
            */

            $sourceAliases = ContactAlias::query()
                ->where('user_id', $userId)
                ->where(
                    'contact_id',
                    $source->id
                )
                ->lockForUpdate()
                ->get();

            foreach ($sourceAliases as $alias) {
                $existingAlias = ContactAlias::query()
                    ->where('user_id', $userId)
                    ->where(
                        'normalized_name',
                        $alias->normalized_name
                    )
                    ->first();

                if ($existingAlias) {
                    /*
                     * Caso o alias já exista no destino,
                     * apenas remove a duplicata da origem.
                     */
                    if (
                        $existingAlias->contact_id
                        === $target->id
                    ) {
                        $alias->delete();

                        continue;
                    }

                    /*
                     * Um mesmo alias não pode apontar para
                     * contatos diferentes.
                     */
                    throw ValidationException::withMessages([
                        'aliases' =>
                            "O apelido \"{$alias->name}\" já pertence a outro contato.",
                    ]);
                }

                $alias->update([
                    'contact_id' => $target->id,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Redirecionar sugestões que apontavam para a origem
            |--------------------------------------------------------------------------
            */

            Contact::query()
                ->where('user_id', $userId)
                ->where(
                    'looks_like_contact_id',
                    $source->id
                )
                ->update([
                    'looks_like_contact_id' =>
                        $target->id,

                    'updated_at' => now(),
                ]);

            /*
             * Remove qualquer sugestão do destino para a origem.
             */
            if (
                $target->looks_like_contact_id
                === $source->id
            ) {
                $target->update([
                    'looks_like_contact_id' => null,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Remover alias conflitante com o nome oficial do destino
            |--------------------------------------------------------------------------
            |
            | O nome oficial não precisa também existir como alias.
            |
            */

            $targetNormalizedName =
                ContactNameNormalizer::normalize(
                    $target->name
                );

            ContactAlias::query()
                ->where('user_id', $userId)
                ->where(
                    'contact_id',
                    $target->id
                )
                ->where(
                    'normalized_name',
                    $targetNormalizedName
                )
                ->delete();

            /*
            |--------------------------------------------------------------------------
            | 7. Excluir o contato de origem
            |--------------------------------------------------------------------------
            */

            $source->delete();

            return $target->fresh([
                'aliases',
                'defaultExpenseCategory',
                'defaultIncomeCategory',
            ]);
        });
    }

    /**
     * Verifica conflitos que tornam a mesclagem insegura.
     */
    private function ensureContactsCanBeMerged(
        Contact $source,
        Contact $target
    ): void {
        if (
            $source->contact_type !== null
            && $target->contact_type !== null
            && $source->contact_type
            !== $target->contact_type
        ) {
            throw ValidationException::withMessages([
                'contact_type' =>
                    'Não é possível mesclar uma pessoa com uma empresa.',
            ]);
        }

        if (
            $this->hasCompleteDocument($source)
            && $this->hasCompleteDocument($target)
            && $source->document
            !== $target->document
        ) {
            throw ValidationException::withMessages([
                'document' =>
                    'Não é possível mesclar contatos com documentos completos diferentes.',
            ]);
        }
    }

    /**
     * Cria um alias a partir do nome antigo.
     */
    private function createAliasFromName(
        int $userId,
        int $contactId,
        string $name
    ): void {
        $normalizedName =
            ContactNameNormalizer::normalize(
                $name
            );

        if ($normalizedName === '') {
            return;
        }

        /*
         * O nome oficial do destino não precisa virar alias.
         */
        $target = Contact::query()
            ->where('user_id', $userId)
            ->where('id', $contactId)
            ->firstOrFail();

        if (
            ContactNameNormalizer::equals(
                $target->name,
                $name
            )
        ) {
            return;
        }

        $existingAlias = ContactAlias::query()
            ->where('user_id', $userId)
            ->where(
                'normalized_name',
                $normalizedName
            )
            ->first();

        if ($existingAlias) {
            if (
                $existingAlias->contact_id
                === $contactId
            ) {
                return;
            }

            throw ValidationException::withMessages([
                'aliases' =>
                    "O apelido \"{$name}\" já pertence a outro contato.",
            ]);
        }

        ContactAlias::create([
            'user_id' => $userId,
            'contact_id' => $contactId,
            'name' => trim($name),
            'normalized_name' =>
                $normalizedName,
        ]);
    }

    /**
     * Verifica se o contato possui CPF ou CNPJ completo.
     */
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
}