import AppLayout from "@/layouts/app-layout";
import { Head, router, usePage } from "@inertiajs/react";
import { FormEvent, useEffect, useMemo, useState } from "react";

type ContactType = "company" | "individual" | null;

type EditableContactType = "company" | "individual" | "";

type CategoryType = "expense" | "income";

type CategoryItem = {
    id: number;
    name: string;
    type: CategoryType;
    color: string;
};

type ContactAlias = {
    id: number;
    name: string;
    normalized_name: string;
};

type ContactItem = {
    id: number;
    name: string;
    document: string | null;
    contact_type: ContactType;

    default_expense_category_id: number | null;
    default_income_category_id: number | null;

    default_expense_category: CategoryItem | null;
    default_income_category: CategoryItem | null;

    aliases: ContactAlias[];

    transactions_count: number;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedContacts = {
    data: ContactItem[];

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
    contact_type: string;
};

type FlashMessages = {
    success?: string;
    error?: string;
};

type PageProps = {
    flash?: FlashMessages;
};

type Props = {
    contacts: PaginatedContacts;
    categories: CategoryItem[];
    filters: Filters;
};

const breadcrumbs = [
    {
        title: "Contatos",
        href: "/contacts",
    },
];

export default function ContactsIndex({
    contacts,
    categories,
    filters,
}: Props) {
    const page = usePage<PageProps>();

    const successMessage = page.props.flash?.success ?? null;

    const errorMessage = page.props.flash?.error ?? null;

    const [search, setSearch] = useState(filters.search);

    const [contactType, setContactType] = useState(filters.contact_type);

    const [editingContact, setEditingContact] = useState<ContactItem | null>(
        null,
    );

    const [selectedContactIds, setSelectedContactIds] = useState<number[]>([]);

    const [isMergeModalOpen, setIsMergeModalOpen] = useState(false);

    useEffect(() => {
        setSearch(filters.search);
        setContactType(filters.contact_type);
    }, [filters]);

    useEffect(() => {
        const visibleIds = new Set(contacts.data.map((contact) => contact.id));

        setSelectedContactIds((current) =>
            current.filter((id) => visibleIds.has(id)),
        );
    }, [contacts.data]);

    const selectedContacts = useMemo(
        () =>
            contacts.data.filter((contact) =>
                selectedContactIds.includes(contact.id),
            ),
        [contacts.data, selectedContactIds],
    );

    const allPageContactsSelected =
        contacts.data.length > 0 &&
        contacts.data.every((contact) => selectedContactIds.includes(contact.id));

    function toggleContactSelection(contactId: number) {
        setSelectedContactIds((current) =>
            current.includes(contactId)
                ? current.filter((id) => id !== contactId)
                : [...current, contactId],
        );
    }

    function togglePageSelection() {
        if (allPageContactsSelected) {
            setSelectedContactIds([]);
            return;
        }

        setSelectedContactIds(contacts.data.map((contact) => contact.id));
    }

    function clearSelection() {
        setSelectedContactIds([]);
    }

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        router.get(
            "/contacts",
            {
                search: search || undefined,

                contact_type: contactType || undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function handleClearFilters() {
        setSearch("");
        setContactType("");

        router.get(
            "/contacts",
            {},
            {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contatos" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">Contatos</h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Gerencie as contrapartes, apelidos e mescle contatos que representam
                        a mesma pessoa ou empresa.
                    </p>
                </header>

                {successMessage && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                        {successMessage}
                    </div>
                )}

                {errorMessage && (
                    <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {errorMessage}
                    </div>
                )}

                <section className="rounded-xl border bg-card p-4 shadow-sm md:p-6">
                    <form
                        onSubmit={handleSubmit}
                        className="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px_auto]"
                    >
                        <div>
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
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Nome, documento ou apelido"
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                            />
                        </div>

                        <div>
                            <label
                                htmlFor="contact_type"
                                className="mb-1.5 block text-sm font-medium"
                            >
                                Tipo
                            </label>

                            <select
                                id="contact_type"
                                value={contactType}
                                onChange={(event) => setContactType(event.target.value)}
                                className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">Todos</option>

                                <option value="company">Empresas</option>

                                <option value="individual">Pessoas</option>

                                <option value="unknown">Não definido</option>
                            </select>
                        </div>

                        <div className="flex items-end gap-2">
                            <button
                                type="submit"
                                className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                            >
                                Filtrar
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
                    <div className="border-b p-4 md:p-6">
                        <h2 className="text-lg font-semibold">Contrapartes</h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            {contacts.total === 0
                                ? "Nenhum contato encontrado."
                                : `${contacts.total} contatos encontrados.`}
                        </p>
                    </div>

                    {selectedContacts.length > 0 && (
                        <div className="flex flex-col gap-3 border-b bg-primary/5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between md:px-6">
                            <div>
                                <p className="text-sm font-medium">
                                    {selectedContacts.length}{" "}
                                    {selectedContacts.length === 1
                                        ? "contato selecionado"
                                        : "contatos selecionados"}
                                </p>

                                <p className="text-xs text-muted-foreground">
                                    Selecione pelo menos dois contatos para mesclá-los.
                                </p>
                            </div>

                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={clearSelection}
                                    className="h-9 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                                >
                                    Limpar seleção
                                </button>

                                <button
                                    type="button"
                                    disabled={selectedContacts.length < 2}
                                    onClick={() => setIsMergeModalOpen(true)}
                                    className="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Mesclar selecionados
                                </button>
                            </div>
                        </div>
                    )}

                    {contacts.data.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            Nenhum contato corresponde aos filtros selecionados.
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1150px] text-left text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="w-12 px-4 py-3 text-center font-medium md:pl-6 md:pr-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Selecionar todos os contatos desta página"
                                                    checked={allPageContactsSelected}
                                                    onChange={togglePageSelection}
                                                    className="h-4 w-4 rounded border"
                                                />
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">Contato</th>

                                            <th className="px-4 py-3 font-medium md:px-6">Tipo</th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Categoria de despesa
                                            </th>

                                            <th className="px-4 py-3 font-medium md:px-6">
                                                Categoria de receita
                                            </th>

                                            <th className="px-4 py-3 text-center font-medium md:px-6">
                                                Transações
                                            </th>

                                            <th className="px-4 py-3 text-right font-medium md:px-6">
                                                Ações
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {contacts.data.map((contact) => (
                                            <tr
                                                key={contact.id}
                                                className="border-b last:border-b-0 hover:bg-muted/30"
                                            >
                                                <td className="w-12 px-4 py-4 text-center md:pl-6 md:pr-2">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Selecionar ${contact.name}`}
                                                        checked={selectedContactIds.includes(contact.id)}
                                                        onChange={() => toggleContactSelection(contact.id)}
                                                        className="h-4 w-4 rounded border"
                                                    />
                                                </td>

                                                <td className="px-4 py-4 md:px-6">
                                                    <div className="flex items-start gap-2">
                                                        <div className="min-w-0">
                                                            <div className="font-medium">{contact.name}</div>

                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                {formatStoredDocument(contact.document)}
                                                            </div>

                                                            {contact.aliases.length > 0 && (
                                                                <div className="mt-2 flex flex-wrap gap-1">
                                                                    {contact.aliases.slice(0, 3).map((alias) => (
                                                                        <span
                                                                            key={alias.id}
                                                                            className="rounded-full border bg-muted/40 px-2 py-0.5 text-[11px] text-muted-foreground"
                                                                        >
                                                                            {alias.name}
                                                                        </span>
                                                                    ))}

                                                                    {contact.aliases.length > 3 && (
                                                                        <span className="rounded-full border bg-muted/40 px-2 py-0.5 text-[11px] text-muted-foreground">
                                                                            +{contact.aliases.length - 3}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 md:px-6">
                                                    <ContactTypeBadge type={contact.contact_type} />
                                                </td>

                                                <td className="px-4 py-4 md:px-6">
                                                    {contact.default_expense_category ? (
                                                        <CategoryBadge
                                                            category={contact.default_expense_category}
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Não definida
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 md:px-6">
                                                    {contact.default_income_category ? (
                                                        <CategoryBadge
                                                            category={contact.default_income_category}
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Não definida
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-center md:px-6">
                                                    {contact.transactions_count}
                                                </td>

                                                <td className="px-4 py-4 text-right md:px-6">
                                                    <button
                                                        type="button"
                                                        onClick={() => setEditingContact(contact)}
                                                        className="rounded-md border px-3 py-2 text-sm font-medium hover:bg-muted"
                                                    >
                                                        Editar
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination contacts={contacts} />
                        </>
                    )}
                </section>
            </div>

            {editingContact && (
                <ContactEditModal
                    contact={editingContact}
                    categories={categories}
                    onClose={() => setEditingContact(null)}
                />
            )}

            {isMergeModalOpen && selectedContacts.length >= 2 && (
                <MergeSelectedContactsModal
                    contacts={selectedContacts}
                    onClose={() => setIsMergeModalOpen(false)}
                    onMerged={() => {
                        setIsMergeModalOpen(false);
                        clearSelection();
                    }}
                />
            )}
        </AppLayout>
    );
}

function MergeSelectedContactsModal({
    contacts,
    onClose,
    onMerged,
}: {
    contacts: ContactItem[];
    onClose: () => void;
    onMerged: () => void;
}) {
    const recommendedTarget = useMemo(
        () =>
            [...contacts].sort(
                (first, second) =>
                    getContactCompletenessScore(second) -
                    getContactCompletenessScore(first),
            )[0],
        [contacts],
    );

    const [targetContactId, setTargetContactId] = useState<number>(
        recommendedTarget?.id ?? contacts[0]?.id,
    );

    const [isSubmitting, setIsSubmitting] = useState(false);

    const [error, setError] = useState<string | null>(null);

    const targetContact =
        contacts.find((contact) => contact.id === targetContactId) ?? contacts[0];

    const sourceContacts = contacts.filter(
        (contact) => contact.id !== targetContact?.id,
    );

    const hasIncompatibleTypes =
        new Set(
            contacts
                .map((contact) => contact.contact_type)
                .filter((type): type is Exclude<ContactType, null> => type !== null),
        ).size > 1;

    function handleMerge() {
        if (!targetContact) {
            setError("Selecione o contato que deve permanecer.");
            return;
        }

        if (hasIncompatibleTypes) {
            setError("Não é possível mesclar pessoas e empresas na mesma operação.");
            return;
        }

        setIsSubmitting(true);
        setError(null);

        router.post(
            "/contacts/merge-many",
            {
                contact_ids: contacts.map((contact) => contact.id),

                target_contact_id: targetContact.id,
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    onMerged();
                },

                onError: (errors) => {
                    const firstError =
                        errors.contact_ids ||
                        errors.target_contact_id ||
                        errors.contact_type ||
                        errors.document ||
                        errors.aliases ||
                        errors.contacts ||
                        "Não foi possível mesclar os contatos.";

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
                if (event.target === event.currentTarget && !isSubmitting) {
                    onClose();
                }
            }}
        >
            <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl border bg-background shadow-xl">
                <div className="border-b p-5">
                    <h2 className="text-lg font-semibold">
                        Mesclar contatos selecionados
                    </h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Escolha o contato principal. Os demais serão removidos, mas seus
                        nomes, apelidos e transações serão preservados.
                    </p>
                </div>

                <div className="space-y-5 p-5">
                    {hasIncompatibleTypes && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            A seleção contém pessoas e empresas. Remova os contatos
                            incompatíveis antes de continuar.
                        </div>
                    )}

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Qual contato deve permanecer?
                        </legend>

                        <div className="grid gap-3 md:grid-cols-2">
                            {contacts.map((contact) => {
                                const selected = contact.id === targetContactId;

                                const recommended = contact.id === recommendedTarget?.id;

                                return (
                                    <label
                                        key={contact.id}
                                        className={`cursor-pointer rounded-xl border p-4 transition-colors ${selected
                                                ? "border-primary bg-primary/5"
                                                : "hover:bg-muted/40"
                                            }`}
                                    >
                                        <div className="flex items-start gap-3">
                                            <input
                                                type="radio"
                                                name="target_contact_id"
                                                value={contact.id}
                                                checked={selected}
                                                onChange={() => {
                                                    setTargetContactId(contact.id);
                                                    setError(null);
                                                }}
                                                className="mt-1"
                                            />

                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{contact.name}</span>

                                                    {recommended && (
                                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary">
                                                            Mais completo
                                                        </span>
                                                    )}
                                                </div>

                                                <dl className="mt-3 space-y-1.5 text-xs">
                                                    <div className="flex justify-between gap-4">
                                                        <dt className="text-muted-foreground">Tipo</dt>
                                                        <dd className="font-medium">
                                                            {formatContactType(contact.contact_type)}
                                                        </dd>
                                                    </div>

                                                    <div className="flex justify-between gap-4">
                                                        <dt className="text-muted-foreground">Documento</dt>
                                                        <dd className="text-right font-medium">
                                                            {formatStoredDocument(contact.document)}
                                                        </dd>
                                                    </div>

                                                    <div className="flex justify-between gap-4">
                                                        <dt className="text-muted-foreground">
                                                            Transações
                                                        </dt>
                                                        <dd className="font-medium">
                                                            {contact.transactions_count}
                                                        </dd>
                                                    </div>

                                                    <div className="flex justify-between gap-4">
                                                        <dt className="text-muted-foreground">Apelidos</dt>
                                                        <dd className="font-medium">
                                                            {contact.aliases.length}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        </div>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>

                    {targetContact && (
                        <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                            <p className="font-medium">Resultado da mesclagem</p>

                            <ul className="mt-2 space-y-1 text-muted-foreground">
                                <li>
                                    <strong className="text-foreground">
                                        {targetContact.name}
                                    </strong>{" "}
                                    permanecerá como contato principal.
                                </li>

                                <li>
                                    {sourceContacts.length}{" "}
                                    {sourceContacts.length === 1
                                        ? "contato será removido"
                                        : "contatos serão removidos"}
                                    .
                                </li>

                                <li>
                                    Os nomes e apelidos dos contatos removidos serão associados ao
                                    contato principal.
                                </li>

                                <li>
                                    Todas as transações serão transferidas para o contato
                                    principal.
                                </li>

                                <li>
                                    Campos vazios do principal poderão herdar dados dos contatos
                                    removidos.
                                </li>
                            </ul>
                        </div>
                    )}

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
                            type="button"
                            onClick={handleMerge}
                            disabled={isSubmitting || hasIncompatibleTypes || !targetContact}
                            className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting
                                ? "Mesclando..."
                                : `Mesclar e manter “${targetContact?.name ?? ""}”`}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function getContactCompletenessScore(contact: ContactItem): number {
    let score = contact.name.trim().length;

    if (contact.document) {
        score += 30;
    }

    if (contact.contact_type) {
        score += 20;
    }

    if (contact.default_expense_category_id) {
        score += 10;
    }

    if (contact.default_income_category_id) {
        score += 10;
    }

    score += Math.min(contact.aliases.length, 10);

    score += Math.min(contact.transactions_count, 20);

    return score;
}

function ContactEditModal({
    contact,
    categories,
    onClose,
}: {
    contact: ContactItem;
    categories: CategoryItem[];
    onClose: () => void;
}) {
    const expenseCategories = useMemo(
        () => categories.filter((category) => category.type === "expense"),
        [categories],
    );

    const incomeCategories = useMemo(
        () => categories.filter((category) => category.type === "income"),
        [categories],
    );

    const [name, setName] = useState(contact.name);

    const [contactType, setContactType] = useState<EditableContactType>(
        contact.contact_type ?? "",
    );

    const [document, setDocument] = useState(
        getEditableDocument(contact.document, contact.contact_type),
    );

    const [expenseCategoryId, setExpenseCategoryId] = useState(
        contact.default_expense_category_id?.toString() ?? "",
    );

    const [incomeCategoryId, setIncomeCategoryId] = useState(
        contact.default_income_category_id?.toString() ?? "",
    );

    const [isSubmitting, setIsSubmitting] = useState(false);

    const [error, setError] = useState<string | null>(null);

    function handleContactTypeChange(newType: EditableContactType) {
        if (newType !== contactType) {
            setDocument("");
        }

        setContactType(newType);
        setError(null);
    }

    function handleDocumentChange(value: string) {
        const maximumLength = contactType === "company" ? 14 : 11;

        const digits = value.replace(/\D/g, "").slice(0, maximumLength);

        setDocument(digits);
        setError(null);
    }

    function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const normalizedName = name.trim();

        if (!normalizedName) {
            setError("Informe o nome do contato.");
            return;
        }

        const documentError = validateDocumentOnClient(document, contactType);

        if (documentError) {
            setError(documentError);
            return;
        }

        setIsSubmitting(true);
        setError(null);

        router.patch(
            `/contacts/${contact.id}`,
            {
                name: normalizedName,

                document: document || null,

                contact_type: contactType || null,

                default_expense_category_id: expenseCategoryId
                    ? Number(expenseCategoryId)
                    : null,

                default_income_category_id: incomeCategoryId
                    ? Number(incomeCategoryId)
                    : null,
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    onClose();
                },

                onError: (errors) => {
                    const firstError =
                        errors.name ||
                        errors.document ||
                        errors.contact_type ||
                        errors.default_expense_category_id ||
                        errors.default_income_category_id ||
                        "Não foi possível atualizar o contato.";

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
                if (event.target === event.currentTarget && !isSubmitting) {
                    onClose();
                }
            }}
        >
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border bg-background shadow-xl">
                <div className="border-b p-5">
                    <h2 className="text-lg font-semibold">Editar contato</h2>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Atualize os dados e as categorias padrão desta contraparte.
                    </p>
                </div>

                <form onSubmit={handleSubmit} className="space-y-5 p-5">
                    <div>
                        <label
                            htmlFor="contact_name"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            Nome
                        </label>

                        <input
                            id="contact_name"
                            type="text"
                            value={name}
                            maxLength={255}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Nome do contato"
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="contact_type_edit"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            Tipo do contato
                        </label>

                        <select
                            id="contact_type_edit"
                            value={contactType}
                            onChange={(event) =>
                                handleContactTypeChange(
                                    event.target.value as EditableContactType,
                                )
                            }
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Não definido</option>

                            <option value="company">Empresa</option>

                            <option value="individual">Pessoa</option>
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="contact_document"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            {getDocumentLabel(contactType)}
                        </label>

                        <input
                            id="contact_document"
                            type="text"
                            inputMode="numeric"
                            value={formatDocumentInput(document, contactType)}
                            maxLength={contactType === "company" ? 18 : 14}
                            disabled={!contactType}
                            onChange={(event) => handleDocumentChange(event.target.value)}
                            placeholder={getDocumentPlaceholder(contactType)}
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                        />

                        <p className="mt-1 text-xs text-muted-foreground">
                            {getDocumentHelp(contactType)}
                        </p>
                    </div>

                    <div>
                        <label
                            htmlFor="default_expense_category_id"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            Categoria padrão de despesa
                        </label>

                        <select
                            id="default_expense_category_id"
                            value={expenseCategoryId}
                            onChange={(event) => setExpenseCategoryId(event.target.value)}
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Não definida</option>

                            {expenseCategories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="default_income_category_id"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            Categoria padrão de receita
                        </label>

                        <select
                            id="default_income_category_id"
                            value={incomeCategoryId}
                            onChange={(event) => setIncomeCategoryId(event.target.value)}
                            className="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">Não definida</option>

                            {incomeCategories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                    </div>

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
                            disabled={isSubmitting || !name.trim()}
                            className="h-10 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting ? "Salvando..." : "Salvar alterações"}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ContactTypeBadge({ type }: { type: ContactType }) {
    if (type === "company") {
        return (
            <span className="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800">
                Empresa
            </span>
        );
    }

    if (type === "individual") {
        return (
            <span className="inline-flex rounded-full bg-purple-100 px-2.5 py-1 text-xs font-medium text-purple-800">
                Pessoa
            </span>
        );
    }

    return (
        <span className="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
            Não definido
        </span>
    );
}

function CategoryBadge({ category }: { category: CategoryItem }) {
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

function Pagination({ contacts }: { contacts: PaginatedContacts }) {
    if (contacts.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t p-4 md:flex-row md:items-center md:justify-between md:px-6">
            <p className="text-sm text-muted-foreground">
                Exibindo {contacts.from ?? 0} até {contacts.to ?? 0} de {contacts.total}
            </p>

            <div className="flex flex-wrap gap-1">
                {contacts.links.map((link, index) => (
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

function getEditableDocument(
    document: string | null,
    contactType: ContactType,
): string {
    if (!document) {
        return "";
    }

    if (contactType === "individual" && /^\d{11}$/.test(document)) {
        return document;
    }

    if (contactType === "company" && /^\d{14}$/.test(document)) {
        return document;
    }

    return "";
}

function validateDocumentOnClient(
    document: string,
    contactType: EditableContactType,
): string | null {
    if (!document) {
        return null;
    }

    if (!contactType) {
        return "Selecione o tipo do contato antes de informar o documento.";
    }

    if (contactType === "individual" && document.length !== 11) {
        return "Informe um CPF completo com 11 dígitos.";
    }

    if (contactType === "company" && document.length !== 14) {
        return "Informe um CNPJ completo com 14 dígitos.";
    }

    return null;
}

function getDocumentLabel(contactType: EditableContactType): string {
    if (contactType === "company") {
        return "CNPJ";
    }

    if (contactType === "individual") {
        return "CPF";
    }

    return "Documento";
}

function getDocumentPlaceholder(contactType: EditableContactType): string {
    if (contactType === "company") {
        return "00.000.000/0000-00";
    }

    if (contactType === "individual") {
        return "000.000.000-00";
    }

    return "Selecione primeiro o tipo";
}

function getDocumentHelp(contactType: EditableContactType): string {
    if (contactType === "company") {
        return "Informe um CNPJ completo e válido ou deixe vazio.";
    }

    if (contactType === "individual") {
        return "Informe um CPF completo e válido ou deixe vazio.";
    }

    return "Selecione Empresa ou Pessoa antes de informar o documento.";
}

function formatDocumentInput(
    document: string,
    contactType: EditableContactType,
): string {
    const digits = document.replace(/\D/g, "");

    if (contactType === "individual") {
        return digits
            .replace(/^(\d{3})(\d)/, "$1.$2")
            .replace(/^(\d{3})\.(\d{3})(\d)/, "$1.$2.$3")
            .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, "$1.$2.$3-$4")
            .slice(0, 14);
    }

    if (contactType === "company") {
        return digits
            .replace(/^(\d{2})(\d)/, "$1.$2")
            .replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3")
            .replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, "$1.$2.$3/$4")
            .replace(/(\d{4})(\d{1,2})$/, "$1-$2")
            .slice(0, 18);
    }

    return digits;
}

function formatStoredDocument(document: string | null): string {
    if (!document) {
        return "Não informado";
    }

    if (/^\d{11}$/.test(document)) {
        return [
            document.slice(0, 3),
            ".",
            document.slice(3, 6),
            ".",
            document.slice(6, 9),
            "-",
            document.slice(9, 11),
        ].join("");
    }

    if (/^\d{14}$/.test(document)) {
        return [
            document.slice(0, 2),
            ".",
            document.slice(2, 5),
            ".",
            document.slice(5, 8),
            "/",
            document.slice(8, 12),
            "-",
            document.slice(12, 14),
        ].join("");
    }

    return "Incompleto na importação";
}

function formatContactType(type: ContactType): string {
    if (type === "company") {
        return "Empresa";
    }

    if (type === "individual") {
        return "Pessoa";
    }

    return "Não definido";
}
