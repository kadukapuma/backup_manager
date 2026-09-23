import { ConfirmDialog } from '@/components/confirm-dialog';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import { type BreadcrumbItem, type Option, type RoleName, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, ShieldOff, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: RoleName | null;
    two_factor_enabled: boolean;
    created_at: string | null;
}

interface Props {
    users: UserRow[];
    roles: Option[];
}

type UserForm = {
    name: string;
    email: string;
    role: string;
    password: string;
    password_confirmation: string;
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users & roles', href: '/users' }];

export default function UsersIndex({ users, roles }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [editing, setEditing] = useState<UserRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [deleting, setDeleting] = useState<UserRow | null>(null);
    const [resetting, setResetting] = useState<UserRow | null>(null);

    const form = useForm<UserForm>({ name: '', email: '', role: 'viewer', password: '', password_confirmation: '' });

    const openCreate = () => {
        setEditing(null);
        form.clearErrors();
        form.setData({ name: '', email: '', role: 'viewer', password: '', password_confirmation: '' });
        setFormOpen(true);
    };

    const openEdit = (user: UserRow) => {
        setEditing(user);
        form.clearErrors();
        form.setData({ name: user.name, email: user.email, role: user.role ?? 'viewer', password: '', password_confirmation: '' });
        setFormOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('users.update', editing.id), options);
        } else {
            form.post(route('users.store'), options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users & roles" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Users & roles"
                    description="Admins manage everything. Operators run backups and restores. Viewers can only look."
                    actions={
                        <Button onClick={openCreate}>
                            <Plus className="size-4" /> New user
                        </Button>
                    }
                />

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>2FA</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell className="font-medium">{user.name}</TableCell>
                                        <TableCell>{user.email}</TableCell>
                                        <TableCell className="capitalize">{user.role ?? '—'}</TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={user.two_factor_enabled ? 'ok' : 'inactive'}
                                                label={user.two_factor_enabled ? 'on' : 'off'}
                                            />
                                        </TableCell>
                                        <TableCell>{formatDate(user.created_at)}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(user)} aria-label="Edit">
                                                    <Pencil className="size-4" />
                                                </Button>
                                                {user.id !== auth.user.id && user.two_factor_enabled && (
                                                    <Button size="icon" variant="ghost" onClick={() => setResetting(user)} aria-label="Reset 2FA">
                                                        <ShieldOff className="size-4" />
                                                    </Button>
                                                )}
                                                {user.id !== auth.user.id && (
                                                    <Button size="icon" variant="ghost" onClick={() => setDeleting(user)} aria-label="Delete">
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit user' : 'New user'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField id="name" label="Name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </FormField>
                        <FormField id="email" label="Email" error={form.errors.email}>
                            <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                        </FormField>
                        <FormField id="role" label="Role" error={form.errors.role}>
                            <NativeSelect id="role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                {roles.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="password"
                            label={editing ? 'New password (leave blank to keep)' : 'Password'}
                            error={form.errors.password}
                            hint="At least 12 characters."
                        >
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                required={!editing}
                            />
                        </FormField>
                        <FormField id="password_confirmation" label="Confirm password">
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                required={!editing}
                            />
                        </FormField>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Create user'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete user"
                description={`Delete ${deleting?.email ?? ''}? Their audit history is kept.`}
                confirmLabel="Delete"
                destructive
                onConfirm={() => deleting && router.delete(route('users.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
            <ConfirmDialog
                open={resetting !== null}
                onOpenChange={(o) => !o && setResetting(null)}
                title="Reset two-factor authentication"
                description={`Turn off 2FA for ${resetting?.email ?? ''}? They will need to set it up again.`}
                confirmLabel="Reset 2FA"
                destructive
                onConfirm={() => resetting && router.post(route('users.reset-two-factor', resetting.id), {}, { onFinish: () => setResetting(null) })}
            />
        </AppLayout>
    );
}
