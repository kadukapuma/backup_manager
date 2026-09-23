import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Option } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Bell, Mail, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ChannelRow {
    id: number;
    name: string;
    type: 'mail' | 'telegram';
    recipients: string;
    events: string[];
    is_active: boolean;
}

interface Props {
    channels: ChannelRow[];
    events: Option[];
    defaultEvents: string[];
    types: { value: string; label: string; available: boolean }[];
    mailer: string;
}

type ChannelForm = {
    name: string;
    type: string;
    recipients: string;
    events: string[];
    is_active: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/notifications' }];

export default function NotificationsIndex({ channels, events, defaultEvents, types, mailer }: Props) {
    const can = useCan();
    const canManage = can('config.manage');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ChannelRow | null>(null);
    const [deleting, setDeleting] = useState<ChannelRow | null>(null);
    const form = useForm<ChannelForm>({ name: '', type: 'mail', recipients: '', events: defaultEvents, is_active: true });

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData({ name: '', type: 'mail', recipients: '', events: defaultEvents, is_active: true });
        setFormOpen(true);
    };

    const openEdit = (c: ChannelRow) => {
        setEditing(c);
        form.clearErrors();
        form.setData({ name: c.name, type: c.type, recipients: c.recipients, events: c.events, is_active: c.is_active });
        setFormOpen(true);
    };

    const toggleEvent = (value: string) =>
        form.setData('events', form.data.events.includes(value) ? form.data.events.filter((e) => e !== value) : [...form.data.events, value]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('notifications.update', editing.id), options);
        } else {
            form.post(route('notifications.store'), options);
        }
    };

    const eventLabel = (value: string) => events.find((e) => e.value === value)?.label ?? value;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Notifications"
                    description={`Alerts for failures, pending databases and stale backups. Mail is sent with the "${mailer}" mailer configured in .env.`}
                    actions={
                        canManage && (
                            <Button onClick={openCreate}>
                                <Plus className="size-4" /> New channel
                            </Button>
                        )
                    }
                />

                {channels.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">
                            No channels yet. Without a channel, nobody is told when a backup fails.
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {channels.map((c) => (
                        <Card key={c.id} className={c.is_active ? '' : 'opacity-70'}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0 space-y-1">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            {c.type === 'mail' ? <Mail className="size-4" /> : <Bell className="size-4" />} {c.name}
                                        </CardTitle>
                                        <CardDescription className="break-all">{c.recipients}</CardDescription>
                                    </div>
                                    {!c.is_active && <StatusBadge status="inactive" />}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex flex-wrap gap-1">
                                    {c.events.map((e) => (
                                        <span key={e} className="bg-muted rounded-full px-2 py-0.5 text-xs">
                                            {eventLabel(e)}
                                        </span>
                                    ))}
                                </div>
                                {canManage && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => router.post(route('notifications.test', c.id), {}, { preserveScroll: true })}
                                        >
                                            <Send className="size-4" /> Send test
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => openEdit(c)}>
                                            <Pencil className="size-4" /> Edit
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setDeleting(c)} aria-label="Delete">
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
                        <DialogTitle>{editing ? 'Edit channel' : 'New notification channel'}</DialogTitle>
                        <DialogDescription>Telegram is planned for a later phase.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField id="name" label="Name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </FormField>
                        <div className="flex flex-wrap gap-2">
                            {types.map((t) => (
                                <Button
                                    key={t.value}
                                    type="button"
                                    size="sm"
                                    disabled={!t.available}
                                    variant={form.data.type === t.value ? 'default' : 'outline'}
                                    onClick={() => form.setData('type', t.value)}
                                >
                                    {t.label}
                                </Button>
                            ))}
                        </div>
                        <FormField id="recipients" label="Email recipients" error={form.errors.recipients} hint="Separate addresses with commas.">
                            <Input
                                id="recipients"
                                placeholder="ops@kreethya.com, admin@kreethya.com"
                                value={form.data.recipients}
                                onChange={(e) => form.setData('recipients', e.target.value)}
                            />
                        </FormField>
                        <div className="grid gap-2">
                            <Label>Events</Label>
                            <div className="grid gap-1.5">
                                {events.map((e) => (
                                    <label key={e.value} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={form.data.events.includes(e.value)} onCheckedChange={() => toggleEvent(e.value)} />
                                        {e.label}
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.events} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Create channel'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete channel"
                description={`Delete ${deleting?.name ?? ''}? Its recipients will stop receiving alerts.`}
                confirmLabel="Delete"
                destructive
                onConfirm={() => deleting && router.delete(route('notifications.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}
