<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Services\Contacts\ContactMergeService;
use App\Services\Contacts\ContactNameNormalizer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Lista somente os contatos principais do usuário autenticado.
     *
     * Contatos vinculados são apresentados como parte do principal:
     * seus nomes e aliases entram nas variações exibidas e suas
     * transações entram na contagem agregada do grupo.
     */
    public function index(
        Request $request
    ): Response {
        $userId = (int) $request->user()->id;

        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'contact_type' => [
                'nullable',

                Rule::in([
                    'company',
                    'individual',
                    'unknown',
                ]),
            ],
        ]);

        $contacts = Contact::query()
            ->where(
                'user_id',
                $userId
            )
            ->whereNull(
                'merged_into_contact_id'
            )
            ->select([
                'id',
                'user_id',
                'name',
                'normalized_name',
                'document',
                'contact_type',
                'default_expense_category_id',
                'default_income_category_id',
                'merged_into_contact_id',
                'merged_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'defaultExpenseCategory:id,name,type,color',

                'defaultIncomeCategory:id,name,type,color',

                'aliases:id,user_id,contact_id,name,normalized_name',

                'mergedContacts' => function ($query): void {
                    $query
                        ->select([
                            'id',
                            'user_id',
                            'name',
                            'normalized_name',
                            'document',
                            'contact_type',
                            'default_expense_category_id',
                            'default_income_category_id',
                            'merged_into_contact_id',
                            'merged_at',
                            'created_at',
                            'updated_at',
                        ])
                        ->with([
                            'aliases:id,user_id,contact_id,name,normalized_name',
                        ])
                        ->withCount(
                            'transactions'
                        )
                        ->orderBy('name');
                },
            ])
            ->withCount(
                'transactions'
            )
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $search = trim($search);

                    if ($search === '') {
                        return;
                    }

                    $query->where(
                        function ($query) use ($search): void {
                            $query
                                ->where(
                                    'name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'document',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'aliases',
                                    function ($query) use ($search): void {
                                        $query->where(
                                            'name',
                                            'ilike',
                                            "%{$search}%"
                                        );
                                    }
                                )
                                ->orWhereHas(
                                    'mergedContacts',
                                    function ($query) use ($search): void {
                                        $query->where(
                                            function ($query) use ($search): void {
                                                $query
                                                    ->where(
                                                        'name',
                                                        'ilike',
                                                        "%{$search}%"
                                                    )
                                                    ->orWhere(
                                                        'document',
                                                        'ilike',
                                                        "%{$search}%"
                                                    )
                                                    ->orWhereHas(
                                                        'aliases',
                                                        function ($query) use ($search): void {
                                                            $query->where(
                                                                'name',
                                                                'ilike',
                                                                "%{$search}%"
                                                            );
                                                        }
                                                    );
                                            }
                                        );
                                    }
                                );
                        }
                    );
                }
            )
            ->when(
                ($filters['contact_type'] ?? null)
                === 'company',
                fn($query) => $query->where(
                    'contact_type',
                    'company'
                )
            )
            ->when(
                ($filters['contact_type'] ?? null)
                === 'individual',
                fn($query) => $query->where(
                    'contact_type',
                    'individual'
                )
            )
            ->when(
                ($filters['contact_type'] ?? null)
                === 'unknown',
                fn($query) => $query->whereNull(
                    'contact_type'
                )
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $contacts->setCollection(
            $contacts
                ->getCollection()
                ->map(
                    fn(Contact $contact): array =>
                    $this->serializeContactGroup(
                        $contact
                    )
                )
        );

        $categories = Category::query()
            ->where(
                function ($query) use ($userId): void {
                    $query
                        ->whereNull('user_id')
                        ->orWhere(
                            'user_id',
                            $userId
                        );
                }
            )
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'color',
            ]);

        return Inertia::render(
            'contacts/index',
            [
                'contacts' => $contacts,

                'categories' => $categories,

                'filters' => [
                    'search' =>
                        $filters['search']
                        ?? '',

                    'contact_type' =>
                        $filters['contact_type']
                        ?? '',
                ],
            ]
        );
    }

    /**
     * Atualiza os dados editáveis de um contato.
     */
    public function update(
        Request $request,
        Contact $contact
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        $this->ensureContactBelongsToUser(
            contact: $contact,
            userId: $userId
        );

        if ($contact->merged_into_contact_id !== null) {
            throw ValidationException::withMessages([
                'contact' =>
                    'Edite o contato principal deste grupo.',
            ]);
        }

        $request->merge([
            'name' => trim(
                (string) $request->input(
                    'name'
                )
            ),

            'contact_type' =>
                $request->input(
                    'contact_type'
                )
                ?: null,

            'document' =>
                $this->normalizeDocument(
                    $request->input(
                        'document'
                    )
                ),
        ]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',

                function (string $attribute, mixed $value, Closure $fail) use ($userId, $contact): void {
                    $normalizedName =
                        ContactNameNormalizer::normalize(
                            (string) $value
                        );

                    if ($normalizedName === '') {
                        $fail(
                            'Informe um nome válido.'
                        );

                        return;
                    }

                    $groupContactIds = Contact::query()
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->where(
                            function ($query) use ($contact): void {
                                $query
                                    ->whereKey(
                                        $contact->id
                                    )
                                    ->orWhere(
                                        'merged_into_contact_id',
                                        $contact->id
                                    );
                            }
                        )
                        ->pluck('id');

                    $officialNameExists =
                        Contact::query()
                            ->where(
                                'user_id',
                                $userId
                            )
                            ->where(
                                'normalized_name',
                                $normalizedName
                            )
                            ->whereNotIn(
                                'id',
                                $groupContactIds
                            )
                            ->exists();

                    if ($officialNameExists) {
                        $fail(
                            'Já existe um contato com esse nome.'
                        );

                        return;
                    }

                    $aliasExists = $contact
                        ->aliases()
                        ->getModel()
                        ->newQuery()
                        ->where(
                            'user_id',
                            $userId
                        )
                        ->where(
                            'normalized_name',
                            $normalizedName
                        )
                        ->whereNotIn(
                            'contact_id',
                            $groupContactIds
                        )
                        ->exists();

                    if ($aliasExists) {
                        $fail(
                            'Esse nome já está cadastrado como apelido de outro contato.'
                        );
                    }
                },
            ],

            'contact_type' => [
                'nullable',

                Rule::in([
                    'company',
                    'individual',
                ]),
            ],

            'document' => [
                'nullable',
                'string',
                'max:14',

                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    $this->validateDocument(
                        document: $value,

                        contactType:
                        $request->input(
                            'contact_type'
                        ),

                        fail: $fail
                    );
                },

                Rule::unique(
                    'contacts',
                    'document'
                )
                    ->where(
                        fn($query) => $query
                            ->where(
                                'user_id',
                                $userId
                            )
                    )
                    ->ignore(
                        $contact->id
                    ),
            ],

            'default_expense_category_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'categories',
                    'id'
                ),
            ],

            'default_income_category_id' => [
                'nullable',
                'integer',

                Rule::exists(
                    'categories',
                    'id'
                ),
            ],
        ]);

        $expenseCategoryId =
            $this->validateCategory(
                categoryId:
                $validated[
                    'default_expense_category_id'
                ] ?? null,

                categoryType:
                'expense',

                userId:
                $userId,

                field:
                'default_expense_category_id'
            );

        $incomeCategoryId =
            $this->validateCategory(
                categoryId:
                $validated[
                    'default_income_category_id'
                ] ?? null,

                categoryType:
                'income',

                userId:
                $userId,

                field:
                'default_income_category_id'
            );

        $normalizedName =
            ContactNameNormalizer::normalize(
                $validated['name']
            );

        $oldNormalizedName =
            (string) $contact->normalized_name;

        $contact->update([
            'name' =>
                $validated['name'],

            'normalized_name' =>
                $normalizedName,

            'document' =>
                $validated['document']
                ?? null,

            'contact_type' =>
                $validated['contact_type']
                ?? null,

            'default_expense_category_id' =>
                $expenseCategoryId,

            'default_income_category_id' =>
                $incomeCategoryId,
        ]);

        /*
         * O nome oficial atual não deve também existir
         * como apelido desse contato.
         */
        $contact
            ->aliases()
            ->where(
                'normalized_name',
                $normalizedName
            )
            ->delete();

        /*
         * Quando o nome oficial é alterado manualmente,
         * o nome antigo pode continuar reconhecido como alias.
         */
        if (
            $oldNormalizedName !== ''
            && $oldNormalizedName !== $normalizedName
        ) {
            $oldName = trim(
                (string) $contact->getOriginal(
                    'name'
                )
            );

            if ($oldName !== '') {
                $contact->aliases()
                    ->getModel()
                    ->newQuery()
                    ->insertOrIgnore([
                        [
                            'user_id' =>
                                $userId,

                            'contact_id' =>
                                $contact->id,

                            'name' =>
                                $oldName,

                            'normalized_name' =>
                                $oldNormalizedName,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ],
                    ]);
            }
        }

        return back()->with(
            'success',
            'Contato atualizado com sucesso.'
        );
    }

    /**
     * Mescla vários contatos selecionados em um único contato.
     */
    public function mergeMany(
        Request $request,
        ContactMergeService $mergeService
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'contact_ids' => [
                'required',
                'array',
                'min:2',
                'max:100',
            ],

            'contact_ids.*' => [
                'required',
                'integer',
                'distinct',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) => $query
                            ->where(
                                'user_id',
                                $userId
                            )
                    ),
            ],

            'target_contact_id' => [
                'required',
                'integer',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) => $query
                            ->where(
                                'user_id',
                                $userId
                            )
                    ),
            ],
        ]);

        $contactIds = array_values(
            array_unique(
                array_map(
                    'intval',
                    $validated[
                        'contact_ids'
                    ]
                )
            )
        );

        $targetContactId =
            (int) $validated[
                'target_contact_id'
            ];

        if (
            !in_array(
                $targetContactId,
                $contactIds,
                true
            )
        ) {
            throw ValidationException::withMessages([
                'target_contact_id' =>
                    'O contato mantido deve estar entre os contatos selecionados.',
            ]);
        }

        $mergeService->mergeMany(
            userId: $userId,
            contactIds: $contactIds,
            targetContactId: $targetContactId
        );

        return redirect()
            ->route('contacts.index')
            ->with(
                'success',
                count($contactIds)
                === 2
                ? 'Contatos mesclados com sucesso.'
                : count($contactIds)
                . ' contatos foram mesclados com sucesso.'
            );
    }

    /**
     * Desmescla os contatos selecionados de um contato principal.
     */
    public function unmergeMany(
        Request $request,
        ContactMergeService $mergeService
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'main_contact_id' => [
                'required',
                'integer',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) =>
                        $query
                            ->where(
                                'user_id',
                                $userId
                            )
                            ->whereNull(
                                'merged_into_contact_id'
                            )
                    ),
            ],

            'contact_ids' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'contact_ids.*' => [
                'required',
                'integer',
                'distinct',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) =>
                        $query->where(
                            'user_id',
                            $userId
                        )
                    ),
            ],
        ]);

        $mainContactId =
            (int) $validated[
                'main_contact_id'
            ];

        $contactIds = array_values(
            array_unique(
                array_map(
                    'intval',
                    $validated[
                        'contact_ids'
                    ]
                )
            )
        );

        /*
         * Garante que todos os contatos selecionados
         * realmente pertencem ao principal informado.
         */
        $validContactIds = Contact::query()
            ->where(
                'user_id',
                $userId
            )
            ->where(
                'merged_into_contact_id',
                $mainContactId
            )
            ->whereIn(
                'id',
                $contactIds
            )
            ->pluck('id')
            ->map(
                static fn($id): int =>
                (int) $id
            )
            ->all();

        if (
            count($validContactIds)
            !== count($contactIds)
        ) {
            throw ValidationException::withMessages([
                'contacts' =>
                    'Um ou mais contatos selecionados não pertencem a esse grupo.',
            ]);
        }

        foreach (
            $validContactIds
            as $contactId
        ) {
            $mergeService->unmerge(
                userId: $userId,
                contactId: $contactId
            );
        }

        return redirect()
            ->route('contacts.index')
            ->with(
                'success',
                count($validContactIds) === 1
                ? 'Contato desmesclado com sucesso.'
                : count($validContactIds)
                . ' contatos foram desmesclados com sucesso.'
            );
    }

    /**
     * Transforma um contato principal e seus vinculados em um
     * único registro de apresentação para o frontend.
     *
     * @return array<string, mixed>
     */
    private function serializeContactGroup(
        Contact $contact
    ): array {
        $aliases = $contact
            ->aliases
            ->map(
                static fn($alias): array => [
                    'id' => (int) $alias->id,
                    'user_id' => (int) $alias->user_id,
                    'contact_id' => (int) $alias->contact_id,
                    'linked_contact_id' => null,
                    'name' => $alias->name,
                    'normalized_name' => $alias->normalized_name,
                    'source' => 'alias',
                ]
            );

        $transactionsCount =
            (int) $contact->transactions_count;

        foreach (
            $contact->mergedContacts
            as $linkedContact
        ) {
            $transactionsCount +=
                (int) $linkedContact->transactions_count;

            $aliases->push([
                'id' => -((int) $linkedContact->id),
                'user_id' => (int) $linkedContact->user_id,
                'contact_id' => (int) $contact->id,
                'linked_contact_id' => (int) $linkedContact->id,
                'name' => $linkedContact->name,
                'normalized_name' => $linkedContact->normalized_name,
                'source' => 'linked_contact',
            ]);

            foreach (
                $linkedContact->aliases
                as $linkedAlias
            ) {
                $aliases->push([
                    'id' => (int) $linkedAlias->id,
                    'user_id' => (int) $linkedAlias->user_id,
                    'contact_id' => (int) $contact->id,
                    'linked_contact_id' => (int) $linkedContact->id,
                    'name' => $linkedAlias->name,
                    'normalized_name' => $linkedAlias->normalized_name,
                    'source' => 'linked_alias',
                ]);
            }
        }

        $aliases = $aliases
            ->reject(
                fn(array $alias): bool =>
                $alias['normalized_name']
                === $contact->normalized_name
            )
            ->unique(
                'normalized_name'
            )
            ->sortBy(
                static fn(array $alias): string =>
                mb_strtolower(
                    $alias['name'],
                    'UTF-8'
                )
            )
            ->values();

        return [
            'id' => (int) $contact->id,
            'user_id' => (int) $contact->user_id,
            'name' => $contact->name,
            'normalized_name' => $contact->normalized_name,
            'document' => $contact->document,
            'contact_type' => $contact->contact_type,
            'default_expense_category_id' =>
                $contact->default_expense_category_id,
            'default_income_category_id' =>
                $contact->default_income_category_id,
            'default_expense_category' =>
                $contact->defaultExpenseCategory,
            'default_income_category' =>
                $contact->defaultIncomeCategory,
            'aliases' => $aliases,
            'transactions_count' => $transactionsCount,
            'linked_contacts_count' =>
                $contact->mergedContacts->count(),
            'linked_contacts' => $contact
                ->mergedContacts
                ->map(
                    static fn(Contact $linkedContact): array => [
                        'id' => (int) $linkedContact->id,
                        'name' => $linkedContact->name,
                        'normalized_name' =>
                            $linkedContact->normalized_name,
                        'document' => $linkedContact->document,
                        'contact_type' =>
                            $linkedContact->contact_type,
                        'merged_at' =>
                            $linkedContact->merged_at
                                    ?->toISOString(),
                        'transactions_count' =>
                            (int) $linkedContact
                                ->transactions_count,
                    ]
                )
                ->values(),
            'created_at' =>
                $contact->created_at
                        ?->toISOString(),
            'updated_at' =>
                $contact->updated_at
                        ?->toISOString(),
        ];
    }

    /**
     * Garante que o contato pertence ao usuário autenticado.
     */
    private function ensureContactBelongsToUser(
        Contact $contact,
        int $userId
    ): void {
        if (
            (int) $contact->user_id
            !== $userId
        ) {
            abort(403);
        }
    }

    /**
     * Normaliza o CPF ou CNPJ informado manualmente.
     */
    private function normalizeDocument(
        mixed $document
    ): ?string {
        if ($document === null) {
            return null;
        }

        $document = trim(
            (string) $document
        );

        if ($document === '') {
            return null;
        }

        /*
         * Documentos censurados vindos de extratos não devem
         * ser convertidos em números parciais durante edição.
         */
        if (
            preg_match(
                '/[a-zA-ZxX•*]/u',
                $document
            )
        ) {
            return $document;
        }

        $digits = preg_replace(
            '/\D/',
            '',
            $document
        );

        return $digits !== ''
            ? $digits
            : null;
    }

    /**
     * Valida CPF ou CNPJ conforme o tipo do contato.
     */
    private function validateDocument(
        mixed $document,
        ?string $contactType,
        Closure $fail
    ): void {
        if (
            $document === null
            || $document === ''
        ) {
            return;
        }

        $document = (string) $document;

        if (!ctype_digit($document)) {
            $fail(
                'Informe um CPF ou CNPJ completo.'
            );

            return;
        }

        if ($contactType === null) {
            $fail(
                'Defina o tipo do contato antes de informar o documento.'
            );

            return;
        }

        if ($contactType === 'individual') {
            if (strlen($document) !== 11) {
                $fail(
                    'Uma pessoa deve possuir um CPF com 11 dígitos.'
                );

                return;
            }

            if (
                !$this->isValidCpf(
                    $document
                )
            ) {
                $fail(
                    'O CPF informado é inválido.'
                );
            }

            return;
        }

        if ($contactType === 'company') {
            if (strlen($document) !== 14) {
                $fail(
                    'Uma empresa deve possuir um CNPJ com 14 dígitos.'
                );

                return;
            }

            if (
                !$this->isValidCnpj(
                    $document
                )
            ) {
                $fail(
                    'O CNPJ informado é inválido.'
                );
            }
        }
    }

    /**
     * Valida se a categoria é global ou pertence ao usuário
     * e se possui o tipo esperado.
     */
    private function validateCategory(
        mixed $categoryId,
        string $categoryType,
        int $userId,
        string $field
    ): ?int {
        if (!$categoryId) {
            return null;
        }

        $category = Category::query()
            ->where(
                'id',
                $categoryId
            )
            ->where(
                'type',
                $categoryType
            )
            ->where(
                function ($query) use ($userId): void {
                    $query
                        ->whereNull(
                            'user_id'
                        )
                        ->orWhere(
                            'user_id',
                            $userId
                        );
                }
            )
            ->first();

        if (!$category) {
            throw ValidationException::withMessages([
                $field =>
                    $categoryType === 'expense'
                    ? 'Selecione uma categoria válida de despesa.'
                    : 'Selecione uma categoria válida de receita.',
            ]);
        }

        return (int) $category->id;
    }

    /**
     * Valida os dígitos verificadores do CPF.
     */
    private function isValidCpf(
        string $cpf
    ): bool {
        if (
            strlen($cpf) !== 11
            || preg_match(
                '/^(\d)\1{10}$/',
                $cpf
            )
        ) {
            return false;
        }

        for (
            $position = 9;
            $position <= 10;
            $position++
        ) {
            $sum = 0;

            for (
                $index = 0;
                $index < $position;
                $index++
            ) {
                $sum +=
                    (int) $cpf[$index]
                    * (
                        ($position + 1)
                        - $index
                    );
            }

            $digit =
                (10 * $sum)
                % 11;

            if ($digit === 10) {
                $digit = 0;
            }

            if (
                (int) $cpf[$position]
                !== $digit
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida os dígitos verificadores do CNPJ.
     */
    private function isValidCnpj(
        string $cnpj
    ): bool {
        if (
            strlen($cnpj) !== 14
            || preg_match(
                '/^(\d)\1{13}$/',
                $cnpj
            )
        ) {
            return false;
        }

        $firstDigit =
            $this->calculateCnpjDigit(
                base: substr(
                    $cnpj,
                    0,
                    12
                ),

                weights: [
                    5,
                    4,
                    3,
                    2,
                    9,
                    8,
                    7,
                    6,
                    5,
                    4,
                    3,
                    2,
                ]
            );

        if (
            (int) $cnpj[12]
            !== $firstDigit
        ) {
            return false;
        }

        $secondDigit =
            $this->calculateCnpjDigit(
                base:
                substr(
                    $cnpj,
                    0,
                    12
                )
                . $firstDigit,

                weights: [
                    6,
                    5,
                    4,
                    3,
                    2,
                    9,
                    8,
                    7,
                    6,
                    5,
                    4,
                    3,
                    2,
                ]
            );

        return (int) $cnpj[13]
            === $secondDigit;
    }

    /**
     * Calcula um dígito verificador do CNPJ.
     *
     * @param array<int> $weights
     */
    private function calculateCnpjDigit(
        string $base,
        array $weights
    ): int {
        $sum = 0;

        foreach (
            $weights as $index => $weight
        ) {
            $sum +=
                (int) $base[$index]
                * $weight;
        }

        $remainder =
            $sum % 11;

        return $remainder < 2
            ? 0
            : 11 - $remainder;
    }
}