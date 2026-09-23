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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Option } from '@/types';
import { type RuleGroup, type RuleRow, type RuleTypeValue } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Wand2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Props {
    groups: RuleGroup[];
    types: Option[];
}

type RuleForm = {
    connection_id: number;
    type: RuleTypeValue;
    pattern: string;
    priority: number;
    is_active: boolean;
};

interface Preview {
    total: number;
    matches: string[];
}

const PATTERN = /^[A-Za-z0-9_*?]{1,64}$/;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Selection rules', href: '/rules' }];

function usePreview(connectionId: number, pattern: string, enabled: boolean): Preview | null {
    const [preview, setPreview] = useState<Preview | null>(null);

    useEffect(() => {
        if (!enabled || !PATTERN.test(pattern) || connectionId === 0) {
            setPreview(null);
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            const url = route('rules.preview', { connection_id: connectionId, pattern });
            fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal, credentials: 'same-origin' })
                .then((res) => (res.ok ? (res.json() as Promise<Preview>) : null))
                .then((data) => setPreview(data))
                .catch(() => undefined);
        }, 300);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [connectionId, pattern, enabled]);

    return preview;
}

export default function RulesIndex({ groups, types }: Props) {
    const can = useCan();
    const canManage = can('config.manage');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<RuleRow | null>(null);
    const [deleting, setDeleting] = useState<RuleRow | null>(null);
    const form = useForm<RuleForm>({ connection_id: groups[0]?.connection.id ?? 0, type: 'include', pattern: '', priority: 100, is_active: true });
    const preview = usePreview(form.data.connection_id, form.data.pattern, formOpen);

    const openCreate = (connectionId: number) => {
        setEditing(null);
        form.clearErrors();
        form.setData({ connection_id: connectionId, type: 'include', pattern: '', priority: 100, is_active: true });
        setFormOpen(true);
    };

    const openEdit = (rule: RuleRow) => {
        setEditing(rule);
        form.clearErrors();
        form.setData({
            connection_id: rule.connection_id,
            type: rule.type,
            pattern: rule.pattern,
            priority: rule.priority,
            is_active: rule.is_active,
        });
        setFormOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) {
            form.put(route('rules.update', editing.id), options);
        } else {
            form.post(route('rules.store'), options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Selection rules" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Selection rules"
                    description="Glob patterns (* and ?) decide whether newly discovered databases are included or excluded. Lowest priority number is checked first; the first match wins. If nothing matches, the connection's new-database policy applies. Manual choices on the Databases page always win."
                />

                {groups.length === 0 && (
                    <Card>
                        <CardContent className="text-muted-foreground py-10 text-center text-sm">Add a connection first.</CardContent>
                    </Card>
                )}

                {groups.map((group) => (
                    <Card key={group.connection.id}>
                        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div className="space-y-1.5">
                                <CardTitle>{group.connection.name}</CardTitle>
                                <CardDescription>
                                    {group.unmatched} database(s) match no rule and follow the policy:{' '}
                                    <strong>{group.connection.policy === 'auto_include' ? 'include automatically' : 'wait for approval'}</strong>.
                                </CardDescription>
                            </div>
                            {canManage && (
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => router.post(route('rules.apply', group.connection.id), {}, { preserveScroll: true })}
                                    >
                                        <Wand2 className="size-4" /> Apply to existing
                                    </Button>
                                    <Button size="sm" onClick={() => openCreate(group.connection.id)}>
                                        <Plus className="size-4" /> Add rule
                                    </Button>
                                </div>
                            )}
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-20">Priority</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Pattern</TableHead>
                                        <TableHead>Matches</TableHead>
                                        <TableHead>Decides (first match)</TableHead>
                                        {canManage && <TableHead className="text-right">Actions</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {group.rules.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-muted-foreground py-6 text-center">
                                                No rules for this connection.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {group.rules.map((rule) => (
                                        <TableRow key={rule.id} className={rule.is_active ? '' : 'opacity-60'}>
                                            <TableCell className="tabular-nums">{rule.priority}</TableCell>
                                            <TableCell>
                                                <StatusBadge status={rule.type === 'include' ? 'included' : 'excluded'} label={rule.type} />
                                                {!rule.is_active && <StatusBadge status="inactive" className="ml-1" />}
                                            </TableCell>
                                            <TableCell className="font-mono">{rule.pattern}</TableCell>
                                            <TableCell className="tabular-nums">{rule.match_count}</TableCell>
                                            <TableCell>
                                                <div className="text-sm tabular-nums">{rule.decides_count}</div>
                                                {rule.sample.length > 0 && (
                                                    <div className="text-muted-foreground line-clamp-1 font-mono text-xs">
                                                        {rule.sample.join(', ')}
                                                        {rule.decides_count > rule.sample.length ? ', …' : ''}
                                                    </div>
                                                )}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell className="text-right">
                                                    <Button size="icon" variant="ghost" onClick={() => openEdit(rule)} aria-label="Edit">
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button size="icon" variant="ghost" onClick={() => setDeleting(rule)} aria-label="Delete">
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit rule' : 'New rule'}</DialogTitle>
                        <DialogDescription>Examples: kreethya_* includes all Kreethya databases, *_test excludes test databases.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid grid-cols-2 gap-3">
                            <FormField id="type" label="Action" error={form.errors.type}>
                                <NativeSelect
                                    id="type"
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value as RuleTypeValue)}
                                >
                                    {types.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <FormField id="priority" label="Priority" error={form.errors.priority} hint="Lower runs first.">
                                <Input
                                    id="priority"
                                    type="number"
                                    min={0}
                                    value={form.data.priority}
                                    onChange={(e) => form.setData('priority', Number(e.target.value))}
                                />
                            </FormField>
                        </div>
                        <FormField id="pattern" label="Pattern" error={form.errors.pattern}>
                            <Input
                                id="pattern"
                                className="font-mono"
                                placeholder="fixflow_*"
                                value={form.data.pattern}
                                onChange={(e) => form.setData('pattern', e.target.value)}
                                required
                            />
                        </FormField>
                        <div className="bg-muted/50 rounded-md border p-3 text-sm">
                            {preview === null ? (
                                <span className="text-muted-foreground">Type a pattern to preview matching databases.</span>
                            ) : (
                                <>
                                    <p className="mb-1 font-medium">Matches {preview.total} database(s)</p>
                                    <p className="text-muted-foreground max-h-28 overflow-y-auto font-mono text-xs break-all">
                                        {preview.matches.join(', ')}
                                        {preview.total > preview.matches.length ? ', …' : ''}
                                    </p>
                                </>
                            )}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save' : 'Create rule'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="Delete rule"
                description={`Delete the ${deleting?.type ?? ''} rule "${deleting?.pattern ?? ''}"? Existing database states are not changed until you apply rules.`}
                confirmLabel="Delete"
                destructive
                onConfirm={() => deleting && router.delete(route('rules.destroy', deleting.id), { onFinish: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}
