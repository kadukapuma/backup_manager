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
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { type BreadcrumbItem, type Option } from '@/types';
import { type ConnectionRow, type NewDatabasePolicyValue } from '@/types/models';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, Pencil, PlugZap, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    connections: ConnectionRow[];
    drivers: Option[];
    policies: Option[];
}

type ConnectionForm = {
    name: string;
    driver: string;
    host: string;
    port: number;
    username: string;
    password: string;
    socket: string;
    ssh_enabled: boolean;
    ssh_host: string;
    ssh_port: number;
    ssh_user: string;
    ssh_forget_host_key: boolean;
    new_database_policy: NewDatabasePolicyValue;
    is_active: boolean;
};

const DEFAULT_PORTS: Record<string, number> = { mariadb: 3306, mysql: 3306, pgsql: 5432 };

const EMPTY: ConnectionForm = {
    name: '',
    driver: 'mariadb',
    host: '127.0.0.1',
    port: 3306,
    username: 'backup',
    password: '',
    socket: '',
    ssh_enabled: false,
    ssh_host: '',
    ssh_port: 22,
    ssh_user: '',
    ssh_forget_host_key: false,
    new_database_policy: 'pending',
    is_active: true,
};

function driverLabel(driver: string): string {
    return driver === 'pgsql' ? 'PostgreSQL' : driver === 'mysql' ? 'MySQL' : 'MariaDB';
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => {
                void navigator.clipboard?.writeText(text).then(() => {
                    setCopied(true);
                    setTimeout(() => setCopied(false), 2000);
                });
            }}
        >
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

