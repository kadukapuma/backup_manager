import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDateTime, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Option } from '@/types';
import { type CopyStatusValue, type DestinationTypeValue, type RestoreModeValue, type RunTriggerValue } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, ArrowRight, Check, Database, HardDrive } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface DatabaseOption {
    id: number;
    name: string;
    connection: string;
    missing: boolean;
}

interface TimelineCopy {
    id: number;
    destination: string;
    destination_type: DestinationTypeValue;
    status: CopyStatusValue;
    restorable: boolean;
}

interface TimelinePoint {
    id: number;
    filename: string | null;
    created_at: string;
    trigger: RunTriggerValue;
    size_bytes: number | null;
    sha256: string | null;
    table_count: number | null;
    copies: TimelineCopy[];
}

interface Props {
    databases: DatabaseOption[];
    database: { id: number; name: string; connection_id: number; connection: string } | null;
    timeline: TimelinePoint[];
    preselectedFile: number | null;
    connections: Option[];
    identityFileConfigured: boolean;
    suggestedName: string | null;
}

type RestoreForm = {
    backup_file_id: number | null;
    source_copy_id: number | null;
    target_connection_id: string;
    target_database: string;
    mode: RestoreModeValue;
    confirmation: string;
    age_identity: string;
};

const STEPS = ['Database', 'Backup point', 'Source copy', 'Target', 'Confirm'] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Restore', href: '/restores' },
    { title: 'New restore', href: '/restores/new' },
];

function defaultCopy(point: TimelinePoint | undefined): number | null {
    if (!point) return null;
    const restorable = point.copies.filter((c) => c.restorable);
    return (restorable.find((c) => c.destination_type === 'local') ?? restorable[0])?.id ?? null;
}

