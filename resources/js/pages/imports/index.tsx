import AppLayout from '@/layouts/app-layout';
import {
    Head,
    router,
    usePage,
} from '@inertiajs/react';
import {
    ChangeEvent,
    FormEvent,
    useEffect,
    useRef,
    useState,
} from 'react';

type ImportStatus =
    | 'pending'
    | 'processing'
    | 'done'
    | 'failed';

type ImportItem = {
    id: number;
    bank: string | null;
    source: string;
    original_filename: string | null;
    status: ImportStatus;
    error_message: string | null;
    processed_at: string | null;
    created_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedImports = {
    data: ImportItem[];

    current_page: number;
    last_page: number;
    per_page: number;
    total: number;

    from: number | null;
    to: number | null;

    links: PaginationLink[];
};

type FlashMessages = {
    success?: string;
    error?: string;
};

type PageProps = {
    flash?: FlashMessages;
};

type Props = {
    imports: PaginatedImports;
};

const breadcrumbs = [
    {
        title: 'Importações',
        href: '/imports',
    },
];

export default function ImportsIndex({
    imports,
}: Props) {
    const page = usePage<PageProps>();

    const fileInputRef =
        useRef<HTMLInputElement | null>(null);

    const [file, setFile] =
        useState<File | null>(null);

    const [isUploading, setIsUploading] =
        useState(false);

    const [clientError, setClientError] =
        useState<string | null>(null);

    const successMessage =
        page.props.flash?.success ?? null;

    const serverError =
        page.props.flash?.error ?? null;

    /*
     * Caso algum processamento antigo ainda esteja
     * marcado como pending ou processing, atualiza somente
     * a prop imports periodicamente.
     *
     * No processamento síncrono atual normalmente a resposta
     * já volta como done ou failed.
     */
    const hasActiveImport = imports.data.some(
        (item) =>
            item.status === 'pending'
            || item.status === 'processing',
    );

    useEffect(() => {
        if (!hasActiveImport) {
            return;
        }

        const intervalId = window.setInterval(
            () => {
                router.reload({
                    only: ['imports'],
                    preserveScroll: true,
                    preserveState: true,
                });
            },
            3000,
        );

        return () => {
            window.clearInterval(intervalId);
        };
    }, [hasActiveImport]);

    function handleFileChange(
        event: ChangeEvent<HTMLInputElement>,
    ) {
        const selectedFile =
            event.target.files?.[0] ?? null;

        setFile(selectedFile);
        setClientError(null);
    }

    function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ) {
        event.preventDefault();

        if (!file) {
            setClientError(
                'Selecione um arquivo de extrato.',
            );

            return;
        }

        const extension =
            file.name
                .split('.')
                .pop()
                ?.toLowerCase()
            ?? '';

        const acceptedExtensions = [
            'csv',
            'txt',
            'ofx',
        ];

        if (
            !acceptedExtensions.includes(extension)
        ) {
            setClientError(
                'Selecione um arquivo CSV, TXT ou OFX.',
            );

            return;
        }

        setIsUploading(true);
        setClientError(null);

        router.post(
            '/imports',
            {
                file,
            },
            {
                forceFormData: true,
                preserveScroll: true,

                onSuccess: () => {
                    setFile(null);

                    if (fileInputRef.current) {
                        fileInputRef.current.value = '';
                    }
                },

                onError: (errors) => {
                    const firstError =
                        errors.file
                        || errors.import
                        || errors.message
                        || 'Não foi possível importar o arquivo.';

                    setClientError(firstError);
                },

                onFinish: () => {
                    setIsUploading(false);
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Importações" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <section className="rounded-xl border bg-card p-6 shadow-sm">
                    <div className="mb-6">
                        <h1 className="text-2xl font-semibold">
                            Importar extrato
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Envie seu extrato bancário. O banco será
                            identificado automaticamente pelo conteúdo
                            do arquivo.
                        </p>
                    </div>

                    <form
                        onSubmit={handleSubmit}
                        className="flex flex-col gap-4"
                    >
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="statement-file"
                                className="text-sm font-medium"
                            >
                                Arquivo do extrato
                            </label>

                            <input
                                ref={fileInputRef}
                                id="statement-file"
                                type="file"
                                accept=".csv,.txt,.ofx,text/csv,text/plain,application/x-ofx"
                                onChange={handleFileChange}
                                disabled={isUploading}
                                className="block w-full rounded-md border bg-background px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                            />

                            <p className="text-xs text-muted-foreground">
                                Formatos aceitos: CSV, TXT e OFX.
                            </p>

                            {file && (
                                <div className="rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Arquivo selecionado:
                                    </span>{' '}

                                    <span className="font-medium">
                                        {file.name}
                                    </span>

                                    <span className="ml-2 text-xs text-muted-foreground">
                                        ({formatFileSize(file.size)})
                                    </span>
                                </div>
                            )}
                        </div>

                        {successMessage && (
                            <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                                {successMessage}
                            </div>
                        )}

                        {(clientError || serverError) && (
                            <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                {clientError || serverError}
                            </div>
                        )}

                        <div>
                            <button
                                type="submit"
                                disabled={
                                    !file
                                    || isUploading
                                }
                                className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isUploading
                                    ? 'Importando extrato...'
                                    : 'Importar extrato'}
                            </button>
                        </div>

                        {isUploading && (
                            <div className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                                O arquivo está sendo processado. Não
                                feche nem atualize esta página até a
                                importação terminar.
                            </div>
                        )}
                    </form>
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <div className="border-b p-6">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Importações recentes
                                </h2>

                                <p className="mt-1 text-sm text-muted-foreground">
                                    Histórico dos arquivos enviados e
                                    processados.
                                </p>
                            </div>

                            <span className="text-sm text-muted-foreground">
                                {imports.total === 1
                                    ? '1 importação'
                                    : `${imports.total} importações`}
                            </span>
                        </div>
                    </div>

                    {imports.data.length === 0 ? (
                        <div className="p-8 text-center">
                            <p className="text-sm font-medium">
                                Nenhuma importação realizada
                            </p>

                            <p className="mt-1 text-sm text-muted-foreground">
                                Envie seu primeiro extrato usando o
                                formulário acima.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[800px] text-left text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-6 py-3 font-medium">
                                                Arquivo
                                            </th>

                                            <th className="px-6 py-3 font-medium">
                                                Banco
                                            </th>

                                            <th className="px-6 py-3 font-medium">
                                                Origem
                                            </th>

                                            <th className="px-6 py-3 font-medium">
                                                Status
                                            </th>

                                            <th className="px-6 py-3 font-medium">
                                                Enviado em
                                            </th>

                                            <th className="px-6 py-3 font-medium">
                                                Finalizado em
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {imports.data.map(
                                            (item) => (
                                                <tr
                                                    key={item.id}
                                                    className="border-b last:border-b-0 hover:bg-muted/30"
                                                >
                                                    <td className="px-6 py-4">
                                                        <div className="font-medium">
                                                            {item.original_filename
                                                                || 'Arquivo sem nome'}
                                                        </div>

                                                        {item.error_message && (
                                                            <div className="mt-1 max-w-md text-xs leading-relaxed text-red-600">
                                                                {
                                                                    item.error_message
                                                                }
                                                            </div>
                                                        )}
                                                    </td>

                                                    <td className="px-6 py-4">
                                                        {formatBank(
                                                            item.bank,
                                                        )}
                                                    </td>

                                                    <td className="px-6 py-4 uppercase text-muted-foreground">
                                                        {item.source}
                                                    </td>

                                                    <td className="px-6 py-4">
                                                        <StatusBadge
                                                            status={
                                                                item.status
                                                            }
                                                        />
                                                    </td>

                                                    <td className="px-6 py-4 text-muted-foreground">
                                                        {formatDate(
                                                            item.created_at,
                                                        )}
                                                    </td>

                                                    <td className="px-6 py-4 text-muted-foreground">
                                                        {item.processed_at
                                                            ? formatDate(
                                                                item.processed_at,
                                                            )
                                                            : '—'}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <Pagination
                                imports={imports}
                            />
                        </>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function StatusBadge({
    status,
}: {
    status: ImportStatus;
}) {
    const labels: Record<ImportStatus, string> = {
        pending: 'Aguardando',
        processing: 'Processando',
        done: 'Concluído',
        failed: 'Falhou',
    };

    const classes: Record<ImportStatus, string> = {
        pending:
            'bg-yellow-100 text-yellow-800',

        processing:
            'bg-blue-100 text-blue-800',

        done:
            'bg-green-100 text-green-800',

        failed:
            'bg-red-100 text-red-800',
    };

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${classes[status]}`}
        >
            {labels[status]}
        </span>
    );
}

function Pagination({
    imports,
}: {
    imports: PaginatedImports;
}) {
    if (imports.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t p-4 md:flex-row md:items-center md:justify-between md:px-6">
            <p className="text-sm text-muted-foreground">
                Exibindo {imports.from ?? 0} até{' '}
                {imports.to ?? 0} de {imports.total}
            </p>

            <div className="flex flex-wrap gap-1">
                {imports.links.map(
                    (link, index) => (
                        <button
                            key={`${link.label}-${index}`}
                            type="button"
                            disabled={!link.url}
                            onClick={() => {
                                if (!link.url) {
                                    return;
                                }

                                router.visit(
                                    link.url,
                                    {
                                        preserveScroll:
                                            true,

                                        preserveState:
                                            true,
                                    },
                                );
                            }}
                            className={`min-w-9 rounded-md border px-3 py-2 text-sm ${link.active
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

function formatBank(
    bank: string | null,
): string {
    if (!bank) {
        return 'Não identificado';
    }

    const banks: Record<string, string> = {
        nubank: 'Nubank',
        inter: 'Banco Inter',
        santander: 'Santander',
    };

    return banks[bank] ?? bank;
}

function formatDate(
    value: string,
): string {
    return new Intl.DateTimeFormat(
        'pt-BR',
        {
            dateStyle: 'short',
            timeStyle: 'short',
        },
    ).format(new Date(value));
}

function formatFileSize(
    bytes: number,
): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(
            bytes / 1024
        ).toFixed(1)} KB`;
    }

    return `${(
        bytes
        / 1024
        / 1024
    ).toFixed(1)} MB`;
}