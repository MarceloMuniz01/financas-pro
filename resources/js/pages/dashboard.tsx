import { Head } from '@inertiajs/react';
import { dashboard } from '@/routes';

type Transaction = {
    id: number;
    transaction_date: string;
    description: string;
    amount: string;
    type: 'income' | 'expense';
};

type DashboardProps = {
    transactions: Transaction[];
    total: string | number;
};

const currencyFormatter = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export default function Dashboard({ transactions, total }: DashboardProps) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                        <p className="text-sm text-muted-foreground">Saldo</p>

                        <p className="mt-2 text-2xl font-semibold">
                            {currencyFormatter.format(Number(total))}
                        </p>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                        <p className="text-sm text-muted-foreground">
                            Transações recentes
                        </p>

                        <p className="mt-2 text-2xl font-semibold">
                            {transactions.length}
                        </p>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                        <p className="text-sm text-muted-foreground">
                            Visão geral
                        </p>

                        <p className="mt-2 text-sm text-muted-foreground">
                            Resumo das suas movimentações
                        </p>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div className="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 className="font-semibold">Últimas transações</h2>
                    </div>

                    {transactions.length === 0 ? (
                        <div className="p-6 text-sm text-muted-foreground">
                            Nenhuma transação encontrada.
                        </div>
                    ) : (
                        <div className="divide-y divide-sidebar-border/70">
                            {transactions.map((transaction) => (
                                <div
                                    key={transaction.id}
                                    className="flex items-center justify-between gap-4 p-4"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {transaction.description}
                                        </p>

                                        <p className="text-sm text-muted-foreground">
                                            {new Date(
                                                transaction.transaction_date,
                                            ).toLocaleDateString('pt-BR')}
                                        </p>
                                    </div>

                                    <div
                                        className={
                                            transaction.type === 'income'
                                                ? 'font-medium text-green-600'
                                                : 'font-medium text-red-600'
                                        }
                                    >
                                        {currencyFormatter.format(
                                            Number(transaction.amount),
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