export default function RestoreCreate({ databases, database, timeline, preselectedFile, connections, identityFileConfigured, suggestedName }: Props) {
    const initialPoint = timeline.find((p) => p.id === preselectedFile);
    const [step, setStep] = useState<number>(database === null ? 0 : initialPoint ? 2 : 1);
    const [search, setSearch] = useState('');

    const form = useForm<RestoreForm>({
        backup_file_id: initialPoint?.id ?? null,
        source_copy_id: defaultCopy(initialPoint),
        target_connection_id: database ? String(database.connection_id) : (connections[0]?.value ?? ''),
        target_database: suggestedName ?? '',
        mode: 'new_copy',
        confirmation: '',
        age_identity: '',
    });

    const point = timeline.find((p) => p.id === form.data.backup_file_id);
    const copy = point?.copies.find((c) => c.id === form.data.source_copy_id);
    const filtered = useMemo(
        () => databases.filter((d) => search === '' || d.name.toLowerCase().includes(search.toLowerCase())).slice(0, 200),
        [databases, search],
    );

    const chooseDatabase = (id: number) => {
        router.get(route('restores.create'), { database: id }, { preserveState: false });
    };

    const choosePoint = (p: TimelinePoint) => {
        form.setData({ ...form.data, backup_file_id: p.id, source_copy_id: defaultCopy(p) });
        setStep(2);
    };

    const setMode = (mode: RestoreModeValue) => {
        if (!database) return;
        form.setData({
            ...form.data,
            mode,
            confirmation: '',
            target_connection_id: String(database.connection_id),
            target_database: mode === 'replace' ? database.name : (suggestedName ?? ''),
        });
    };

    const canNext = [database !== null, point !== undefined, copy?.restorable === true, form.data.target_database !== '', false][step];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('restores.store'), {
            onError: (errors) => {
                if (errors.backup_file_id) setStep(1);
                else if (errors.source_copy_id) setStep(2);
                else if (errors.target_database || errors.target_connection_id || errors.mode) setStep(3);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New restore" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Restore wizard"
                    description="Every step can be revisited before you confirm. Nothing changes until the final step."
                />

                <ol className="flex flex-wrap gap-2 text-sm">
                    {STEPS.map((label, i) => (
                        <li key={label}>
                            <button
                                type="button"
                                disabled={i > step}
                                onClick={() => setStep(i)}
                                className={cn(
                                    'flex items-center gap-2 rounded-full border px-3 py-1',
                                    i === step && 'bg-primary text-primary-foreground border-primary',
                                    i < step && 'bg-muted',
                                    i > step && 'opacity-50',
                                )}
                            >
                                <span className="text-xs">{i < step ? <Check className="size-3" /> : i + 1}</span>
                                {label}
                            </button>
                        </li>
                    ))}
                </ol>

                {step === 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Which database?</CardTitle>
                            <CardDescription>Only databases with at least one successful backup are listed.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Input placeholder="Search…" value={search} onChange={(e) => setSearch(e.target.value)} autoFocus />
                            <div className="grid max-h-[55vh] gap-1 overflow-y-auto">
                                {filtered.map((d) => (
                                    <button
                                        key={d.id}
                                        type="button"
                                        onClick={() => chooseDatabase(d.id)}
                                        className={cn(
                                            'hover:bg-muted flex items-center justify-between rounded-md border px-3 py-2 text-left',
                                            database?.id === d.id && 'border-primary',
                                        )}
                                    >
                                        <span className="flex items-center gap-2 font-mono text-sm">
                                            <Database className="size-4" /> {d.name}
                                        </span>
                                        <span className="text-muted-foreground flex items-center gap-2 text-xs">
                                            {d.missing && <StatusBadge status="missing" />}
                                            {d.connection}
                                        </span>
                                    </button>
                                ))}
                                {filtered.length === 0 && <p className="text-muted-foreground text-sm">No databases with backups.</p>}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {step === 1 && database && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Backup points for <span className="font-mono">{database.name}</span>
                            </CardTitle>
                            <CardDescription>{database.connection} · newest first</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ol className="relative max-h-[60vh] space-y-2 overflow-y-auto border-l pl-4">
                                {timeline.map((p) => (
                                    <li key={p.id}>
                                        <span className="bg-background absolute -left-1.5 mt-3 size-3 rounded-full border" />
                                        <button
                                            type="button"
                                            onClick={() => choosePoint(p)}
                                            className={cn(
                                                'hover:bg-muted w-full rounded-md border p-3 text-left',
                                                form.data.backup_file_id === p.id && 'border-primary',
                                            )}
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-medium">
                                                    {formatDateTime(p.created_at)}{' '}
                                                    <span className="text-muted-foreground text-xs">({timeAgo(p.created_at)})</span>
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {p.trigger.replace('_', ' ')} · {formatBytes(p.size_bytes)}
                                                    {p.table_count !== null ? ` · ${p.table_count} tables` : ''}
                                                </span>
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-1">
                                                {p.copies.map((c) => (
                                                    <StatusBadge key={c.id} status={c.status} label={`${c.destination}: ${c.status}`} />
                                                ))}
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ol>
                        </CardContent>
                    </Card>
                )}

                {step === 2 && point && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Where should the backup be read from?</CardTitle>
                            <CardDescription>
                                Local copies are fastest. If the chosen copy is missing or fails its checksum, other copies are tried automatically.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2 sm:grid-cols-2">
                            {point.copies.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    disabled={!c.restorable}
                                    onClick={() => form.setData('source_copy_id', c.id)}
                                    className={cn(
                                        'flex items-center justify-between rounded-md border p-3 text-left disabled:opacity-50',
                                        form.data.source_copy_id === c.id && 'border-primary bg-muted',
                                    )}
                                >
                                    <span className="flex items-center gap-2">
                                        <HardDrive className="size-4" /> {c.destination}
                                        <span className="text-muted-foreground text-xs">{c.destination_type}</span>
                                    </span>
                                    <StatusBadge status={c.status} />
                                </button>
                            ))}
                            <InputError message={form.errors.source_copy_id} />
                        </CardContent>
                    </Card>
                )}

                {step === 3 && database && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Restore into…</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    onClick={() => setMode('new_copy')}
                                    className={cn('rounded-md border p-3 text-left', form.data.mode === 'new_copy' && 'border-primary bg-muted')}
                                >
                                    <div className="font-medium">A new database (recommended)</div>
                                    <div className="text-muted-foreground text-xs">The original stays untouched. Good for checking data first.</div>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setMode('replace')}
                                    className={cn('rounded-md border p-3 text-left', form.data.mode === 'replace' && 'border-destructive bg-muted')}
                                >
                                    <div className="font-medium">Replace the original</div>
                                    <div className="text-muted-foreground text-xs">
                                        Drops and recreates <span className="font-mono">{database.name}</span> after a safety backup.
                                    </div>
                                </button>
                            </div>

                            {form.data.mode === 'new_copy' ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField id="target_connection_id" label="Server" error={form.errors.target_connection_id}>
                                        <NativeSelect
                                            id="target_connection_id"
                                            value={form.data.target_connection_id}
                                            onChange={(e) => form.setData('target_connection_id', e.target.value)}
                                        >
                                            {connections.map((c) => (
                                                <option key={c.value} value={c.value}>
                                                    {c.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </FormField>
                                    <FormField
                                        id="target_database"
                                        label="New database name"
                                        error={form.errors.target_database}
                                        hint="Letters, digits and underscore, up to 64 characters."
                                    >
                                        <Input
                                            id="target_database"
                                            className="font-mono"
                                            value={form.data.target_database}
                                            onChange={(e) => form.setData('target_database', e.target.value)}
                                        />
                                    </FormField>
                                </div>
                            ) : (
                                <Alert variant="destructive">
                                    <AlertTriangle className="size-4" />
                                    <AlertTitle>The current {database.name} will be dropped</AlertTitle>
                                    <AlertDescription>
                                        A pre-restore safety backup is taken first. If that safety backup fails, the restore is aborted and nothing is
                                        changed.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <InputError message={form.data.mode === 'replace' ? form.errors.target_database : undefined} />
                        </CardContent>
                    </Card>
                )}

                {step === 4 && database && point && copy && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Confirm restore</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">Backup</dt>
                                    <dd>
                                        <span className="font-mono">{database.name}</span> from {formatDateTime(point.created_at)}
                                    </dd>
                                    <dt className="text-muted-foreground">Read from</dt>
                                    <dd>{copy.destination}</dd>
                                    <dt className="text-muted-foreground">Target</dt>
                                    <dd>
                                        <span className="font-mono">{form.data.target_database}</span> on{' '}
                                        {connections.find((c) => c.value === form.data.target_connection_id)?.label}
                                    </dd>
                                    <dt className="text-muted-foreground">Mode</dt>
                                    <dd className={form.data.mode === 'replace' ? 'font-semibold text-red-600' : ''}>
                                        {form.data.mode === 'replace' ? 'Replace original (drop + recreate)' : 'New copy'}
                                    </dd>
                                </dl>

                                <FormField
                                    id="age_identity"
                                    label={
                                        identityFileConfigured
                                            ? 'age private key (optional; the server key file is used when empty)'
                                            : 'age private key'
                                    }
                                    error={form.errors.age_identity}
                                    hint="Paste the AGE-SECRET-KEY-1… line. It is used only for this restore and wiped when the job starts."
                                >
                                    <Textarea
                                        id="age_identity"
                                        rows={3}
                                        className="font-mono text-xs"
                                        autoComplete="off"
                                        spellCheck={false}
                                        placeholder="AGE-SECRET-KEY-1..."
                                        value={form.data.age_identity}
                                        onChange={(e) => form.setData('age_identity', e.target.value)}
                                    />
                                </FormField>

                                <FormField id="confirmation" label={`Type ${form.data.target_database} to confirm`} error={form.errors.confirmation}>
                                    <Input
                                        id="confirmation"
                                        className="font-mono"
                                        autoComplete="off"
                                        value={form.data.confirmation}
                                        onChange={(e) => form.setData('confirmation', e.target.value)}
                                    />
                                </FormField>

                                <Button
                                    type="submit"
                                    variant={form.data.mode === 'replace' ? 'destructive' : 'default'}
                                    disabled={form.processing || form.data.confirmation !== form.data.target_database}
                                >
                                    Start restore
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <div className="flex justify-between">
                    <Button variant="secondary" disabled={step === 0} onClick={() => setStep(step - 1)}>
                        <ArrowLeft className="size-4" /> Back
                    </Button>
                    {step < 4 && (
                        <Button disabled={!canNext} onClick={() => setStep(step + 1)}>
                            Next <ArrowRight className="size-4" />
                        </Button>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
