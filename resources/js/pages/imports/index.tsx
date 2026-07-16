import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    ChangeEvent,
    FormEvent,
    useCallback,
    useEffect,
    useState,
} from 'react';

type ImportStatus = 'pending' | 'processing' | 'done' | 'failed';

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

type UploadResponse = {
    message: string;
    import?: ImportItem;
};

const breadcrumbs = [
    {
        title: 'Importações',
        href: '/imports',
    },
];

export default function ImportsIndex() {
    const [file, setFile] = useState<File | null>(null);
    const [imports, setImports] = useState<ImportItem[]>([]);

    const [isLoading, setIsLoading] = useState(true);
    const [isUploading, setIsUploading] = useState(false);

    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const loadImports = useCallback(async () => {
        try {
            const response = await fetch('/api/imports', {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Não foi possível carregar as importações.');
            }

            const data: ImportItem[] = await response.json();

            setImports(data);
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Erro ao carregar as importações.',
            );
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        loadImports();
    }, [loadImports]);

    useEffect(() => {
        const hasActiveImport = imports.some(
            (item) =>
                item.status === 'pending' ||
                item.status === 'processing',
        );

        if (!hasActiveImport) {
            return;
        }

        const intervalId = window.setInterval(() => {
            loadImports();
        }, 2000);

        return () => {
            window.clearInterval(intervalId);
        };
    }, [imports, loadImports]);

    function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
        const selectedFile = event.target.files?.[0] ?? null;

        setFile(selectedFile);
        setMessage(null);
        setError(null);
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!file) {
            setError('Selecione um arquivo CSV.');
            return;
        }

        setIsUploading(true);
        setMessage(null);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('file', file);

            const response = await fetch('/api/imports', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                },
                body: formData,
            });

            const data: UploadResponse = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || 'Não foi possível enviar o arquivo.',
                );
            }

            setMessage(data.message);
            setFile(null);

            const input = document.getElementById(
                'statement-file',
            ) as HTMLInputElement | null;

            if (input) {
                input.value = '';
            }

            await loadImports();
        } catch (requestError) {
            setError(
                requestError instanceof Error
                    ? requestError.message
                    : 'Erro ao enviar o arquivo.',
            );
        } finally {
            setIsUploading(false);
        }
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
                            Envie um extrato CSV do Nubank ou Banco Inter.
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
                                id="statement-file"
                                type="file"
                                accept=".csv,.txt,text/csv"
                                onChange={handleFileChange}
                                disabled={isUploading}
                                className="block w-full rounded-md border px-3 py-2 text-sm"
                            />

                            {file && (
                                <p className="text-sm text-muted-foreground">
                                    Selecionado: {file.name}
                                </p>
                            )}
                        </div>

                        {message && (
                            <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                                {message}
                            </div>
                        )}

                        {error && (
                            <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                {error}
                            </div>
                        )}

                        <div>
                            <button
                                type="submit"
                                disabled={!file || isUploading}
                                className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isUploading
                                    ? 'Enviando...'
                                    : 'Importar extrato'}
                            </button>
                        </div>
                    </form>
                </section>

                <section className="rounded-xl border bg-card shadow-sm">
                    <div className="border-b p-6">
                        <h2 className="text-lg font-semibold">
                            Importações recentes
                        </h2>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Acompanhe o processamento dos arquivos enviados.
                        </p>
                    </div>

                    {isLoading ? (
                        <div className="p-6 text-sm text-muted-foreground">
                            Carregando importações...
                        </div>
                    ) : imports.length === 0 ? (
                        <div className="p-6 text-sm text-muted-foreground">
                            Nenhuma importação realizada.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-6 py-3 font-medium">
                                            Arquivo
                                        </th>

                                        <th className="px-6 py-3 font-medium">
                                            Banco
                                        </th>

                                        <th className="px-6 py-3 font-medium">
                                            Status
                                        </th>

                                        <th className="px-6 py-3 font-medium">
                                            Enviado em
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {imports.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="border-b last:border-b-0"
                                        >
                                            <td className="px-6 py-4">
                                                <div className="font-medium">
                                                    {item.original_filename ||
                                                        'Arquivo sem nome'}
                                                </div>

                                                {item.error_message && (
                                                    <div className="mt-1 max-w-md text-xs text-red-600">
                                                        {item.error_message}
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-6 py-4 capitalize">
                                                {formatBank(item.bank)}
                                            </td>

                                            <td className="px-6 py-4">
                                                <StatusBadge
                                                    status={item.status}
                                                />
                                            </td>

                                            <td className="px-6 py-4 text-muted-foreground">
                                                {formatDate(item.created_at)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function StatusBadge({ status }: { status: ImportStatus }) {
    const labels: Record<ImportStatus, string> = {
        pending: 'Aguardando',
        processing: 'Processando',
        done: 'Concluído',
        failed: 'Falhou',
    };

    const classes: Record<ImportStatus, string> = {
        pending: 'bg-yellow-100 text-yellow-800',
        processing: 'bg-blue-100 text-blue-800',
        done: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800',
    };

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${classes[status]}`}
        >
            {labels[status]}
        </span>
    );
}

function formatBank(bank: string | null): string {
    if (!bank) {
        return 'Não identificado';
    }

    const banks: Record<string, string> = {
        nubank: 'Nubank',
        inter: 'Banco Inter',
    };

    return banks[bank] ?? bank;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}