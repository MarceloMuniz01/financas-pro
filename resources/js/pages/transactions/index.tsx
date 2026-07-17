import AppLayout from "@/layouts/app-layout";
import { Head, router, usePage } from "@inertiajs/react";
import { useMemo, useState } from "react";
import type { FormEvent, ReactNode } from "react";

type TransactionType = "income" | "expense";
type ContactType = "company" | "individual" | null;

type LinkedContact = {
    id: number;
    name: string;
    normalized_name: string;
    document: string | null;
    contact_type: ContactType;
    merged_at: string | null;
};

type TransactionContact = {
    id: number;
    user_id: number;
    name: string;
    normalized_name: string;
    document: string | null;
    contact_type: ContactType;
    linked_contacts_count: number;
    linked_contacts: LinkedContact[];
};

type OriginalTransactionContact = {
    id: number;
    name: string;
    normalized_name: string;
    document: string | null;
    contact_type: ContactType;
    merged_into_contact_id: number;
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
    user_id: number;
    import_id: number | null;
    contact_id: number | null;
    category_id: number | null;
    transaction_date: string;
    description: string;
    amount: number;
    type: TransactionType;
    source_type: string;
    counterparty_name: string | null;
    counterparty_document: string | null;
    counterparty_type: ContactType;
    transaction_method: string | null;
    contact: TransactionContact | null;
    original_contact: OriginalTransactionContact | null;
    is_from_merged_contact: boolean;
    category: TransactionCategory | null;
    import: TransactionImport | null;
    created_at: string | null;
    updated_at: string | null;
};

type CategoryItem = TransactionCategory;

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

type PageProps = {
    flash?: {
        success?: string;
        error?: string;
    };
};

type Props = {
    transactions: PaginatedTransactions;
    categories: CategoryItem[];
    filters: Filters;
    totals: Totals;
};

type CategoryChangeScope =
    "current" | "all_from_contact" | "current_and_future";

const breadcrumbs = [
    {
        title: "Transações",
        href: "/transactions",
    },
];

const emptyFilters: Filters = {
    search: "",
    type: "",
    category_id: "",
    date_from: "",
    date_to: "",
};

