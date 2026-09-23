import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useCan } from '@/hooks/use-can';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, timeAgo } from '@/lib/format';
import { type BreadcrumbItem, type Option, type TestStatus } from '@/types';
import { type DestinationTypeValue } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/react';
import { FlaskConical, HardDrive, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface DestinationConfig {
    host?: string;
    port?: number;
    user?: string;
    known_hosts_file?: string;
    password_is_set?: boolean;
    private_key_is_set?: boolean;
    private_key_passphrase_is_set?: boolean;
    provider?: string;
    region?: string;
    endpoint?: string;
    bucket?: string;
    access_key_id?: string;
    storage_class?: string;
    secret_access_key_is_set?: boolean;
}

interface DestinationRow {
    id: number;
    name: string;
    type: DestinationTypeValue;
    type_label: string;
    base_path: string;
    config: DestinationConfig;
    is_active: boolean;
    last_tested_at: string | null;
    last_test_status: TestStatus | null;
    last_test_message: string | null;
    free_space_bytes: number | null;
    copies: number;
    stored_bytes: number;
}

interface Props {
    destinations: DestinationRow[];
    types: Option[];
    s3Providers: string[];
}

type ConfigForm = {
    host: string;
    port: number;
    user: string;
    password: string;
    private_key: string;
    private_key_passphrase: string;
    known_hosts_file: string;
    provider: string;
    region: string;
    endpoint: string;
    bucket: string;
    access_key_id: string;
    secret_access_key: string;
    storage_class: string;
};

type DestinationForm = {
    name: string;
    type: DestinationTypeValue;
    base_path: string;
    is_active: boolean;
    config: ConfigForm;
};

const EMPTY_CONFIG: ConfigForm = {
    host: '',
    port: 22,
    user: '',
    password: '',
    private_key: '',
    private_key_passphrase: '',
    known_hosts_file: '',
    provider: 'AWS',
    region: '',
    endpoint: '',
    bucket: '',
    access_key_id: '',
    secret_access_key: '',
    storage_class: '',
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Destinations', href: '/destinations' }];

function describe(d: DestinationRow): string {
    if (d.type === 'sftp') return `${d.config.user ?? ''}@${d.config.host ?? ''}:${d.config.port ?? 22} ${d.base_path}`;
    if (d.type === 's3') return `${d.config.provider ?? ''} s3://${d.config.bucket ?? ''}/${d.base_path}`;
    return d.base_path;
}

export default function DestinationsIndex({ destinations, types, s3Providers }: Props) {
    const can = useCan();
    const canManage = can('config.manage');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<DestinationRow | null>(null);
    const [deleting, setDeleting] = useState<DestinationRow | null>(null);
    const form = useForm<DestinationForm>({ name: '', type: 'local', base_path: '', is_active: true, config: EMPTY_CONFIG });

    const anyTesting = destinations.some((d) => d.last_test_status === 'running');
    usePollWhile(anyTesting, ['destinations']);

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData({ name: '', type: 'local', base_path: '/var/backups/databases', is_active: true, config: EMPTY_CONFIG });
        setFormOpen(true);
    };

    const openEdit = (d: DestinationRow) => {
        setEditing(d);
        form.clearErrors();
        form.setData({
            name: d.name,
            type: d.type,
            base_path: d.base_path,
            is_active: d.is_active,
            config: {
                ...EMPTY_CONFIG,
                host: d.config.host ?? '',
                port: d.config.port ?? 22,
                user: d.config.user ?? '',
                known_hosts_file: d.config.known_hosts_file ?? '',
                provider: d.config.provider ?? 'AWS',
                region: d.config.region ?? '',
                endpoint: d.config.endpoint ?? '',
                bucket: d.config.bucket ?? '',
                access_key_id: d.config.access_key_id ?? '',
                storage_class: d.config.storage_class ?? '',
            },
        });
        setFormOpen(true);
    };

    const setConfig = <K extends keyof ConfigForm>(key: K, value: ConfigForm[K]) => form.setData('config', { ...form.data.config, [key]: value });
    const err = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[`config.${key}`];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('destinations.update', editing.id), options);
        } else {
            form.post(route('destinations.store'), options);
        }
    };

    const keepHint = (isSet: boolean | undefined) => (editing && isSet ? '•••••••• stored. Leave blank to keep.' : undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Destinations" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Destinations"
                    description="Where encrypted backups are copied. Use at least one off-server destination (SFTP or S3)."
                    actions={
                        canManage && (
                            <Button onClick={openCreate}>
                                <Plus className="size-4" /> New destination
                            </Button>
                        )
                    }
                />

                {destinations.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">No destinations yet.</CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {destinations.map((d) => (
                        <Card key={d.id} className={d.is_active ? '' : 'opacity-70'}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 space-y-1">
                                        <CardTitle className="flex items-center gap-2 truncate text-base">
                                            <HardDrive className="size-4 shrink-0" /> {d.name}
                                        </CardTitle>
                                        <CardDescription className="truncate font-mono text-xs">{describe(d)}</CardDescription>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {!d.is_active && <StatusBadge status="inactive" />}
                                        <StatusBadge status={d.last_test_status} label={d.last_test_status === 'running' ? 'testing…' : undefined} />
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="grid grid-cols-3 gap-2 text-center">
                                    <div className="rounded-md border p-2">
                                        <div className="font-semibold">{d.type_label}</div>
                                        <div className="text-muted-foreground text-xs">type</div>
                                    </div>
                                    <div className="rounded-md border p-2">
                                        <div className="font-semibold">{formatBytes(d.stored_bytes)}</div>
                                        <div className="text-muted-foreground text-xs">{d.copies} copies</div>
                                    </div>
                                    <div className="rounded-md border p-2">
                                        <div className="font-semibold">{formatBytes(d.free_space_bytes)}</div>
                                        <div className="text-muted-foreground text-xs">free</div>
                                    </div>
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    <p>Last test: {timeAgo(d.last_tested_at)}</p>
                                    {d.last_test_message && <p className="line-clamp-3 break-all">{d.last_test_message}</p>}
                                </div>
                                {canManage && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={d.last_test_status === 'running'}
                                            onClick={() => router.post(route('destinations.test', d.id), {}, { preserveScroll: true })}
                                        >
                                            <FlaskConical className="size-4" /> Test
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => openEdit(d)}>
                                            <Pencil className="size-4" /> Edit
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setDeleting(d)} aria-label="Delete">
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit destination' : 'New destination'}</DialogTitle>
                        <DialogDescription>
                            Credentials are encrypted at rest and passed to rclone through environment variables only.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid grid-cols-2 gap-3">
                            <FormField id="name" label="Name" error={form.errors.name} className="col-span-2 sm:col-span-1">
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </FormField>
                            <FormField id="type" label="Type" error={form.errors.type} className="col-span-2 sm:col-span-1">
                                <NativeSelect
                                    id="type"
                                    value={form.data.type}
                                    disabled={editing !== null}
                                    onChange={(e) => form.setData('type', e.target.value as DestinationTypeValue)}
                                >
                                    {types.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                        </div>

                        {form.data.type === 'sftp' && (
                            <div className="grid grid-cols-3 gap-3">
                                <FormField id="host" label="Host" error={err('host')} className="col-span-2">
                                    <Input id="host" value={form.data.config.host} onChange={(e) => setConfig('host', e.target.value)} />
                                </FormField>
                                <FormField id="port" label="Port" error={err('port')}>
                                    <Input
                                        id="port"
                                        type="number"
                                        value={form.data.config.port}
                                        onChange={(e) => setConfig('port', Number(e.target.value))}
                                    />
                                </FormField>
                                <FormField id="user" label="User" error={err('user')} className="col-span-3 sm:col-span-1">
                                    <Input id="user" value={form.data.config.user} onChange={(e) => setConfig('user', e.target.value)} />
                                </FormField>
                                <FormField
                                    id="password"
                                    label="Password"
                                    error={err('password')}
                                    className="col-span-3 sm:col-span-2"
                                    hint={keepHint(editing?.config.password_is_set)}
                                >
                                    <Input
                                        id="password"
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.config.password}
                                        onChange={(e) => setConfig('password', e.target.value)}
                                    />
                                </FormField>
                                <FormField
                                    id="private_key"
                                    label="Private key (PEM, optional)"
                                    error={err('private_key')}
                                    className="col-span-3"
                                    hint={keepHint(editing?.config.private_key_is_set) ?? 'Key authentication is recommended over a password.'}
                                >
                                    <Textarea
                                        id="private_key"
                                        rows={3}
                                        className="font-mono text-xs"
                                        placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                        value={form.data.config.private_key}
                                        onChange={(e) => setConfig('private_key', e.target.value)}
                                    />
                                </FormField>
                                <FormField
                                    id="private_key_passphrase"
                                    label="Key passphrase"
                                    error={err('private_key_passphrase')}
                                    className="col-span-3 sm:col-span-1"
                                    hint={keepHint(editing?.config.private_key_passphrase_is_set)}
                                >
                                    <Input
                                        id="private_key_passphrase"
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.config.private_key_passphrase}
                                        onChange={(e) => setConfig('private_key_passphrase', e.target.value)}
                                    />
                                </FormField>
                                <FormField
                                    id="known_hosts_file"
                                    label="known_hosts file"
                                    error={err('known_hosts_file')}
                                    className="col-span-3 sm:col-span-2"
                                    hint="Recommended: verifies the server's host key."
                                >
                                    <Input
                                        id="known_hosts_file"
                                        placeholder="/home/backup/.ssh/known_hosts"
                                        value={form.data.config.known_hosts_file}
                                        onChange={(e) => setConfig('known_hosts_file', e.target.value)}
                                    />
                                </FormField>
                            </div>
                        )}

                        {form.data.type === 's3' && (
                            <div className="grid grid-cols-2 gap-3">
                                <FormField id="provider" label="Provider" error={err('provider')}>
                                    <NativeSelect
                                        id="provider"
                                        value={form.data.config.provider}
                                        onChange={(e) => setConfig('provider', e.target.value)}
                                    >
                                        {s3Providers.map((p) => (
                                            <option key={p} value={p}>
                                                {p}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                                <FormField id="region" label="Region" error={err('region')}>
                                    <Input
                                        id="region"
                                        placeholder="ap-south-1"
                                        value={form.data.config.region}
                                        onChange={(e) => setConfig('region', e.target.value)}
                                    />
                                </FormField>
                                <FormField id="endpoint" label="Endpoint (non-AWS)" error={err('endpoint')} className="col-span-2">
                                    <Input
                                        id="endpoint"
                                        placeholder="https://s3.wasabisys.com"
                                        value={form.data.config.endpoint}
                                        onChange={(e) => setConfig('endpoint', e.target.value)}
                                    />
                                </FormField>
                                <FormField id="bucket" label="Bucket" error={err('bucket')}>
                                    <Input id="bucket" value={form.data.config.bucket} onChange={(e) => setConfig('bucket', e.target.value)} />
                                </FormField>
                                <FormField id="storage_class" label="Storage class" error={err('storage_class')}>
                                    <Input
                                        id="storage_class"
                                        placeholder="STANDARD_IA"
                                        value={form.data.config.storage_class}
                                        onChange={(e) => setConfig('storage_class', e.target.value)}
                                    />
                                </FormField>
                                <FormField id="access_key_id" label="Access key ID" error={err('access_key_id')}>
                                    <Input
                                        id="access_key_id"
                                        autoComplete="off"
                                        value={form.data.config.access_key_id}
                                        onChange={(e) => setConfig('access_key_id', e.target.value)}
                                    />
                                </FormField>
                                <FormField
                                    id="secret_access_key"
                                    label="Secret access key"
                                    error={err('secret_access_key')}
                                    hint={keepHint(editing?.config.secret_access_key_is_set)}
                                >
                                    <Input
                                        id="secret_access_key"
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.config.secret_access_key}
                                        onChange={(e) => setConfig('secret_access_key', e.target.value)}
                                    />
                                </FormField>
                            </div>
                        )}

                        <FormField
                            id="base_path"
                            label={form.data.type === 'local' ? 'Directory (absolute path)' : 'Base path (folder)'}
                            error={form.errors.base_path}
                            hint={
                                form.data.type === 'local'
                                    ? 'Must be writable by the queue worker user. Keep it outside the web root.'
                                    : 'Backups go to <base path>/<connection>/<database>/.'
                            }
                        >
                            <Input id="base_path" value={form.data.base_path} onChange={(e) => form.setData('base_path', e.target.value)} />
                        </FormField>

                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                            Active
                        </label>

                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Create destination'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete destination"
                description={`Delete ${deleting?.name ?? ''}? This is only possible when it holds no backup copies. Files on the remote are not touched.`}
                confirmLabel="Delete"
                destructive
                typeToConfirm={deleting?.name}
                onConfirm={() => deleting && router.delete(route('destinations.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}
