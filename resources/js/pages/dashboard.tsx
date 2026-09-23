import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { usePollWhile } from '@/hooks/use-poll-while';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDateTime, formatSeconds, timeAgo } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { type RunRow } from '@/types/models';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';

interface Props {
    health: { ok: boolean; problems: string[]; warnings: string[] };
    stats: { included: number; backups_24h: number; bytes_24h: number; running: number; stale_after_hours: number };
    stale: { id: number; name: string; connection: string; last_success_at: string | null }[];
    staleCount: number;
    runs: RunRow[];
    destinations: {
        id: number;
        name: string;
        type: string;
        is_active: boolean;
        last_test_status: 'ok' | 'running' | 'failed' | null;
        stored_bytes: number;
        copies: number;
        free_space_bytes: number | null;
    }[];
    pending: { id: number; name: string; connection: string; size_bytes: number; first_seen_at: string | null }[];
    pendingCount: number;
    failedJobs: { id: number; queue: string; job: string; error: string; failed_at: string }[];
    failedJobsCount: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

function StatTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-muted-foreground text-xs">{label}</div>
                <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
                {hint && <div className="text-muted-foreground mt-0.5 text-xs">{hint}</div>}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({
    health,
    stats,
    stale,
    staleCount,
    runs,
    destinations,
    pending,
    pendingCount,
    failedJobs,
    failedJobsCount,
}: Props) {
    const can = useCan();
    usePollWhile(stats.running > 0, ['runs', 'stats', 'health', 'stale', 'staleCount']);
    const maxStored = Math.max(1, ...destinations.map((d) => d.stored_bytes));

    const approve = (ids: number[]) => router.post(route('databases.state'), { ids, state: 'included' }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-4 p-4">
                <Card
                    className={
                        health.ok
                            ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40'
                            : 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40'
                    }
                >
                    <CardContent className="flex items-start gap-3 p-4">
                        {health.ok ? (
                            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                            <XCircle className="mt-0.5 size-6 shrink-0 text-red-600 dark:text-red-400" />
                        )}
                        <div className="space-y-1">
                            <div className="text-lg font-semibold">{health.ok ? 'All backups healthy' : 'Attention needed'}</div>
                            {health.problems.map((p) => (
                                <p key={p} className="text-sm">
                                    {p}
                                </p>
                            ))}
                            {health.warnings.map((w) => (
                                <p key={w} className="flex items-center gap-1.5 text-sm text-amber-800 dark:text-amber-300">
                                    <AlertTriangle className="size-4" /> {w}
                                </p>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile label="Databases backed up" value={String(stats.included)} hint="included, on active connections" />
                    <StatTile label="Successful backups (24 h)" value={String(stats.backups_24h)} hint={formatBytes(stats.bytes_24h)} />
                    <StatTile label={`Stale (> ${stats.stale_after_hours} h)`} value={String(staleCount)} />
                    <StatTile label="Runs in progress" value={String(stats.running)} />
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base">Databases without a recent backup</CardTitle>
                            {staleCount > stale.length && (
                                <span className="text-muted-foreground text-xs">
                                    showing {stale.length} of {staleCount}
                                </span>
                            )}
                        </CardHeader>
                        <CardContent className="p-0">
                            {stale.length === 0 ? (
                                <p className="text-muted-foreground px-6 pb-6 text-sm">Every included database has a recent successful backup.</p>
                            ) : (
                                <Table>
                                    <TableBody>
                                        {stale.map((d) => (
                                            <TableRow key={d.id}>
                                                <TableCell className="font-mono text-sm">{d.name}</TableCell>
                                                <TableCell className="text-muted-foreground">{d.connection}</TableCell>
                                                <TableCell className="text-right" title={formatDateTime(d.last_success_at)}>
                                                    {d.last_success_at ? timeAgo(d.last_success_at) : 'never'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base">Pending new databases</CardTitle>
                            {can('databases.operate') && pending.length > 1 && (
                                <Button size="sm" variant="secondary" onClick={() => approve(pending.map((p) => p.id))}>
                                    Approve all shown
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="p-0">
                            {pending.length === 0 ? (
                                <p className="text-muted-foreground px-6 pb-6 text-sm">No databases waiting for approval.</p>
                            ) : (
                                <Table>
                                    <TableBody>
                                        {pending.map((d) => (
                                            <TableRow key={d.id}>
                                                <TableCell className="font-mono text-sm">{d.name}</TableCell>
                                                <TableCell className="text-muted-foreground">{d.connection}</TableCell>
                                                <TableCell className="text-muted-foreground text-right text-xs">
                                                    {formatBytes(d.size_bytes)} · seen {timeAgo(d.first_seen_at)}
                                                </TableCell>
                                                {can('databases.operate') && (
                                                    <TableCell className="text-right">
                                                        <Button size="sm" variant="outline" onClick={() => approve([d.id])}>
                                                            Approve
                                                        </Button>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                            {pendingCount > pending.length && (
                                <p className="text-muted-foreground px-6 py-2 text-xs">
                                    <Link href="/databases?state=pending" className="underline-offset-4 hover:underline">
                                        See all {pendingCount}
                                    </Link>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">Last 10 runs</CardTitle>
                        <Link href="/runs" className="text-muted-foreground text-xs underline-offset-4 hover:underline">
                            All runs
                        </Link>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Run</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Plan</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Databases</TableHead>
                                    <TableHead className="text-right">Size</TableHead>
                                    <TableHead className="text-right">Duration</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-muted-foreground py-6 text-center">
                                            No runs yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {runs.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            <Link href={route('runs.show', r.id)} className="font-medium underline-offset-4 hover:underline">
                                                #{r.id}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">{formatDateTime(r.started_at ?? r.created_at)}</TableCell>
                                        <TableCell>{r.plan ?? r.trigger.replace('_', ' ')}</TableCell>
                                        <TableCell>
                                            <StatusBadge status={r.status} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.summary.succeeded !== undefined ? `${r.summary.succeeded}/${r.files_count}` : r.files_count}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatBytes(r.summary.bytes ?? null)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatSeconds(r.summary.duration_seconds)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Storage used per destination</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {destinations.length === 0 && <p className="text-muted-foreground text-sm">No destinations configured.</p>}
                            {destinations.map((d) => (
                                <div key={d.id} className="space-y-1">
                                    <div className="flex items-center justify-between gap-2 text-sm">
                                        <span className="flex items-center gap-2">
                                            {d.name}
                                            {d.last_test_status === 'failed' && <StatusBadge status="failed" label="test failed" />}
                                            {!d.is_active && <StatusBadge status="inactive" />}
                                        </span>
                                        <span className="text-muted-foreground text-xs tabular-nums">
                                            {formatBytes(d.stored_bytes)} · {d.copies} copies
                                            {d.free_space_bytes !== null ? ` · ${formatBytes(d.free_space_bytes)} free` : ''}
                                        </span>
                                    </div>
                                    <div
                                        className="bg-muted h-2 overflow-hidden rounded-full"
                                        title={`${d.name}: ${formatBytes(d.stored_bytes)} in ${d.copies} copies`}
                                        role="img"
                                        aria-label={`${d.name}: ${formatBytes(d.stored_bytes)} stored`}
                                    >
                                        <div
                                            className="bg-primary/70 h-full rounded-full"
                                            style={{ width: `${(d.stored_bytes / maxStored) * 100}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Failed queue jobs ({failedJobsCount})</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {failedJobs.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No failed jobs.</p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {failedJobs.map((j) => (
                                        <li key={j.id} className="rounded-md border p-2">
                                            <div className="flex justify-between gap-2">
                                                <span className="font-medium">{j.job}</span>
                                                <span className="text-muted-foreground text-xs">
                                                    {j.queue} · {j.failed_at}
                                                </span>
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs break-all">{j.error}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {failedJobsCount > 0 && (
                                <p className="text-muted-foreground mt-3 text-xs">
                                    Inspect on the server with <code>php artisan queue:failed</code>, then retry or flush.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
