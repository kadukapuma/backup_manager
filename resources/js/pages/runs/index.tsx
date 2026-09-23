import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDateTime, formatSeconds } from '@/lib/format';
import { type BreadcrumbItem, type Option, type Paginated } from '@/types';
import { type RunRow } from '@/types/models';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    status: string;
    trigger: string;
    plan: string;
    from: string;
    to: string;
}

interface Props {
    runs: Paginated<RunRow>;
    filters: Filters;
    statuses: Option[];
    triggers: Option[];
    plans: Option[];
    hasActive: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Runs', href: '/runs' }];

export default function RunsIndex({ runs, filters, statuses, triggers, plans, hasActive }: Props) {
    const [values, setValues] = useState<Filters>(filters);
    usePollWhile(hasActive, ['runs', 'hasActive']);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== ''));
        router.get(route('runs.index'), query, { preserveState: true, preserveScroll: true });
    };

    const set = (key: keyof Filters, value: string) => setValues((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Runs" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Backup runs" description="History of scheduled, manual and pre-restore backups." />

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={apply} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                            <div className="grid gap-1">
                                <Label htmlFor="f-status">Status</Label>
                                <NativeSelect id="f-status" value={values.status} onChange={(e) => set('status', e.target.value)}>
                                    <option value="">Any</option>
                                    {statuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-trigger">Trigger</Label>
                                <NativeSelect id="f-trigger" value={values.trigger} onChange={(e) => set('trigger', e.target.value)}>
                                    <option value="">Any</option>
                                    {triggers.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-plan">Plan</Label>
                                <NativeSelect id="f-plan" value={values.plan} onChange={(e) => set('plan', e.target.value)}>
                                    <option value="">Any</option>
                                    {plans.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-from">From</Label>
                                <Input id="f-from" type="date" value={values.from} onChange={(e) => set('from', e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-to">To</Label>
                                <Input id="f-to" type="date" value={values.to} onChange={(e) => set('to', e.target.value)} />
                            </div>
                            <Button type="submit">Filter</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>#</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Plan / trigger</TableHead>
                                    <TableHead>Connection</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Databases</TableHead>
                                    <TableHead className="text-right">Size</TableHead>
                                    <TableHead className="text-right">Duration</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-muted-foreground py-10 text-center">
                                            No runs yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {runs.data.map((run) => (
                                    <TableRow key={run.id}>
                                        <TableCell>
                                            <Link href={route('runs.show', run.id)} className="font-medium underline-offset-4 hover:underline">
                                                #{run.id}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">{formatDateTime(run.started_at ?? run.created_at)}</TableCell>
                                        <TableCell>
                                            <div>{run.plan ?? 'Manual'}</div>
                                            <div className="text-muted-foreground text-xs">
                                                {run.trigger.replace('_', ' ')}
                                                {run.triggered_by ? ` by ${run.triggered_by}` : ''}
                                            </div>
                                        </TableCell>
                                        <TableCell>{run.connection ?? '—'}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={run.status} />
                                            {run.summary.error && <div className="mt-1 max-w-xs text-xs text-red-600">{run.summary.error}</div>}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {run.summary.succeeded !== undefined ? `${run.summary.succeeded}/${run.files_count}` : run.files_count}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatBytes(run.summary.bytes ?? null)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatSeconds(run.summary.duration_seconds)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <Pagination page={runs} />
            </div>
        </AppLayout>
    );
}
