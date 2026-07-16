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
     * Lista os contatos do usuário autenticado.
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
            ->select([
                'id',
                'user_id',
                'name',
                'normalized_name',
                'document',
                'contact_type',
                'default_expense_category_id',
                'default_income_category_id',
                'created_at',
                'updated_at',
            ])
            ->with([
                'defaultExpenseCategory:id,name,type,color',

                'defaultIncomeCategory:id,name,type,color',

                'aliases:id,user_id,contact_id,name,normalized_name',
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
                            ->whereKeyNot(
                                $contact->id
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
                        ->where(
                            'contact_id',
                            '<>',
                            $contact->id
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