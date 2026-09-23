import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type RestoreDetail, type RestoreStatusValue } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { Check, Loader2, X } from 'lucide-react';

const PIPELINE: { status: RestoreStatusValue; label: string }[] = [
    { status: 'queued', label: 'Queued' },
    { status: 'safety_backup', label: 'Safety backup' },
    { status: 'downloading', label: 'Download' },
    { status: 'verifying', label: 'Verify SHA-256' },
    { status: 'restoring', label: 'Decrypt & import' },
    { status: 'post_check', label: 'Post-check' },
    { status: 'success', label: 'Done' },
];

export default function RestoreShow({ restore }: { restore: RestoreDetail }) {
    const active = restore.status !== 'success' && restore.status !== 'failed';
    usePollWhile(active, ['restore']);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Restore', href: '/restores' },
        { title: `#${restore.id}`, href: `/restores/${restore.id}` },
    ];

    const currentIndex = PIPELINE.findIndex((p) => p.status === restore.status);
    const failedAt = restore.status === 'failed';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Restore #${restore.id}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Restore #${restore.id}: ${restore.source_database} → ${restore.target_database}`}
                    description={`${restore.mode === 'replace' ? 'Replace original' : 'New copy'} on ${restore.target_connection} · requested by ${restore.requested_by ?? '—'} ${formatDateTime(restore.created_at)}`}
                    actions={<StatusBadge status={restore.status} className="px-3 py-1 text-sm" />}
                />

                <Card>
                    <CardContent className="p-4">
                        <ol className="flex flex-wrap gap-2">
                            {PIPELINE.map((p, i) => {
                                const done = restore.status === 'success' || (currentIndex > i && !failedAt);
                                const current = p.status === restore.status;
                                return (
                                    <li
                                        key={p.status}
                                        className={cn(
                                            'flex items-center gap-2 rounded-full border px-3 py-1 text-sm',
                                            done && 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950',
                                            current && active && 'border-sky-300 bg-sky-50 dark:border-sky-900 dark:bg-sky-950',
                                        )}
                                    >
                                        {done ? (
                                            <Check className="size-3.5" />
                                        ) : current && active ? (
                                            <Loader2 className="size-3.5 animate-spin" />
                                        ) : (
                                            <span className="text-muted-foreground text-xs">{i + 1}</span>
                                        )}
                                        {p.label}
                                    </li>
                                );
                            })}
                            {failedAt && (
                                <li className="flex items-center gap-2 rounded-full border border-red-300 bg-red-50 px-3 py-1 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                                    <X className="size-3.5" /> Failed
                                </li>
                            )}
                        </ol>
                        {restore.progress_message && <p className="text-muted-foreground mt-3 text-sm">{restore.progress_message}</p>}
                        {restore.error && (
                            <p className="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">{restore.error}</p>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">Backup file</dt>
                                <dd className="font-mono text-xs break-all">{restore.backup_filename}</dd>
                                <dt className="text-muted-foreground">Backup taken</dt>
                                <dd>{formatDateTime(restore.backup_created_at)}</dd>
                                <dt className="text-muted-foreground">Read from</dt>
                                <dd>{restore.source_destination ?? '—'}</dd>
                                <dt className="text-muted-foreground">Safety backup</dt>
                                <dd>
                                    {restore.safety_backup ? (
                                        <Link className="underline-offset-4 hover:underline" href={route('runs.show', restore.safety_backup.run_id)}>
                                            {restore.safety_backup.filename}
                                        </Link>
                                    ) : (
                                        'not needed / not taken'
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">Started</dt>
                                <dd>{formatDateTime(restore.started_at)}</dd>
                                <dt className="text-muted-foreground">Finished</dt>
                                <dd>{formatDateTime(restore.finished_at)}</dd>
                                <dt className="text-muted-foreground">Post-check</dt>
                                <dd>
                                    {restore.post_check ? (
                                        <span className={restore.post_check.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'}>
                                            {restore.post_check.actual_tables} tables
                                            {restore.post_check.expected_tables !== null ? ` (expected ${restore.post_check.expected_tables})` : ''}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Log</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre className="bg-muted max-h-80 overflow-auto rounded-md p-2 text-xs whitespace-pre-wrap">
                                {restore.log ?? 'Waiting…'}
                            </pre>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
