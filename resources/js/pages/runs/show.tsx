import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCan } from '@/hooks/use-can';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDateTime, formatDuration, formatSeconds } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type RunFileRow, type RunRow } from '@/types/models';
import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Download, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface Props {
    run: RunRow;
    files: RunFileRow[];
}

function FileCard({ file, canDownload, canRestore }: { file: RunFileRow; canDownload: boolean; canRestore: boolean }) {
    const [open, setOpen] = useState(file.status === 'failed');
    const hasLocal = file.copies.some((c) => c.destination_type === 'local' && (c.status === 'verified' || c.status === 'uploaded'));

    return (
        <Card>
            <CardHeader className="pb-2">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" className="flex items-center gap-2 text-left" onClick={() => setOpen(!open)}>
                        {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                        <CardTitle className="font-mono text-sm">{file.database}</CardTitle>
                        <StatusBadge status={file.status} />
                    </button>
                    <div className="text-muted-foreground flex flex-wrap items-center gap-3 text-xs">
                        <span>{formatBytes(file.size_bytes)}</span>
                        <span>{formatDuration(file.duration_ms)}</span>
                        {canDownload && file.status === 'success' && hasLocal && (
                            <Button asChild size="sm" variant="outline">
                                <a href={route('backups.download', file.id)}>
                                    <Download className="size-4" /> Download
                                </a>
                            </Button>
                        )}
                        {canRestore && file.status === 'success' && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('restores.create', { database: file.database_id, file: file.id })}>
                                    <RotateCcw className="size-4" /> Restore…
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {file.error && <p className="rounded-md bg-red-50 p-2 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">{file.error}</p>}
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {file.copies.map((copy) => (
                        <div key={copy.id} className="rounded-md border p-2">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">{copy.destination}</span>
                                <StatusBadge status={copy.status} />
                            </div>
                            <p className="text-muted-foreground mt-1 truncate font-mono text-xs" title={copy.remote_path}>
                                {copy.remote_path}
                            </p>
                            {copy.error && <p className="mt-1 text-xs text-red-600">{copy.error}</p>}
                        </div>
                    ))}
                    {file.copies.length === 0 && file.status !== 'queued' && file.status !== 'running' && (
                        <p className="text-muted-foreground text-xs">No copies.</p>
                    )}
                </div>
                {open && (
                    <div className="space-y-2">
                        {file.filename && (
                            <p className="text-xs">
                                <span className="text-muted-foreground">File:</span> <span className="font-mono">{file.filename}</span>
                            </p>
                        )}
                        {file.sha256 && (
                            <p className="text-xs break-all">
                                <span className="text-muted-foreground">SHA-256:</span> <span className="font-mono">{file.sha256}</span>
                            </p>
                        )}
                        {file.verifications.length > 0 && (
                            <ul className="space-y-1 text-xs">
                                {file.verifications.map((v, i) => (
                                    <li key={i} className="flex flex-wrap items-center gap-2">
                                        <StatusBadge status={v.status} />
                                        <span className="font-medium">{v.level}</span>
                                        <span className="text-muted-foreground font-mono break-all">
                                            {v.details ? JSON.stringify(v.details) : ''}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {file.log && <pre className="bg-muted max-h-64 overflow-auto rounded-md p-2 text-xs whitespace-pre-wrap">{file.log}</pre>}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function RunShow({ run, files }: Props) {
    const can = useCan();
    const active = run.status === 'queued' || run.status === 'running';
    usePollWhile(active, ['run', 'files']);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Runs', href: '/runs' },
        { title: `#${run.id}`, href: `/runs/${run.id}` },
    ];

    const done = files.filter((f) => f.status === 'success' || f.status === 'failed').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Run #${run.id}`} />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader
                    title={`Run #${run.id} · ${run.plan ?? 'Manual backup'}`}
                    description={`${run.connection ?? ''} · ${run.trigger.replace('_', ' ')}${run.triggered_by ? ` by ${run.triggered_by}` : ''}`}
                    actions={<StatusBadge status={run.status} className="px-3 py-1 text-sm" />}
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-muted-foreground text-xs">Started</div>
                            <div className="font-medium">{formatDateTime(run.started_at ?? run.created_at)}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-muted-foreground text-xs">Progress</div>
                            <div className="font-medium">
                                {done} / {files.length} databases
                            </div>
                            {active && files.length > 0 && (
                                <div className="bg-muted mt-2 h-1.5 overflow-hidden rounded-full">
                                    <div className="bg-primary h-full transition-all" style={{ width: `${(done / files.length) * 100}%` }} />
                                </div>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-muted-foreground text-xs">Total size</div>
                            <div className="font-medium">{formatBytes(run.summary.bytes ?? null)}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-muted-foreground text-xs">Duration</div>
                            <div className="font-medium">{active ? 'running…' : formatSeconds(run.summary.duration_seconds)}</div>
                        </CardContent>
                    </Card>
                </div>

                {(run.summary.error || run.summary.message) && (
                    <Card>
                        <CardContent className={'p-4 text-sm ' + (run.summary.error ? 'text-red-600' : 'text-muted-foreground')}>
                            {run.summary.error ?? run.summary.message}
                        </CardContent>
                    </Card>
                )}

                <div className="space-y-3">
                    {files.map((file) => (
                        <FileCard key={file.id} file={file} canDownload={can('backups.download')} canRestore={can('backups.restore')} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
