import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import { type BreadcrumbItem, type Option } from '@/types';
import { type RunStatusValue } from '@/types/models';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarClock, Lock, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type Retention = {
    keep_daily: number;
    keep_weekly: number;
    keep_monthly: number;
};

interface PlanRow {
    id: number;
    name: string;
    connection_id: number;
    connection_name: string;
    cron_expression: string;
    timezone: string;
    retention: Retention;
    all_included_databases: boolean;
    database_ids: number[];
    database_names: string[];
    destination_ids: number[];
    destination_names: string[];
    target_count: number;
    is_active: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
    last_run_status: RunStatusValue | null;
    last_run_id: number | null;
}

interface DatabaseOption {
    id: number;
    name: string;
    connection_id: number;
}

interface Props {
    plans: PlanRow[];
    connections: Option[];
    destinations: Option[];
    databases: DatabaseOption[];
    presets: Option[];
    defaultTimezone: string;
}

type PlanForm = {
    name: string;
    connection_id: string;
    cron_expression: string;
    timezone: string;
    retention: Retention;
    all_included_databases: boolean;
    database_ids: number[];
    destination_ids: number[];
    is_active: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Backup plans', href: '/plans' }];

function useCronPreview(expression: string, timezone: string, enabled: boolean): { valid: boolean; runs: string[] } | null {
    const [preview, setPreview] = useState<{ valid: boolean; runs: string[] } | null>(null);

    useEffect(() => {
        if (!enabled || expression.trim() === '') {
            setPreview(null);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(route('plans.cron-preview', { expression, timezone }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((res) => (res.ok ? (res.json() as Promise<{ valid: boolean; runs: string[] }>) : null))
                .then((data) => setPreview(data))
                .catch(() => undefined);
        }, 300);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [expression, timezone, enabled]);

    return preview;
}

export default function PlansIndex({ plans, connections, destinations, databases, presets, defaultTimezone }: Props) {
    const can = useCan();
    const canManage = can('config.manage');
    const canRun = can('backups.run');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<PlanRow | null>(null);
    const [deleting, setDeleting] = useState<PlanRow | null>(null);
    const [dbFilter, setDbFilter] = useState('');

    const empty: PlanForm = {
        name: '',
        connection_id: connections[0]?.value ?? '',
        cron_expression: '0 2 * * *',
        timezone: defaultTimezone,
        retention: { keep_daily: 7, keep_weekly: 4, keep_monthly: 6 },
        all_included_databases: true,
        database_ids: [],
        destination_ids: [],
        is_active: true,
    };
    const form = useForm<PlanForm>(empty);
    const preview = useCronPreview(form.data.cron_expression, form.data.timezone, formOpen);

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(empty);
        setDbFilter('');
        setFormOpen(true);
    };

    const openEdit = (p: PlanRow) => {
        setEditing(p);
        form.clearErrors();
        form.setData({
            name: p.name,
            connection_id: String(p.connection_id),
            cron_expression: p.cron_expression,
            timezone: p.timezone,
            retention: p.retention,
            all_included_databases: p.all_included_databases,
            database_ids: p.database_ids,
            destination_ids: p.destination_ids,
            is_active: p.is_active,
        });
        setDbFilter('');
        setFormOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('plans.update', editing.id), options);
        } else {
            form.post(route('plans.store'), options);
        }
    };

    const toggleId = (key: 'database_ids' | 'destination_ids', id: number) => {
        const current = form.data[key];
        form.setData(key, current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    };

    const connectionDbs = databases.filter(
        (d) => String(d.connection_id) === form.data.connection_id && (dbFilter === '' || d.name.includes(dbFilter)),
    );
    const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backup plans" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Backup plans"
                    description="Schedules that back up databases to one or more destinations, with grandfather-father-son retention."
                    actions={
                        canManage && (
                            <Button onClick={openCreate} disabled={connections.length === 0 || destinations.length === 0}>
                                <Plus className="size-4" /> New plan
                            </Button>
                        )
                    }
                />

                {(connections.length === 0 || destinations.length === 0) && (
                    <Card>
                        <CardContent className="text-muted-foreground py-6 text-center text-sm">
                            Add at least one connection and one destination before creating a plan.
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {plans.map((p) => (
                        <Card key={p.id} className={p.is_active ? '' : 'opacity-70'}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 space-y-1">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <CalendarClock className="size-4" /> {p.name}
                                        </CardTitle>
                                        <CardDescription>
                                            {p.connection_name} ·{' '}
                                            {p.all_included_databases
                                                ? `all included databases (${p.target_count})`
                                                : `${p.target_count} selected database(s)`}
                                        </CardDescription>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {!p.is_active && <StatusBadge status="inactive" label="paused" />}
                                        {p.last_run_status && <StatusBadge status={p.last_run_status} />}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                    <dt className="text-muted-foreground">Schedule</dt>
                                    <dd className="font-mono text-xs">
                                        {p.cron_expression} <span className="text-muted-foreground font-sans">({p.timezone})</span>
                                    </dd>
                                    <dt className="text-muted-foreground">Next run</dt>
                                    <dd>{p.next_run_at ? `${formatDateTime(p.next_run_at)} (${timeAgo(p.next_run_at)})` : '—'}</dd>
                                    <dt className="text-muted-foreground">Last run</dt>
                                    <dd>
                                        {p.last_run_id ? (
                                            <Link className="underline-offset-4 hover:underline" href={`/runs/${p.last_run_id}`}>
                                                {timeAgo(p.last_run_at)}
                                            </Link>
                                        ) : (
                                            'never'
                                        )}
                                    </dd>
                                    <dt className="text-muted-foreground">Destinations</dt>
                                    <dd>{p.destination_names.join(', ')}</dd>
                                    <dt className="text-muted-foreground">Retention</dt>
                                    <dd>
                                        {p.retention.keep_daily} daily · {p.retention.keep_weekly} weekly · {p.retention.keep_monthly} monthly
                                    </dd>
                                </dl>
                                <div className="flex flex-wrap gap-2">
                                    {canRun && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => router.post(route('plans.run', p.id), {}, { preserveScroll: true })}
                                        >
                                            <Play className="size-4" /> Run now
                                        </Button>
                                    )}
                                    {canManage && (
                                        <>
                                            <Button size="sm" variant="ghost" onClick={() => openEdit(p)}>
                                                <Pencil className="size-4" /> Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => setDeleting(p)} aria-label="Delete">
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit plan' : 'New backup plan'}</DialogTitle>
                        <DialogDescription>Times are evaluated in the selected timezone.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-5">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField id="name" label="Name" error={form.errors.name}>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </FormField>
                            <FormField id="connection_id" label="Connection" error={form.errors.connection_id}>
                                <NativeSelect
                                    id="connection_id"
                                    value={form.data.connection_id}
                                    onChange={(e) => form.setData({ ...form.data, connection_id: e.target.value, database_ids: [] })}
                                >
                                    {connections.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        </div>

                        <div className="grid gap-2">
                            <Label>Schedule</Label>
                            <div className="flex flex-wrap gap-2">
                                {presets.map((p) => (
                                    <Button
                                        key={p.value}
                                        type="button"
                                        size="sm"
                                        variant={form.data.cron_expression === p.value ? 'default' : 'outline'}
                                        onClick={() => form.setData('cron_expression', p.value)}
                                    >
                                        {p.label}
                                    </Button>
                                ))}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField
                                    id="cron_expression"
                                    label="Cron expression"
                                    error={form.errors.cron_expression}
                                    hint="minute hour day month weekday"
                                >
                                    <Input
                                        id="cron_expression"
                                        className="font-mono"
                                        value={form.data.cron_expression}
                                        onChange={(e) => form.setData('cron_expression', e.target.value)}
                                    />
                                </FormField>
                                <FormField id="timezone" label="Timezone" error={form.errors.timezone}>
                                    <Input id="timezone" value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} />
                                </FormField>
                            </div>
                            <div className="bg-muted/50 rounded-md border p-3 text-sm">
                                {preview === null ? (
                                    <span className="text-muted-foreground">Enter a cron expression to see the next runs.</span>
                                ) : preview.valid ? (
                                    <>
                                        <p className="mb-1 font-medium">Next 5 runs</p>
                                        <ul className="text-muted-foreground space-y-0.5 text-xs">
                                            {preview.runs.map((r) => (
                                                <li key={r}>{formatDateTime(r)}</li>
                                            ))}
                                        </ul>
                                    </>
                                ) : (
                                    <span className="text-red-600">Invalid cron expression or timezone.</span>
                                )}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label>Databases</Label>
                            <label className="flex items-center gap-2 text-sm">
                                <Switch
                                    checked={form.data.all_included_databases}
                                    onCheckedChange={(v) => form.setData('all_included_databases', v)}
                                />
                                All included databases on this connection (new approved databases are picked up automatically)
                            </label>
                            {!form.data.all_included_databases && (
                                <div className="rounded-md border p-2">
                                    <Input
                                        placeholder="Filter…"
                                        className="mb-2 h-8"
                                        value={dbFilter}
                                        onChange={(e) => setDbFilter(e.target.value)}
                                    />
                                    <div className="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                                        {connectionDbs.map((d) => (
                                            <label key={d.id} className="flex items-center gap-2 font-mono text-xs">
                                                <Checkbox
                                                    checked={form.data.database_ids.includes(d.id)}
                                                    onCheckedChange={() => toggleId('database_ids', d.id)}
                                                />
                                                {d.name}
                                            </label>
                                        ))}
                                        {connectionDbs.length === 0 && <p className="text-muted-foreground text-xs">No included databases.</p>}
                                    </div>
                                    {err('database_ids') && <p className="mt-1 text-sm text-red-600">{err('database_ids')}</p>}
                                </div>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label>Destinations</Label>
                            <div className="grid gap-1 sm:grid-cols-2">
                                {destinations.map((d) => (
                                    <label key={d.value} className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.destination_ids.includes(Number(d.value))}
                                            onCheckedChange={() => toggleId('destination_ids', Number(d.value))}
                                        />
                                        {d.label}
                                    </label>
                                ))}
                            </div>
                            {err('destination_ids') && <p className="text-sm text-red-600">{err('destination_ids')}</p>}
                        </div>

                        <div className="grid gap-2">
                            <Label>Retention per destination (grandfather-father-son)</Label>
                            <div className="grid grid-cols-3 gap-3">
                                {(['keep_daily', 'keep_weekly', 'keep_monthly'] as const).map((key) => (
                                    <FormField key={key} id={key} label={key.replace('keep_', 'Keep ')} error={err(`retention.${key}`)}>
                                        <Input
                                            id={key}
                                            type="number"
                                            min={0}
                                            value={form.data.retention[key]}
                                            onChange={(e) => form.setData('retention', { ...form.data.retention, [key]: Number(e.target.value) })}
                                        />
                                    </FormField>
                                ))}
                            </div>
                            <p className="text-muted-foreground text-xs">The newest successful backup of every database is always kept.</p>
                        </div>

                        <div className="text-muted-foreground flex items-center gap-2 text-xs">
                            <Lock className="size-3.5" /> Compression: zstd · Encryption: age (always on)
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                            Active (run on schedule)
                        </label>

                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save plan' : 'Create plan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete plan"
                description={`Delete plan "${deleting?.name ?? ''}"? Backups already made are kept.`}
                confirmLabel="Delete"
                destructive
                onConfirm={() => deleting && router.delete(route('plans.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}
