<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactMergeService
{
    /**
     * Mantém compatibilidade com a mesclagem de dois contatos.
     */
    public function merge(
        int $userId,
        int $sourceContactId,
        int $targetContactId
    ): Contact {
        return $this->mergeMany(
            userId: $userId,
            contactIds: [
                $sourceContactId,
                $targetContactId,
            ],
            targetContactId: $targetContactId
        );
    }

    /**
     * Mescla dois ou mais contatos.
     *
     * Nenhum contato é excluído.
     * Nenhuma transação é movida.
     * Nenhum alias é transferido.
     * Nenhuma categoria é alterada.
     *
     * Os contatos secundários passam apenas a apontar para
     * o contato principal por merged_into_contact_id.
     *
     * @param array<int, int|string> $contactIds
     */
    public function mergeMany(
        int $userId,
        array $contactIds,
        int $targetContactId
    ): Contact {
        $contactIds = $this->normalizeContactIds(
            $contactIds
        );

        if (count($contactIds) < 2) {
            throw ValidationException::withMessages([
                'contact_ids' =>
                    'Selecione pelo menos dois contatos para mesclar.',
            ]);
        }

        if (
            !in_array(
                $targetContactId,
                $contactIds,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'target_contact_id' =>
                    'O contato principal deve estar entre os contatos selecionados.',
            ]);
        }

        return DB::transaction(
            function () use ($userId, $contactIds, $targetContactId): Contact {
                /*
                 * Bloqueia os contatos selecionados para evitar que duas
                 * mesclagens simultâneas alterem os mesmos registros.
                 */
                $contacts = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->whereIn(
                        'id',
                        $contactIds
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (
                    $contacts->count()
                    !== count($contactIds)
                ) {
                    throw ValidationException::withMessages([
                        'contact_ids' =>
                            'Um ou mais contatos não existem ou não pertencem ao usuário.',
                    ]);
                }

                /** @var Contact|null $targetContact */
                $targetContact = $contacts->get(
                    $targetContactId
                );

                if (!$targetContact) {
                    throw ValidationException::withMessages([
                        'target_contact_id' =>
                            'O contato principal não foi encontrado.',
                    ]);
                }

                /*
                 * O destino precisa ser um contato principal.
                 *
                 * Isso evita estruturas como:
                 *
                 * C -> B -> A
                 *
                 * A estrutura permitida é somente:
                 *
                 * B -> A
                 * C -> A
                 */
                if (
                    $targetContact->merged_into_contact_id
                    !== null
                ) {
                    throw ValidationException::withMessages([
                        'target_contact_id' =>
                            'O contato escolhido já está vinculado a outro contato. Escolha o contato principal.',
                    ]);
                }

                $sourceContactIds = array_values(
                    array_filter(
                        $contactIds,
                        static fn(int $contactId): bool =>
                        $contactId
                        !== $targetContactId
                    )
                );

                /*
                 * Verifica se algum contato selecionado é o principal
                 * atual do contato escolhido como destino.
                 *
                 * Na prática, essa situação não deveria acontecer porque
                 * o destino já foi validado como principal, mas a proteção
                 * também ajuda a impedir ciclos em dados inconsistentes.
                 */
                $wouldCreateCycle = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->where(
                        'merged_into_contact_id',
                        $targetContactId
                    )
                    ->whereIn(
                        'id',
                        $sourceContactIds
                    )
                    ->whereKey(
                        $targetContactId
                    )
                    ->exists();

                if ($wouldCreateCycle) {
                    throw ValidationException::withMessages([
                        'contact_ids' =>
                            'A mesclagem criaria um vínculo circular entre os contatos.',
                    ]);
                }

                /*
                 * Contatos que já estavam vinculados aos contatos de origem
                 * também devem passar a apontar diretamente para o novo
                 * contato principal.
                 *
                 * Exemplo:
                 *
                 * C -> B
                 *
                 * Ao mesclar B em A:
                 *
                 * B -> A
                 * C -> A
                 */
                $descendantContactIds = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->whereIn(
                        'merged_into_contact_id',
                        $sourceContactIds
                    )
                    ->pluck('id')
                    ->map(
                        static fn(mixed $id): int =>
                        (int) $id
                    )
                    ->all();

                $contactsToLink = array_values(
                    array_unique([
                        ...$sourceContactIds,
                        ...$descendantContactIds,
                    ])
                );

                /*
                 * O contato principal nunca pode ser atualizado como
                 * secundário, mesmo diante de dados inconsistentes.
                 */
                $contactsToLink = array_values(
                    array_filter(
                        $contactsToLink,
                        static fn(int $contactId): bool =>
                        $contactId
                        !== $targetContactId
                    )
                );

                if ($contactsToLink === []) {
                    throw ValidationException::withMessages([
                        'contact_ids' =>
                            'Não existem contatos válidos para vincular.',
                    ]);
                }

                Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->whereIn(
                        'id',
                        $contactsToLink
                    )
                    ->update([
                        'merged_into_contact_id' =>
                            $targetContactId,

                        'merged_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

                /*
                 * O contato mantido continua como principal.
                 *
                 * Suas categorias permanecem inalteradas e serão usadas
                 * por todos os contatos vinculados.
                 */
                $targetContact->forceFill([
                    'merged_into_contact_id' =>
                        null,

                    'merged_at' =>
                        null,
                ])->save();

                return $targetContact
                    ->fresh([
                        'defaultExpenseCategory',
                        'defaultIncomeCategory',
                        'aliases',
                        'mergedContacts',
                    ])
                    ->loadCount([
                        'transactions',
                        'mergedContacts',
                    ]);
            },
            attempts: 3
        );
    }

    /**
     * Desvincula um único contato do seu contato principal.
     *
     * Como as transações, aliases e categorias nunca foram movidos,
     * o contato recupera imediatamente sua identidade original.
     */
    public function unmerge(
        int $userId,
        int $contactId
    ): Contact {
        return DB::transaction(
            function () use ($userId, $contactId): Contact {
                $contact = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->lockForUpdate()
                    ->find(
                        $contactId
                    );

                if (!$contact) {
                    throw ValidationException::withMessages([
                        'contact_id' =>
                            'O contato não foi encontrado.',
                    ]);
                }

                if (
                    $contact->merged_into_contact_id
                    === null
                ) {
                    throw ValidationException::withMessages([
                        'contact_id' =>
                            'Este contato já é um contato principal.',
                    ]);
                }

                $contact->update([
                    'merged_into_contact_id' =>
                        null,

                    'merged_at' =>
                        null,
                ]);

                return $contact->fresh([
                    'defaultExpenseCategory',
                    'defaultIncomeCategory',
                    'aliases',
                ]);
            },
            attempts: 3
        );
    }

    /**
     * Desvincula todos os contatos secundários de um principal.
     *
     * @return Collection<int, Contact>
     */
    public function unmergeAll(
        int $userId,
        int $targetContactId
    ): Collection {
        return DB::transaction(
            function () use ($userId, $targetContactId): Collection {
                $targetContact = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->lockForUpdate()
                    ->find(
                        $targetContactId
                    );

                if (!$targetContact) {
                    throw ValidationException::withMessages([
                        'target_contact_id' =>
                            'O contato principal não foi encontrado.',
                    ]);
                }

                if (
                    $targetContact->merged_into_contact_id
                    !== null
                ) {
                    throw ValidationException::withMessages([
                        'target_contact_id' =>
                            'O contato informado não é um contato principal.',
                    ]);
                }

                $linkedContacts = Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->where(
                        'merged_into_contact_id',
                        $targetContactId
                    )
                    ->lockForUpdate()
                    ->get();

                if ($linkedContacts->isEmpty()) {
                    throw ValidationException::withMessages([
                        'target_contact_id' =>
                            'Este contato não possui contatos vinculados.',
                    ]);
                }

                Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->where(
                        'merged_into_contact_id',
                        $targetContactId
                    )
                    ->update([
                        'merged_into_contact_id' =>
                            null,

                        'merged_at' =>
                            null,

                        'updated_at' =>
                            now(),
                    ]);

                return Contact::query()
                    ->where(
                        'user_id',
                        $userId
                    )
                    ->whereIn(
                        'id',
                        $linkedContacts->pluck('id')
                    )
                    ->with([
                        'defaultExpenseCategory',
                        'defaultIncomeCategory',
                        'aliases',
                    ])
                    ->get();
            },
            attempts: 3
        );
    }

    /**
     * Normaliza e remove IDs inválidos ou repetidos.
     *
     * @param array<int, int|string> $contactIds
     * @return array<int, int>
     */
    private function normalizeContactIds(
        array $contactIds
    ): array {
        return array_values(
            array_unique(
                array_map(
                    static fn(mixed $contactId): int =>
                    (int) $contactId,

                    array_filter(
                        $contactIds,
                        static fn(mixed $contactId): bool =>
                        is_numeric($contactId)
                        && (int) $contactId > 0
                    )
                )
            )
        );
    }
}