export default function TransactionsIndex({
    transactions,
    categories,
    filters: initialFilters,
    totals,
}: Props) {
    const { flash } = usePage<PageProps>().props;

    const [filters, setFilters] = useState<Filters>(initialFilters);

    const [editingTransaction, setEditingTransaction] =
        useState<TransactionItem | null>(null);

    const filteredCategories = useMemo(() => {
        if (filters.type !== "income" && filters.type !== "expense") {
            return categories;
        }

        return categories.filter((category) => category.type === filters.type);
    }, [categories, filters.type]);

    function updateFilter(name: keyof Filters, value: string) {
        setFilters((current) => {
            const next = {
                ...current,
                [name]: value,
            };

            if (name === "type" && current.category_id) {
                const selectedCategory = categories.find(
                    (category) => category.id === Number(current.category_id),
                );

                if (selectedCategory && value && selectedCategory.type !== value) {
                    next.category_id = "";
                }
            }

            return next;
        });
    }

    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.get("/transactions", compactFilters(filters), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function clearFilters() {
        setFilters(emptyFilters);

        router.get(
            "/transactions",
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transações" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">Transações</h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Consulte e organize as movimentações importadas dos seus extratos.
                    </p>
                </header>

                {flash?.success && (
                    <FeedbackMessage type="success" message={flash.success} />
                )}

                {flash?.error && <FeedbackMessage type="error" message={flash.error} />}

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
                            totals.balance >= 0 ? "text-green-600" : "text-red-600"
                        }
                    />
                </section>

                <TransactionFilters
                    filters={filters}
                    categories={filteredCategories}
                    onChange={updateFilter}
                    onSubmit={applyFilters}
                    onClear={clearFilters}
                />

                <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="border-b p-4 md:p-6">
                        <h2 className="text-lg font-semibold">Movimentações</h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {transactions.total === 0
                                ? "Nenhuma transação encontrada."
                                : `${transactions.total} ${transactions.total === 1
                                    ? "transação encontrada"
                                    : "transações encontradas"
                                }.`}
                        </p>
                    </div>

                    {transactions.data.length === 0 ? (
                        <EmptyTransactions />
                    ) : (
                        <>
                            <TransactionTable
                                transactions={transactions.data}
                                onEditCategory={setEditingTransaction}
                            />

                            <Pagination pagination={transactions} />
                        </>
                    )}
                </section>
            </div>

            {editingTransaction && (
                <CategoryChangeModal
                    transaction={editingTransaction}
                    categories={categories}
                    onClose={() => setEditingTransaction(null)}
                />
            )}
        </AppLayout>
    );
}

function TransactionFilters({
    filters,
    categories,
    onChange,
    onSubmit,
    onClear,
}: {
    filters: Filters;
    categories: CategoryItem[];
    onChange: (name: keyof Filters, value: string) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onClear: () => void;
}) {
    return (
        <section className="rounded-xl border bg-card p-4 shadow-sm md:p-6">
            <form
                onSubmit={onSubmit}
                className="grid gap-4 md:grid-cols-2 xl:grid-cols-6"
            >
                <FilterField
                    label="Buscar"
                    htmlFor="search"
                    className="md:col-span-2 xl:col-span-2"
                >
                    <input
                        id="search"
                        type="search"
                        value={filters.search}
                        onChange={(event) => onChange("search", event.target.value)}
                        placeholder="Descrição, contato ou documento"
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                </FilterField>

                <FilterField label="Tipo" htmlFor="type">
                    <select
                        id="type"
                        value={filters.type}
                        onChange={(event) => onChange("type", event.target.value)}
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Todos</option>
                        <option value="income">Receitas</option>
                        <option value="expense">Despesas</option>
                    </select>
                </FilterField>

                <FilterField label="Categoria" htmlFor="category_id">
                    <select
                        id="category_id"
                        value={filters.category_id}
                        onChange={(event) => onChange("category_id", event.target.value)}
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">Todas</option>

                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </FilterField>

                <FilterField label="Data inicial" htmlFor="date_from">
                    <input
                        id="date_from"
                        type="date"
                        value={filters.date_from}
                        onChange={(event) => onChange("date_from", event.target.value)}
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </FilterField>

                <FilterField label="Data final" htmlFor="date_to">
                    <input
                        id="date_to"
                        type="date"
                        value={filters.date_to}
                        min={filters.date_from || undefined}
                        onChange={(event) => onChange("date_to", event.target.value)}
                        className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    />
                </FilterField>

                <div className="flex items-end gap-2 md:col-span-2 xl:col-span-6">
                    <button
                        type="submit"
                        className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                    >
                        Aplicar filtros
                    </button>

                    <button
                        type="button"
                        onClick={onClear}
                        className="h-10 rounded-md border px-4 text-sm font-medium hover:bg-muted"
                    >
                        Limpar
                    </button>
                </div>
            </form>
        </section>
    );
}

function FilterField({
    label,
    htmlFor,
    className = "",
    children,
}: {
    label: string;
    htmlFor: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className}>
            <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium">
                {label}
            </label>

            {children}
        </div>
    );
}

