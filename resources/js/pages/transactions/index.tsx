import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    FormEvent,
    useEffect,
    useMemo,
    useState,
} from 'react';

type TransactionType = 'income' | 'expense';

type ImportStatus =
    | 'pending'
    | 'processing'
    | 'done'
    | 'failed';

type TransactionContact = {
    id: number;
    name: string;
    contact_type: 'company' | 'individual' | null;
};

type TransactionCategory = {
    id: number;
    name: string;
    type: TransactionType;
    color: string;
};

type TransactionImport = {
    id: number;
    bank: string | null;
};

type TransactionItem = {
    id: number;
    transaction_date: string;
    transaction_code: string;
    description: string;
    amount: string;
    source_type: string;
    transaction_method: string | null;

    contact: TransactionContact | null;
    category: TransactionCategory | null;
    import: TransactionImport | null;
};

type CategoryItem = {
    id: number;
    name: string;
    type: TransactionType;
    color: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedTransactions = {
    data: TransactionItem[];

    current_page: number;
    last_page: number;
    per_page: number;
    total: number;

    from: number | null;
    to: number | null;

    links: PaginationLink[];
};

type Filters = {
    search: string;
    type: string;
    category_id: string;
    date_from: string;
    date_to: string;
};

type Totals = {
    income: number;
    expense: number;
    balance: number;
};

type FlashMessages = {
    success?: string;
    error?: string;
};

type PageProps = {
    flash?: FlashMessages;
};

type Props = {
    transactions: PaginatedTransactions;
    categories: CategoryItem[];
    filters: Filters;
    totals: Totals;
};

type CategoryChangeScope =
    | 'current'
    | 'all_from_contact'
    | 'current_and_future';

const breadcrumbs = [
    {
        title: 'Transações',
        href: '/transactions',
    },
];

export default function TransactionsIndex({
    transactions,
    categories,
    filters,
    totals,
}: Props) {
    const page = usePage<PageProps>();

    const successMessage =
        page.props.flash?.success ?? null;

    const [search, setSearch] = useState(filters.search);
    const [type, setType] = useState(filters.type);

    const [categoryId, setCategoryId] = useState(
        filters.category_id,
    );

    const [dateFrom, setDateFrom] = useState(
        filters.date_from,
    );

    const [dateTo, setDateTo] = useState(
        filters.date_to,
    );

    const [
        editingTransaction,
        setEditingTransaction,
    ] = useState<TransactionItem | null>(null);

    useEffect(() => {
        setSearch(filters.search);
        setType(filters.type);
        setCategoryId(filters.category_id);
        setDateFrom(filters.date_from);
        setDateTo(filters.date_to);
    }, [filters]);

    const filteredCategories = useMemo(() => {
        if (
            type !== 'income'
            && type !== 'expense'
        ) {
            return categories;
        }

        return categories.filter(
            (category) => category.type === type,
        );
    }, [categories, type]);

    function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        router.get(
            '/transactions',
            {
                search: search || undefined,
                type: type || undefined,
                category_id: categoryId || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function handleClearFilters() {
        setSearch('');
        setType('');
        setCategoryId('');
        setDateFrom('');
        setDateTo('');

        router.get(
            '/transactions',
            {},
            {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function handleTypeChange(value: string) {
        setType(value);

        if (!categoryId) {
            return;
        }

        const category = categories.find(
            (item) =>
                item.id === Number(categoryId),
        );

        if (
            category
            && value
            && category.type !== value
        ) {
            setCategoryId('');
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transações" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Transações
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Consulte e organize as movimentações
                        importadas dos seus extratos.
                    </p>
                </header>

                {successMessage && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        {successMessage}
                    </div>
                )}

                <section className="grid gap-4 md:grid-cols-3">
                    <SummaryCard
                        title="Receitas"
                        value={totals.income}
                        valueClassName="text-green-600"
                    />

                    <SummaryCard
                        title="Despesas"
                        value={totals.expense}
                        valueClassName="text-red-600"
                    />

                    <SummaryCard
                        title="Saldo"
                        value={totals.balance}
                        valueClassName={
                            totals.balance >= 0
                                ? 'text-green-600'
                                : 'text-red-600'
                        }
                    />
                </section>

                <section className="rounded-xl border bg-card p-4 shadow-sm md:p-6">
                    <form
                        onSubmit={handleSubmit}
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-6"
                    >
                        <div className="md:col-span-2 xl:col-span-2">
                            <label
                                htmlFor="search"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Buscar
                            </label>

                            <input
                                id="search"
                                type="text"
                                value={search}
                                onChange={(event) =>
                                    setSearch(
                                        event.target.value,
                                    )
                                }
                                placeholder="Descrição ou contato"
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                            />
                        </div>

                        <div>
                            <label
                                htmlFor="type"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Tipo
                            </label>

                            <select
                                id="type"
                                value={type}
                                onChange={(event) =>
                                    handleTypeChange(
                                        event.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">
                                    Todos
                                </option>

                                <option value="income">
                                    Receitas
                                </option>

                                <option value="expense">
                                    Despesas
                                </option>
                            </select>
                        </div>

                        <div>
                            <label
                                htmlFor="category"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Categoria
                            </label>

                            <select
                                id="category"
                                value={categoryId}
                                onChange={(event) =>
                                    setCategoryId(
                                        event.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">
                                    Todas
                                </option>

                                {filteredCategories.map(
                                    (category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>

                        <div>
                            <label
                                htmlFor="date_from"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Data inicial
                            </label>

                            <input
                                id="date_from"
                                type="date"
                                value={dateFrom}
                                onChange={(event) =>
                                    setDateFrom(
                                        event.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </div>

                        <div>
                            <label
                                htmlFor="date_to"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Data final
                            </label>

                            <input
                                id="date_to"
                                type="date"
                                value={dateTo}
                                onChange={(event) =>
                                    setDateTo(
                                        event.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            />
                        </div>

                        <div className="flex items-end gap-2 md:col-span-2 xl:col-span-6">
                            <button
                                type="submit"
                                className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                            >
                                Aplicar filtros
                            </button>

                            <button
                                type="button"
                                onClick={handleClearFilters}
                                className="h-10 rounded-md border px-4 text-sm font-medium"
                            >
                                Limpar
                            </button>
                        </div>
                    </form>
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="flex flex-col gap-1 border-b p-4 md:p-6">
                        <h2 className="text-lg font-semibold">
                            Movimentações
                        </h2>

                        <p className="text-sm text-muted-foreground">
                            {transactions.total === 0
                                ? 'Nenhuma transação encontrada.'
                                : `${transactions.total} transações encontradas.`}
                        </p>
                    </div>

                    {transactions.data.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            Nenhuma transação corresponde aos
                            filtros selecionados.
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[950px] text-left text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Data
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Descrição
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Contato
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Categoria
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Método
                                            </th>

                                            <th className="px-4 py-3 text-right font-medium md:px-6">
                                                Valor
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {transactions.data.map(
                                            (transaction) => {
                                                const amount =
                                                    Number(
                                                        transaction.amount,
                                                    );

                                                return (
                                                    <tr
                                                        key={
                                                            transaction.id
                                                        }
                                                        className="border-b last:border-b-0 hover:bg-muted/30"
                                                    >
                                                        <td className="whitespace-nowrap px-4 py-4 md:px-6">
                                                            {formatDate(
                                                                transaction.transaction_date,
                                                            )}
                                                        </td>

                                                        <td className="max-w-sm px-4 py-4 md:px-6">
                                                            <div
                                                                className="truncate font-medium"
                                                                title={
                                                                    transaction.description
                                                                }
                                                            >
                                                                {
                                                                    transaction.description
                                                                }
                                                            </div>

                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                {formatBank(
                                                                    transaction
                                                                        .import
                                                                        ?.bank,
                                                                )}
                                                            </div>
                                                        </td>

                                                        <td className="px-4 py-4 md:px-6">
                                                            {transaction
                                                                .contact
                                                                ?.name
                                                                ?? 'Desconhecido'}
                                                        </td>

                                                        <td className="px-4 py-4 md:px-6">
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    setEditingTransaction(
                                                                        transaction,
                                                                    )
                                                                }
                                                                className="rounded-md text-left transition-opacity hover:opacity-70"
                                                            >
                                                                {transaction.category ? (
                                                                    <CategoryBadge
                                                                        category={
                                                                            transaction.category
                                                                        }
                                                                    />
                                                                ) : (
                                                                    <span className="text-muted-foreground underline decoration-dashed underline-offset-4">
                                                                        Definir
                                                                        categoria
                                                                    </span>
                                                                )}
                                                            </button>
                                                        </td>

                                                        <td className="px-4 py-4 md:px-6">
                                                            {formatMethod(
                                                                transaction.transaction_method,
                                                            )}
                                                        </td>

                                                        <td
                                                            className={`whitespace-nowrap px-4 py-4 text-right font-semibold md:px-6 ${
                                                                amount >= 0
                                                                    ? 'text-green-600'
                                                                    : 'text-red-600'
                                                            }`}
                                                        >
                                                            {formatCurrency(
                                                                amount,
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            },
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination
                                transactions={transactions}
                            />
                        </>
                    )}
                </section>
            </div>

            {editingTransaction && (
                <CategoryChangeModal
                    transaction={editingTransaction}
                    categories={categories}
                    onClose={() =>
                        setEditingTransaction(null)
                    }
                />
            )}
        </AppLayout>
    );
}

function SummaryCard({
    title,
    value,
    valueClassName,
}: {
    title: string;
    value: number;
    valueClassName: string;
}) {
    return (
        <div className="rounded-xl border bg-card p-5 shadow-sm">
            <p className="text-sm text-muted-foreground">
                {title}
            </p>

            <p
                className={`mt-2 text-2xl font-semibold ${valueClassName}`}
            >
                {formatCurrency(value)}
            </p>
        </div>
    );
}

function CategoryBadge({
    category,
}: {
    category: TransactionCategory;
}) {
    return (
        <span className="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-medium">
            <span
                className="h-2.5 w-2.5 rounded-full"
                style={{
                    backgroundColor: category.color,
                }}
            />

            {category.name}
        </span>
    );
}

function CategoryChangeModal({
    transaction,
    categories,
    onClose,
}: {
    transaction: TransactionItem;
    categories: CategoryItem[];
    onClose: () => void;
}) {
    const transactionType: TransactionType =
        Number(transaction.amount) < 0
            ? 'expense'
            : 'income';

    const availableCategories = categories.filter(
        (category) =>
            category.type === transactionType,
    );

    const [categoryId, setCategoryId] = useState(
        transaction.category?.id.toString() ?? '',
    );

    const [scope, setScope] =
        useState<CategoryChangeScope>('current');

    const [isSubmitting, setIsSubmitting] =
        useState(false);

    const [error, setError] =
        useState<string | null>(null);

    const hasContact = transaction.contact !== null;

    function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        if (!categoryId) {
            setError('Selecione uma categoria.');
            return;
        }

        if (
            !hasContact
            && scope !== 'current'
        ) {
            setError(
                'Esta transação não possui um contato associado.',
            );

            return;
        }

        setIsSubmitting(true);
        setError(null);

        router.patch(
            `/transactions/${transaction.id}/category`,
            {
                category_id: Number(categoryId),
                scope,
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    onClose();
                },

                onError: (errors) => {
                    const firstError =
                        errors.category_id
                        || errors.scope
                        || 'Não foi possível atualizar a categoria.';

                    setError(firstError);
                },

                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            onMouseDown={(event) => {
                if (
                    event.target
                    === event.currentTarget
                    && !isSubmitting
                ) {
                    onClose();
                }
            }}
        >
            <div className="w-full max-w-lg rounded-xl border bg-background shadow-xl">
                <div className="border-b p-5">
                    <h2 className="text-lg font-semibold">
                        Alterar categoria
                    </h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {transaction.contact?.name
                            ?? transaction.description}
                    </p>

                    <p
                        className={`mt-2 text-sm font-semibold ${
                            Number(transaction.amount) >= 0
                                ? 'text-green-600'
                                : 'text-red-600'
                        }`}
                    >
                        {formatCurrency(
                            Number(transaction.amount),
                        )}
                    </p>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="space-y-5 p-5"
                >
                    <div>
                        <label
                            htmlFor="category_id"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            Nova categoria
                        </label>

                        <select
                            id="category_id"
                            value={categoryId}
                            onChange={(event) =>
                                setCategoryId(
                                    event.target.value,
                                )
                            }
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">
                                Selecione uma categoria
                            </option>

                            {availableCategories.map(
                                (category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ),
                            )}
                        </select>
                    </div>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Onde aplicar?
                        </legend>

                        <ScopeOption
                            value="current"
                            selectedValue={scope}
                            onChange={setScope}
                            title="Somente esta transação"
                            description="Altera apenas esta movimentação."
                        />

                        <ScopeOption
                            value="all_from_contact"
                            selectedValue={scope}
                            onChange={setScope}
                            disabled={!hasContact}
                            title="Todas as transações deste contato"
                            description="Corrige todo o histórico deste contato e usa a categoria nas próximas importações."
                        />

                        <ScopeOption
                            value="current_and_future"
                            selectedValue={scope}
                            onChange={setScope}
                            disabled={!hasContact}
                            title="Esta e as próximas transações"
                            description="Mantém o histórico anterior e usa esta categoria nas próximas importações."
                        />
                    </fieldset>

                    {error && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {error}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 border-t pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isSubmitting}
                            className="h-10 rounded-md border px-4 text-sm font-medium disabled:opacity-50"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            disabled={
                                isSubmitting
                                || !categoryId
                            }
                            className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting
                                ? 'Salvando...'
                                : 'Salvar alteração'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ScopeOption({
    value,
    selectedValue,
    onChange,
    title,
    description,
    disabled = false,
}: {
    value: CategoryChangeScope;
    selectedValue: CategoryChangeScope;
    onChange: (
        value: CategoryChangeScope,
    ) => void;
    title: string;
    description: string;
    disabled?: boolean;
}) {
    return (
        <label
            className={`flex gap-3 rounded-lg border p-4 transition-colors ${
                selectedValue === value
                    ? 'border-primary bg-primary/5'
                    : ''
            } ${
                disabled
                    ? 'cursor-not-allowed opacity-50'
                    : 'cursor-pointer hover:bg-muted/40'
            }`}
        >
            <input
                type="radio"
                name="scope"
                value={value}
                checked={selectedValue === value}
                disabled={disabled}
                onChange={() => onChange(value)}
                className="mt-1"
            />

            <span>
                <span className="block text-sm font-medium">
                    {title}
                </span>

                <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}

function Pagination({
    transactions,
}: {
    transactions: PaginatedTransactions;
}) {
    if (transactions.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t p-4 md:flex-row md:items-center md:justify-between md:px-6">
            <p className="text-sm text-muted-foreground">
                Exibindo {transactions.from ?? 0} até{' '}
                {transactions.to ?? 0} de{' '}
                {transactions.total}
            </p>

            <div className="flex flex-wrap gap-1">
                {transactions.links.map(
                    (link, index) => (
                        <button
                            key={`${link.label}-${index}`}
                            type="button"
                            disabled={!link.url}
                            onClick={() => {
                                if (!link.url) {
                                    return;
                                }

                                router.visit(link.url, {
                                    preserveState: true,
                                    preserveScroll: true,
                                });
                            }}
                            className={`min-w-9 rounded-md border px-3 py-2 text-sm ${
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-background hover:bg-muted'
                            } disabled:cursor-not-allowed disabled:opacity-40`}
                            dangerouslySetInnerHTML={{
                                __html: link.label,
                            }}
                        />
                    ),
                )}
            </div>
        </div>
    );
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(value);
}

function formatDate(value: string): string {
    const datePart = value.slice(0, 10);

    const [year, month, day] =
        datePart.split('-');

    if (!year || !month || !day) {
        return value;
    }

    return `${day}/${month}/${year}`;
}

function formatBank(
    bank?: string | null,
): string {
    const banks: Record<string, string> = {
        nubank: 'Nubank',
        inter: 'Banco Inter',
    };

    if (!bank) {
        return 'Banco não identificado';
    }

    return banks[bank] ?? bank;
}

function formatMethod(
    method: string | null,
): string {
    const methods: Record<string, string> = {
        pix: 'Pix',
        ted: 'TED',
        doc: 'DOC',
        boleto: 'Boleto',
        card: 'Cartão',
        debit_card: 'Cartão de débito',
        credit_card: 'Cartão de crédito',
        other: 'Outro',
    };

    if (!method) {
        return 'Outro';
    }

    return methods[method] ?? method;
}