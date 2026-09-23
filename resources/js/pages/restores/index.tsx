import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type RestoreRow } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Restore', href: '/restores' }];

export default function RestoresIndex({ restores }: { restores: Paginated<RestoreRow> }) {
    const can = useCan();
    const active = restores.data.some((r) => r.status !== 'success' && r.status !== 'failed');
    usePollWhile(active, ['restores']);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Restore" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title="Restore"
                    description="Restore a backup over the original database or into a new one. Existing databases get a safety backup first."
                    actions={
                        can('backups.restore') && (
                            <Button asChild>
                                <Link href={route('restores.create')}>
                                    <RotateCcw className="size-4" /> New restore
                                </Link>
                            </Button>
                        )
                    }
                />

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>#</TableHead>
                                    <TableHead>Requested</TableHead>
                                    <TableHead>Backup</TableHead>
                                    <TableHead>Target</TableHead>
                                    <TableHead>Mode</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {restores.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-10 text-center">
                                            No restores yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {restores.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            <Link href={route('restores.show', r.id)} className="font-medium underline-offset-4 hover:underline">
                                                #{r.id}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div className="whitespace-nowrap">{formatDateTime(r.created_at)}</div>
                                            <div className="text-muted-foreground text-xs">{r.requested_by}</div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-mono text-sm">{r.source_database}</div>
                                            <div className="text-muted-foreground text-xs">{formatDateTime(r.backup_created_at)}</div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-mono text-sm">{r.target_database}</div>
                                            <div className="text-muted-foreground text-xs">{r.target_connection}</div>
                                        </TableCell>
                                        <TableCell className="text-sm">{r.mode === 'replace' ? 'Replace original' : 'New copy'}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={r.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <Pagination page={restores} />
            </div>
        </AppLayout>
    );
}
