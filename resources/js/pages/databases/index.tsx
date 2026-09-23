import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDateTime, timeAgo } from '@/lib/format';
import { type BreadcrumbItem, type Option, type Paginated } from '@/types';
import { type DatabaseRow } from '@/types/models';
import { Head, router } from '@inertiajs/react';
import { Check, CircleSlash, Play, RotateCcw, Search, Wand2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    connection: string;
    search: string;
    state: string;
}

interface Props {
    databases: Paginated<DatabaseRow>;
    connections: Option[];
    filters: Filters;
    counts: { included: number; excluded: number; pending: number; missing: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Databases', href: '/databases' }];

export default function DatabasesIndex({ databases, connections, filters, counts }: Props) {
    const can = useCan();
    const canOperate = can('databases.operate');
    const canBackup = can('backups.run');
    const [values, setValues] = useState<Filters>(filters);
    const [selected, setSelected] = useState<number[]>([]);

    const load = (next: Filters) => {
        setValues(next);
        setSelected([]);
        const query = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== ''));
        router.get(route('databases.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        load(values);
    };

    const setState = (ids: number[], state: 'included' | 'excluded' | 'automatic') => {
        router.post(route('databases.state'), { ids, state }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const backupNow = (ids: number[]) => {
        router.post(route('backups.manual'), { database_ids: ids }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    };

    const allIds = databases.data.map((d) => d.id);
    const allSelected = allIds.length > 0 && allIds.every((id) => selected.includes(id));
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const stateTabs: { value: string; label: string; count?: number }[] = [
        { value: '', label: 'All' },
        { value: 'included', label: 'Included', count: counts.included },
        { value: 'pending', label: 'Pending approval', count: counts.pending },
        { value: 'excluded', label: 'Excluded', count: counts.excluded },
        { value: 'missing', label: 'Missing', count: counts.missing },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Databases" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Databases"
                    description="Choose which databases are backed up. Pending databases are not backed up until approved."
                />

                <div className="flex flex-wrap gap-2">
                    {stateTabs.map((tab) => (
                        <Button
                            key={tab.value}
                            size="sm"
                            variant={values.state === tab.value ? 'default' : 'outline'}
                            onClick={() => load({ ...values, state: tab.value })}
                        >
                            {tab.label}
                            {tab.count !== undefined && <span className="ml-1 opacity-70">({tab.count})</span>}
                        </Button>
                    ))}
                </div>

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={search} className="flex flex-col gap-2 sm:flex-row">
                            <NativeSelect
                                className="sm:w-56"
                                value={values.connection}
                                onChange={(e) => load({ ...values, connection: e.target.value })}
                                aria-label="Connection"
                            >
                                <option value="">All connections</option>
                                {connections.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                placeholder="Search database name…"
                                value={values.search}
                                onChange={(e) => setValues({ ...values, search: e.target.value })}
                                className="sm:max-w-xs"
                            />
                            <Button type="submit" variant="secondary">
                                <Search className="size-4" /> Search
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {selected.length > 0 && (canOperate || canBackup) && (
                    <div className="bg-muted flex flex-wrap items-center gap-2 rounded-lg p-2 text-sm">
                        <span className="px-2 font-medium">{selected.length} selected</span>
                        {canOperate && (
                            <>
                                <Button size="sm" onClick={() => setState(selected, 'included')}>
                                    <Check className="size-4" /> Include / approve
                                </Button>
                                <Button size="sm" variant="secondary" onClick={() => setState(selected, 'excluded')}>
                                    <CircleSlash className="size-4" /> Exclude
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setState(selected, 'automatic')}>
                                    <Wand2 className="size-4" /> Use rules
                                </Button>
                            </>
                        )}
                        {canBackup && (
                            <Button size="sm" variant="secondary" onClick={() => backupNow(selected)}>
                                <Play className="size-4" /> Back up now
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                            <RotateCcw className="size-4" /> Clear
                        </Button>
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {(canOperate || canBackup) && (
                                        <TableHead className="w-8">
                                            <Checkbox
                                                checked={allSelected}
                                                onCheckedChange={(v) => setSelected(v === true ? allIds : [])}
                                                aria-label="Select all"
                                            />
                                        </TableHead>
                                    )}
                                    <TableHead>Database</TableHead>
                                    <TableHead>Connection</TableHead>
                                    <TableHead>State</TableHead>
                                    <TableHead className="text-right">Size</TableHead>
                                    <TableHead className="text-right">Tables</TableHead>
                                    <TableHead>Last good backup</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {databases.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-muted-foreground py-10 text-center">
                                            No databases found. Add a connection or press Refresh on a connection to run discovery.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {databases.data.map((db) => (
                                    <TableRow key={db.id} data-state={selected.includes(db.id) ? 'selected' : undefined}>
                                        {(canOperate || canBackup) && (
                                            <TableCell>
                                                <Checkbox
                                                    checked={selected.includes(db.id)}
                                                    onCheckedChange={() => toggle(db.id)}
                                                    aria-label={`Select ${db.name}`}
                                                />
                                            </TableCell>
                                        )}
                                        <TableCell className="font-mono text-sm font-medium">{db.name}</TableCell>
                                        <TableCell className="text-muted-foreground">{db.connection_name}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-1">
                                                {db.missing_since ? (
                                                    <StatusBadge status="missing" label={`missing since ${formatDateTime(db.missing_since)}`} />
                                                ) : (
                                                    <StatusBadge status={db.state} />
                                                )}
                                                <span className="text-muted-foreground text-xs">by {db.state_source}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatBytes(db.size_bytes)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{db.table_count}</TableCell>
                                        <TableCell title={formatDateTime(db.last_backup_at)}>
                                            {db.last_backup_at ? timeAgo(db.last_backup_at) : <span className="text-muted-foreground">never</span>}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                {canOperate && !db.missing_since && db.state !== 'included' && (
                                                    <Button size="sm" variant="outline" onClick={() => setState([db.id], 'included')}>
                                                        {db.state === 'pending' ? 'Approve' : 'Include'}
                                                    </Button>
                                                )}
                                                {canOperate && !db.missing_since && db.state !== 'excluded' && (
                                                    <Button size="sm" variant="ghost" onClick={() => setState([db.id], 'excluded')}>
                                                        Exclude
                                                    </Button>
                                                )}
                                                {canBackup && db.state === 'included' && !db.missing_since && (
                                                    <Button size="sm" variant="ghost" onClick={() => backupNow([db.id])} aria-label="Back up now">
                                                        <Play className="size-4" />
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
                <Pagination page={databases} />
            </div>
        </AppLayout>
    );
}
