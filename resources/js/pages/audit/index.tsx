import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { type BreadcrumbItem, type Option, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface AuditRow {
    id: number;
    action: string;
    user: { id: number; name: string; email: string } | null;
    subject_type: string | null;
    subject_id: number | null;
    ip: string | null;
    user_agent: string | null;
    meta: Record<string, unknown> | null;
    created_at: string;
}

interface Filters {
    user_id: string;
    action: string;
    subject_type: string;
    from: string;
    to: string;
}

interface Props {
    logs: Paginated<AuditRow>;
    filters: Filters;
    actions: Option[];
    users: Option[];
    subjectTypes: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/audit-log' }];

export default function AuditIndex({ logs, filters, actions, users, subjectTypes }: Props) {
    const [values, setValues] = useState<Filters>(filters);
    const [expanded, setExpanded] = useState<number | null>(null);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== ''));
        router.get(route('audit.index'), query, { preserveState: true, preserveScroll: true });
    };

    const reset = () => {
        const empty: Filters = { user_id: '', action: '', subject_type: '', from: '', to: '' };
        setValues(empty);
        router.get(route('audit.index'));
    };

    const set = (key: keyof Filters, value: string) => setValues((prev) => ({ ...prev, [key]: value }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="Audit log" description="Every sensitive action, with the user, IP address and time." />

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={apply} className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                            <div className="grid gap-1">
                                <Label htmlFor="f-user">User</Label>
                                <NativeSelect id="f-user" value={values.user_id} onChange={(e) => set('user_id', e.target.value)}>
                                    <option value="">All users</option>
                                    {users.map((u) => (
                                        <option key={u.value} value={u.value}>
                                            {u.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-action">Action</Label>
                                <NativeSelect id="f-action" value={values.action} onChange={(e) => set('action', e.target.value)}>
                                    <option value="">All actions</option>
                                    {actions.map((a) => (
                                        <option key={a.value} value={a.value}>
                                            {a.value}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-subject">Subject</Label>
                                <NativeSelect id="f-subject" value={values.subject_type} onChange={(e) => set('subject_type', e.target.value)}>
                                    <option value="">All</option>
                                    {subjectTypes.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
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
                            <div className="flex gap-2">
                                <Button type="submit">Filter</Button>
                                <Button type="button" variant="secondary" onClick={reset}>
                                    Reset
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Action</TableHead>
                                    <TableHead>Subject</TableHead>
                                    <TableHead>IP</TableHead>
                                    <TableHead>Details</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-8 text-center">
                                            No entries match these filters.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {logs.data.map((log) => (
                                    <TableRow key={log.id} className="align-top">
                                        <TableCell className="whitespace-nowrap">{formatDateTime(log.created_at)}</TableCell>
                                        <TableCell>{log.user ? log.user.name : <span className="text-muted-foreground">system</span>}</TableCell>
                                        <TableCell className="font-mono text-xs">{log.action}</TableCell>
                                        <TableCell className="text-xs">
                                            {log.subject_type ? `${log.subject_type} #${log.subject_id ?? ''}` : '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{log.ip ?? '—'}</TableCell>
                                        <TableCell className="max-w-md">
                                            {log.meta ? (
                                                <button
                                                    type="button"
                                                    className="text-left text-xs underline-offset-4 hover:underline"
                                                    onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                                                >
                                                    {expanded === log.id ? (
                                                        <pre className="bg-muted rounded p-2 whitespace-pre-wrap">
                                                            {JSON.stringify(log.meta, null, 2)}
                                                        </pre>
                                                    ) : (
                                                        <span className="text-muted-foreground line-clamp-1">{JSON.stringify(log.meta)}</span>
                                                    )}
                                                </button>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <Pagination page={logs} />
            </div>
        </AppLayout>
    );
}