function TransactionTable({
    transactions,
    onEditCategory,
}: {
    transactions: TransactionItem[];
    onEditCategory: (transaction: TransactionItem) => void;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[980px] text-left text-sm">
                <thead className="border-b bg-muted/50">
                    <tr>
                        <TableHeader>Data</TableHeader>
                        <TableHeader>Descrição</TableHeader>
                        <TableHeader>Contato</TableHeader>
                        <TableHeader>Categoria</TableHeader>
                        <TableHeader>Método</TableHeader>
                        <TableHeader align="right">Valor</TableHeader>
                    </tr>
                </thead>

                <tbody>
                    {transactions.map((transaction) => (
                        <TransactionRow
                            key={transaction.id}
                            transaction={transaction}
                            onEditCategory={onEditCategory}
                        />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function TableHeader({
    children,
    align = "left",
}: {
    children: ReactNode;
    align?: "left" | "right";
}) {
    return (
        <th
            className={`px-4 py-3 font-medium md:px-6 ${align === "right" ? "text-right" : ""
                }`}
        >
            {children}
        </th>
    );
}

function TransactionRow({
    transaction,
    onEditCategory,
}: {
    transaction: TransactionItem;
    onEditCategory: (transaction: TransactionItem) => void;
}) {
    return (
        <tr className="border-b last:border-b-0 hover:bg-muted/30">
            <td className="whitespace-nowrap px-4 py-4 md:px-6">
                {formatDate(transaction.transaction_date)}
            </td>

            <td className="max-w-sm px-4 py-4 md:px-6">
                <div className="truncate font-medium" title={transaction.description}>
                    {transaction.description}
                </div>

                <div className="mt-1 text-xs text-muted-foreground">
                    {formatBank(transaction.import?.bank)}
                </div>
            </td>

            <td className="px-4 py-4 md:px-6">
                <ContactCell transaction={transaction} />
            </td>

            <td className="px-4 py-4 md:px-6">
                <button
                    type="button"
                    onClick={() => onEditCategory(transaction)}
                    className="rounded-md text-left transition-opacity hover:opacity-70"
                >
                    {transaction.category ? (
                        <CategoryBadge category={transaction.category} />
                    ) : (
                        <span className="text-muted-foreground underline decoration-dashed underline-offset-4">
                            Definir categoria
                        </span>
                    )}
                </button>
            </td>

            <td className="px-4 py-4 md:px-6">
                {formatMethod(transaction.transaction_method)}
            </td>

            <td
                className={`whitespace-nowrap px-4 py-4 text-right font-semibold md:px-6 ${transaction.amount >= 0 ? "text-green-600" : "text-red-600"
                    }`}
            >
                {formatCurrency(transaction.amount)}
            </td>
        </tr>
    );
}

function ContactCell({ transaction }: { transaction: TransactionItem }) {
    if (!transaction.contact) {
        return <span className="text-muted-foreground">Desconhecido</span>;
    }

    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2">
                <span className="truncate font-medium">{transaction.contact.name}</span>

                {transaction.contact.linked_contacts_count > 0 && (
                    <span className="whitespace-nowrap rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">
                        {transaction.contact.linked_contacts_count}{" "}
                        {transaction.contact.linked_contacts_count === 1
                            ? "mesclado"
                            : "mesclados"}
                    </span>
                )}
            </div>

            {transaction.is_from_merged_contact && transaction.original_contact && (
                <div className="mt-1 text-xs text-muted-foreground">
                    Importado como {transaction.original_contact.name}
                </div>
            )}
        </div>
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
            <p className="text-sm text-muted-foreground">{title}</p>

            <p className={`mt-2 text-2xl font-semibold ${valueClassName}`}>
                {formatCurrency(value)}
            </p>
        </div>
    );
}

function CategoryBadge({ category }: { category: TransactionCategory }) {
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
    const availableCategories = categories.filter(
        (category) => category.type === transaction.type,
    );

    const [categoryId, setCategoryId] = useState(
        transaction.category?.id.toString() ?? "",
    );

    const [scope, setScope] = useState<CategoryChangeScope>("current");

    const [isSubmitting, setIsSubmitting] = useState(false);

    const [error, setError] = useState<string | null>(null);

    const hasContact = transaction.contact !== null;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!categoryId) {
            setError("Selecione uma categoria.");
            return;
        }

        if (!hasContact && scope !== "current") {
            setError("Esta transação não possui um contato associado.");
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

                onSuccess: onClose,

                onError: (errors) => {
                    setError(
                        errors.category_id ||
                        errors.scope ||
                        "Não foi possível atualizar a categoria.",
                    );
                },

                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    return (
        <ModalBackdrop canClose={!isSubmitting} onClose={onClose}>
            <div className="w-full max-w-lg rounded-xl border bg-background shadow-xl">
                <div className="border-b p-5">
                    <h2 className="text-lg font-semibold">Alterar categoria</h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        {transaction.contact?.name ?? transaction.description}
                    </p>

                    {transaction.is_from_merged_contact &&
                        transaction.original_contact && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Transação importada como {transaction.original_contact.name}
                            </p>
                        )}

                    <p
                        className={`mt-2 text-sm font-semibold ${transaction.amount >= 0 ? "text-green-600" : "text-red-600"
                            }`}
                    >
                        {formatCurrency(transaction.amount)}
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-5 p-5">
                    <FilterField label="Nova categoria" htmlFor="category_id_modal">
                        <select
                            id="category_id_modal"
                            value={categoryId}
                            onChange={(event) => setCategoryId(event.target.value)}
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Selecione uma categoria</option>

                            {availableCategories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </FilterField>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">Onde aplicar?</legend>

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
                            title="Todas as transações do grupo"
                            description="Atualiza o histórico do contato principal e de todos os contatos mesclados, além da categoria padrão."
                        />

                        <ScopeOption
                            value="current_and_future"
                            selectedValue={scope}
                            onChange={setScope}
                            disabled={!hasContact}
                            title="Esta e as próximas transações"
                            description="Altera esta movimentação e a categoria padrão do contato principal."
                        />
                    </fieldset>

                    {error && <FeedbackMessage type="error" message={error} />}

                    <div className="flex justify-end gap-2 border-t pt-4">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isSubmitting}
                            className="h-10 rounded-md border px-4 text-sm font-medium hover:bg-muted disabled:opacity-50"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            disabled={isSubmitting || !categoryId}
                            className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting ? "Salvando..." : "Salvar alteração"}
                        </button>
                    </div>
                </form>
            </div>
        </ModalBackdrop>
    );
}

function ModalBackdrop({
    canClose,
    onClose,
    children,
}: {
    canClose: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            onMouseDown={(event) => {
                if (canClose && event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            {children}
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
    onChange: (value: CategoryChangeScope) => void;
    title: string;
    description: string;
    disabled?: boolean;
}) {
    return (
        <label
            className={`flex gap-3 rounded-lg border p-4 transition-colors ${selectedValue === value ? "border-primary bg-primary/5" : ""
                } ${disabled
                    ? "cursor-not-allowed opacity-50"
                    : "cursor-pointer hover:bg-muted/40"
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
                <span className="block text-sm font-medium">{title}</span>

                <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}

function Pagination({ pagination }: { pagination: PaginatedTransactions }) {
    if (pagination.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t p-4 md:flex-row md:items-center md:justify-between md:px-6">
            <p className="text-sm text-muted-foreground">
                Exibindo {pagination.from ?? 0} até {pagination.to ?? 0} de{" "}
                {pagination.total}
            </p>

            <div className="flex flex-wrap gap-1">
                {pagination.links.map((link, index) => (
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
                        className={`min-w-9 rounded-md border px-3 py-2 text-sm ${link.active
                                ? "bg-primary text-primary-foreground"
                                : "bg-background hover:bg-muted"
                            } disabled:cursor-not-allowed disabled:opacity-40`}
                        dangerouslySetInnerHTML={{
                            __html: link.label,
                        }}
                    />
                ))}
            </div>
        </div>
    );
}

function EmptyTransactions() {
    return (
        <div className="p-8 text-center text-sm text-muted-foreground">
            Nenhuma transação corresponde aos filtros selecionados.
        </div>
    );
}

function FeedbackMessage({
    type,
    message,
}: {
    type: "success" | "error";
    message: string;
}) {
    const classes =
        type === "success"
            ? "border-green-200 bg-green-50 text-green-800"
            : "border-red-200 bg-red-50 text-red-700";

    return (
        <div className={`rounded-md border px-4 py-3 text-sm ${classes}`}>
            {message}
        </div>
    );
}

function compactFilters(filters: Filters): Partial<Filters> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ""),
    ) as Partial<Filters>;
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat("pt-BR", {
        style: "currency",
        currency: "BRL",
    }).format(value);
}

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split("-");

    if (!year || !month || !day) {
        return value;
    }

    return `${day}/${month}/${year}`;
}

function formatBank(bank?: string | null): string {
    const banks: Record<string, string> = {
        nubank: "Nubank",
        inter: "Banco Inter",
        santander: "Santander",
        itau: "Itaú",
        bradesco: "Bradesco",
        caixa: "Caixa",
        banco_do_brasil: "Banco do Brasil",
    };

    if (!bank) {
        return "Banco não identificado";
    }

    return banks[bank] ?? bank;
}

function formatMethod(method: string | null): string {
    const methods: Record<string, string> = {
        pix: "Pix",
        ted: "TED",
        doc: "DOC",
        boleto: "Boleto",
        card: "Cartão",
        debit_card: "Cartão de débito",
        credit_card: "Cartão de crédito",
        transferencia: "Transferência",
        saque: "Saque",
        deposito: "Depósito",
        tarifa: "Tarifa",
        outros: "Outros",
        other: "Outro",
    };

    if (!method) {
        return "Outro";
    }

    return methods[method] ?? method;
}