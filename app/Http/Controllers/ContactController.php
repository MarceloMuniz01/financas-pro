<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Services\Contacts\ContactMergeService;
use App\Services\Contacts\ContactNameNormalizer;
use App\Services\Contacts\ContactSimilaritySignature;
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
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

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
            ->where('user_id', $userId)
            ->select([
                'id',
                'user_id',
                'name',
                'normalized_name',
                'document',
                'contact_type',
                'default_expense_category_id',
                'default_income_category_id',
                'looks_like_contact_id',
                'similarity_dismissed_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'defaultExpenseCategory:id,name,type,color',

                'defaultIncomeCategory:id,name,type,color',

                'aliases:id,user_id,contact_id,name,normalized_name',

                'looksLikeContact' => function ($query): void {
                    $query
                        ->select([
                            'id',
                            'user_id',
                            'name',
                            'document',
                            'contact_type',
                            'default_expense_category_id',
                            'default_income_category_id',
                        ])
                        ->with([
                            'defaultExpenseCategory:id,name,type,color',

                            'defaultIncomeCategory:id,name,type,color',

                            'aliases:id,user_id,contact_id,name,normalized_name',
                        ])
                        ->withCount('transactions');
                },
            ])
            ->withCount('transactions')
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $search = trim($search);

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
            /*
             * Mostra primeiro os contatos que possuem
             * sugestão de possível duplicidade.
             */
            ->orderByRaw(
                'looks_like_contact_id IS NULL'
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
     * Atualiza os dados editáveis do contato.
     */
    public function update(
        Request $request,
        Contact $contact
    ): RedirectResponse {
        $userId = $request->user()->id;

        $this->ensureContactBelongsToUser(
            $contact,
            $userId
        );

        $request->merge([
            'name' => trim(
                (string) $request->input('name')
            ),

            'contact_type' =>
                $request->input('contact_type')
                ?: null,

            'document' =>
                $this->normalizeDocument(
                    $request->input('document')
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

                    $exists = Contact::query()
                        ->where('user_id', $userId)
                        ->where(
                            'normalized_name',
                            $normalizedName
                        )
                        ->whereKeyNot($contact->id)
                        ->exists();

                    if ($exists) {
                        $fail(
                            'Já existe um contato com esse nome.'
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
                        fn($query) => $query->where(
                            'user_id',
                            $userId
                        )
                    )
                    ->ignore($contact->id),
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

                categoryType: 'expense',

                userId: $userId,

                field:
                'default_expense_category_id'
            );

        $incomeCategoryId =
            $this->validateCategory(
                categoryId:
                $validated[
                    'default_income_category_id'
                ] ?? null,

                categoryType: 'income',

                userId: $userId,

                field:
                'default_income_category_id'
            );

        $normalizedName =
            ContactNameNormalizer::normalize(
                $validated['name']
            );

        $similarityKeys =
            ContactSimilaritySignature::make(
                $validated['name']
            );

        $contact->update([
            'name' =>
                $validated['name'],

            'normalized_name' =>
                $normalizedName,

            'similarity_key' =>
                mb_substr(
                    $normalizedName,
                    0,
                    12,
                    'UTF-8'
                ),

            'similarity_signature' =>
                $similarityKeys[
                    'similarity_signature'
                ],

            'similarity_prefix' =>
                $similarityKeys[
                    'similarity_prefix'
                ],

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

            /*
             * A edição invalida uma sugestão antiga.
             *
             * Não executamos uma nova detecção aqui.
             * A análise automática permanece centralizada
             * no ProcessImportJob.
             */
            'similarity_dismissed_at' =>
                null,

            'looks_like_contact_id' =>
                null,
        ]);

        return back()->with(
            'success',
            'Contato atualizado com sucesso.'
        );
    }

    /**
     * Descarta uma sugestão de possível duplicidade.
     */
    public function dismissSimilarity(
        Request $request,
        Contact $contact
    ): RedirectResponse {
        $userId = $request->user()->id;

        $this->ensureContactBelongsToUser(
            $contact,
            $userId
        );

        $contact->update([
            'looks_like_contact_id' =>
                null,

            /*
             * Impede que a sugestão seja recriada
             * automaticamente em uma próxima importação,
             * até que o contato seja editado.
             */
            'similarity_dismissed_at' =>
                now(),
        ]);

        return back()->with(
            'success',
            'Sugestão de contato semelhante ignorada.'
        );
    }

    /**
     * Mescla um contato de origem em um contato de destino.
     *
     * O contato de origem será excluído.
     * O contato de destino permanecerá.
     */
    public function merge(
        Request $request,
        Contact $contact,
        ContactMergeService $mergeService
    ): RedirectResponse {
        $userId = $request->user()->id;

        $this->ensureContactBelongsToUser(
            $contact,
            $userId
        );

        $validated = $request->validate([
            'source_contact_id' => [
                'required',
                'integer',
                'different:target_contact_id',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) => $query->where(
                            'user_id',
                            $userId
                        )
                    ),
            ],

            'target_contact_id' => [
                'required',
                'integer',
                'different:source_contact_id',

                Rule::exists(
                    'contacts',
                    'id'
                )->where(
                        fn($query) => $query->where(
                            'user_id',
                            $userId
                        )
                    ),
            ],
        ]);

        $sourceContactId =
            (int) $validated[
                'source_contact_id'
            ];

        $targetContactId =
            (int) $validated[
                'target_contact_id'
            ];

        /*
         * A rota precisa partir de um dos dois contatos
         * envolvidos na mesclagem.
         */
        if (
            $contact->id !== $sourceContactId
            && $contact->id !== $targetContactId
        ) {
            abort(403);
        }

        $mergeService->merge(
            userId: $userId,

            sourceContactId:
            $sourceContactId,

            targetContactId:
            $targetContactId
        );

        /*
         * Não executamos detecção de similaridade aqui.
         *
         * O ContactMergeService já:
         *
         * - move as transações;
         * - transfere os aliases;
         * - limpa referências à origem;
         * - remove o contato de origem.
         *
         * Uma análise adicional seria retrabalho.
         */

        return back()->with(
            'success',
            'Contatos mesclados com sucesso.'
        );
    }

    /**
     * Garante que o contato pertence ao usuário autenticado.
     */
    private function ensureContactBelongsToUser(
        Contact $contact,
        int $userId
    ): void {
        if ($contact->user_id !== $userId) {
            abort(403);
        }
    }

    /**
     * Normaliza CPF ou CNPJ enviado pelo usuário.
     *
     * Na edição manual, documentos censurados
     * não são aceitos.
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
         * Evita transformar um documento censurado
         * em uma sequência parcial de números.
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

            if (!$this->isValidCpf($document)) {
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

            if (!$this->isValidCnpj($document)) {
                $fail(
                    'O CNPJ informado é inválido.'
                );
            }
        }
    }

    /**
     * Valida se a categoria é global ou pertence ao usuário
     * e se possui o tipo correto.
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
                        ->whereNull('user_id')
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

        return $category->id;
    }

    /**
     * Valida CPF pelos dois dígitos verificadores.
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
                    * (($position + 1) - $index);
            }

            $digit = (10 * $sum) % 11;

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
     * Valida CNPJ pelos dois dígitos verificadores.
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

        $remainder = $sum % 11;

        return $remainder < 2
            ? 0
            : 11 - $remainder;
    }
}