function SshSetup({ c }: { c: ConnectionRow }) {
    return (
        <div className="bg-muted/50 space-y-2 rounded-md border p-3 text-xs">
            <p className="flex items-center gap-1 font-medium">
                <KeyRound className="size-3.5" /> SSH access on {c.ssh_host}
            </p>
            {c.ssh_authorized_keys_line && (
                <>
                    <p className="text-muted-foreground">
                        Add this line to <span className="font-mono">~{c.ssh_user}/.ssh/authorized_keys</span> on that server. The key can only open a
                        tunnel to {c.host}:{c.port}, nothing else.
                    </p>
                    <pre className="bg-background max-h-24 overflow-auto rounded border p-2 font-mono text-[11px] break-all whitespace-pre-wrap">
                        {c.ssh_authorized_keys_line}
                    </pre>
                    <CopyButton text={c.ssh_authorized_keys_line} />
                </>
            )}
            {c.ssh_host_key_fingerprints.length > 0 ? (
                <div className="text-muted-foreground">
                    Pinned host key:
                    {c.ssh_host_key_fingerprints.map((f) => (
                        <div key={f} className="font-mono break-all">
                            {f}
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-amber-600">Host key not pinned yet. Press Test after adding the key line.</p>
            )}
        </div>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connections', href: '/connections' }];

export default function ConnectionsIndex({ connections, drivers, policies }: Props) {
    const can = useCan();
    const canManage = can('config.manage');
    const canOperate = can('databases.operate');

    const [editing, setEditing] = useState<ConnectionRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [deleting, setDeleting] = useState<ConnectionRow | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const form = useForm<ConnectionForm>(EMPTY);

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(EMPTY);
        setFormOpen(true);
    };

    const openEdit = (c: ConnectionRow) => {
        setEditing(c);
        form.clearErrors();
        form.setData({
            name: c.name,
            driver: c.driver,
            host: c.host,
            port: c.port,
            username: c.username,
            password: '',
            socket: c.socket ?? '',
            ssh_enabled: c.ssh_enabled,
            ssh_host: c.ssh_host ?? '',
            ssh_port: c.ssh_port,
            ssh_user: c.ssh_user ?? '',
            ssh_forget_host_key: false,
            new_database_policy: c.new_database_policy,
            is_active: c.is_active,
        });
        setFormOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('connections.update', editing.id), options);
        } else {
            form.post(route('connections.store'), options);
        }
    };

    const post = (name: string, id: number) => {
        setBusy(id);
        router.post(route(name, id), {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connections" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Connections"
                    description="Database servers to back up: MariaDB, MySQL or PostgreSQL, on this server or on another one through SSH."
                    actions={
                        canManage && (
                            <Button onClick={openCreate}>
                                <Plus className="size-4" /> New connection
                            </Button>
                        )
                    }
                />

                {connections.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">
                            No connections yet. Add a database server to start discovering databases.
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {connections.map((c) => (
                        <Card key={c.id} className={c.is_active ? '' : 'opacity-70'}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 space-y-1">
                                        <CardTitle className="truncate text-base">{c.name}</CardTitle>
                                        <CardDescription className="truncate font-mono text-xs">
                                            {driverLabel(c.driver)} · {c.username}@{c.socket ? c.socket : `${c.host}:${c.port}`}
                                        </CardDescription>
                                        {c.ssh_enabled && (
                                            <CardDescription className="truncate font-mono text-xs">
                                                via SSH {c.ssh_user}@{c.ssh_host}:{c.ssh_port}
                                            </CardDescription>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {!c.is_active && <StatusBadge status="inactive" />}
                                        <StatusBadge status={c.last_test_status} label={c.last_test_status === 'ok' ? 'reachable' : undefined} />
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="grid grid-cols-3 gap-2 text-center">
                                    <Link href={`/databases?connection=${c.id}`} className="hover:bg-muted rounded-md border p-2">
                                        <div className="text-lg font-semibold">{c.databases_count}</div>
                                        <div className="text-muted-foreground text-xs">databases</div>
                                    </Link>
                                    <Link href={`/databases?connection=${c.id}&state=included`} className="hover:bg-muted rounded-md border p-2">
                                        <div className="text-lg font-semibold">{c.included_count}</div>
                                        <div className="text-muted-foreground text-xs">included</div>
                                    </Link>
                                    <Link href={`/databases?connection=${c.id}&state=pending`} className="hover:bg-muted rounded-md border p-2">
                                        <div className={'text-lg font-semibold ' + (c.pending_count > 0 ? 'text-amber-600' : '')}>
                                            {c.pending_count}
                                        </div>
                                        <div className="text-muted-foreground text-xs">pending</div>
                                    </Link>
                                </div>
                                <div className="text-muted-foreground space-y-0.5 text-xs">
                                    <p>
                                        New databases:{' '}
                                        <span className="text-foreground">
                                            {c.new_database_policy === 'auto_include' ? 'included automatically' : 'wait for approval'}
                                        </span>
                                    </p>
                                    <p>Last discovery: {timeAgo(c.last_discovered_at)}</p>
                                    {c.last_test_message && <p className="line-clamp-3 break-all">{c.last_test_message}</p>}
                                </div>
                                {c.ssh_enabled && canManage && <SshSetup c={c} />}
                                <div className="flex flex-wrap gap-2">
                                    {canManage && (
                                        <Button size="sm" variant="secondary" disabled={busy === c.id} onClick={() => post('connections.test', c.id)}>
                                            <PlugZap className="size-4" /> Test
                                        </Button>
                                    )}
                                    {canOperate && (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={busy === c.id}
                                            onClick={() => post('connections.discover', c.id)}
                                        >
                                            <RefreshCw className="size-4" /> Refresh
                                        </Button>
                                    )}
                                    {canManage && (
                                        <>
                                            <Button size="sm" variant="ghost" onClick={() => openEdit(c)}>
                                                <Pencil className="size-4" /> Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => setDeleting(c)}>
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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit connection' : 'New connection'}</DialogTitle>
                        <DialogDescription>The password is encrypted at rest and never shown again.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid grid-cols-2 gap-3">
                            <FormField id="name" label="Name" error={form.errors.name} className="col-span-2 sm:col-span-1">
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </FormField>
                            <FormField id="driver" label="Driver" error={form.errors.driver} className="col-span-2 sm:col-span-1">
                                <NativeSelect
                                    id="driver"
                                    value={form.data.driver}
                                    onChange={(e) => {
                                        const driver = e.target.value;
                                        const keepPort = form.data.port !== DEFAULT_PORTS[form.data.driver];
                                        form.setData({
                                            ...form.data,
                                            driver,
                                            port: keepPort ? form.data.port : (DEFAULT_PORTS[driver] ?? form.data.port),
                                        });
                                    }}
                                >
                                    {drivers.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <FormField id="host" label="Host" error={form.errors.host} className="col-span-2 sm:col-span-1">
                                <Input id="host" value={form.data.host} onChange={(e) => form.setData('host', e.target.value)} />
                            </FormField>
                            <FormField id="port" label="Port" error={form.errors.port} className="col-span-2 sm:col-span-1">
                                <Input
                                    id="port"
                                    type="number"
                                    value={form.data.port}
                                    onChange={(e) => form.setData('port', Number(e.target.value))}
                                />
                            </FormField>
                            {!form.data.ssh_enabled && (
                                <FormField
                                    id="socket"
                                    label="Unix socket (optional)"
                                    error={form.errors.socket}
                                    className="col-span-2"
                                    hint={
                                        form.data.driver === 'pgsql'
                                            ? 'The socket directory, used instead of host when set.'
                                            : 'Used instead of host/port when set.'
                                    }
                                >
                                    <Input
                                        id="socket"
                                        placeholder={form.data.driver === 'pgsql' ? '/var/run/postgresql' : '/var/lib/mysql/mysql.sock'}
                                        value={form.data.socket}
                                        onChange={(e) => form.setData('socket', e.target.value)}
                                    />
                                </FormField>
                            )}
                            <label className="col-span-2 flex items-center gap-2 text-sm">
                                <Switch
                                    checked={form.data.ssh_enabled}
                                    onCheckedChange={(v) => form.setData({ ...form.data, ssh_enabled: v, socket: v ? '' : form.data.socket })}
                                />
                                Connect through SSH (database on another server)
                            </label>
                            {form.data.ssh_enabled && (
                                <>
                                    <p className="text-muted-foreground col-span-2 -mt-2 text-xs">
                                        Host and port above are the database as seen <strong>from the SSH server</strong>, usually 127.0.0.1. The
                                        panel creates an SSH key for this connection and shows the line to install after you save.
                                    </p>
                                    <FormField id="ssh_host" label="SSH server" error={form.errors.ssh_host} className="col-span-2 sm:col-span-1">
                                        <Input
                                            id="ssh_host"
                                            placeholder="203.0.113.20"
                                            value={form.data.ssh_host}
                                            onChange={(e) => form.setData('ssh_host', e.target.value)}
                                        />
                                    </FormField>
                                    <FormField id="ssh_port" label="SSH port" error={form.errors.ssh_port} className="col-span-2 sm:col-span-1">
                                        <Input
                                            id="ssh_port"
                                            type="number"
                                            value={form.data.ssh_port}
                                            onChange={(e) => form.setData('ssh_port', Number(e.target.value))}
                                        />
                                    </FormField>
                                    <FormField id="ssh_user" label="SSH user" error={form.errors.ssh_user} className="col-span-2">
                                        <Input
                                            id="ssh_user"
                                            autoComplete="off"
                                            placeholder="bmtunnel"
                                            value={form.data.ssh_user}
                                            onChange={(e) => form.setData('ssh_user', e.target.value)}
                                        />
                                    </FormField>
                                    {editing && editing.ssh_host_key_fingerprints.length > 0 && (
                                        <label className="col-span-2 flex items-center gap-2 text-sm">
                                            <Switch
                                                checked={form.data.ssh_forget_host_key}
                                                onCheckedChange={(v) => form.setData('ssh_forget_host_key', v)}
                                            />
                                            Forget the pinned host key (only if the server was reinstalled)
                                        </label>
                                    )}
                                </>
                            )}
                            <FormField id="username" label="Username" error={form.errors.username} className="col-span-2 sm:col-span-1">
                                <Input
                                    id="username"
                                    autoComplete="off"
                                    value={form.data.username}
                                    onChange={(e) => form.setData('username', e.target.value)}
                                    required
                                />
                            </FormField>
                            <FormField
                                id="password"
                                label="Password"
                                error={form.errors.password}
                                className="col-span-2 sm:col-span-1"
                                hint={editing?.password_set ? '•••••••• stored. Leave blank to keep.' : undefined}
                            >
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                />
                            </FormField>
                            <FormField id="policy" label="New databases" error={form.errors.new_database_policy} className="col-span-2">
                                <NativeSelect
                                    id="policy"
                                    value={form.data.new_database_policy}
                                    onChange={(e) => form.setData('new_database_policy', e.target.value as NewDatabasePolicyValue)}
                                >
                                    {policies.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.value === 'pending' ? 'Wait for approval and alert (safer)' : 'Include automatically'}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <label className="col-span-2 flex items-center gap-2 text-sm">
                                <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                                Active (discover and back up)
                            </label>
                        </div>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Create connection'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete connection"
                description={`Delete ${deleting?.name ?? ''}? This is only possible when it has no backup history. Otherwise, deactivate it.`}
                confirmLabel="Delete"
                destructive
                typeToConfirm={deleting?.name}
                onConfirm={() => deleting && router.delete(route('connections.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}
