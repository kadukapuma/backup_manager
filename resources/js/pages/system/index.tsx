import { CatalogRebuildCard } from '@/components/catalog-rebuild-card';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Option } from '@/types';
import { Deferred, Head, router, useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { FormEventHandler } from 'react';

interface ToolCheck {
    tool: string;
    path: string;
    found: boolean;
    version: string | null;
    error: string | null;
}

interface Props {
    canManage: boolean;
    settings: { age_public_key: string; stale_after_hours: number };
    tools?: ToolCheck[];
    security: {
        ip_allowlist: string[];
        require_two_factor: boolean;
        age_identity_file_configured: boolean;
        age_identity_file_readable: boolean;
        queue_connection: string;
        cache_store: string;
        timezone: string;
    };
    destinations: Option[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'System settings', href: '/system' }];

function ToolsTable({ tools }: { tools: ToolCheck[] }) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Tool</TableHead>
                    <TableHead>Path</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Version / error</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {tools.map((t) => (
                    <TableRow key={t.tool}>
                        <TableCell className="font-medium">{t.tool}</TableCell>
                        <TableCell className="font-mono text-xs">{t.path}</TableCell>
                        <TableCell>
                            <StatusBadge status={t.found ? 'ok' : 'failed'} label={t.found ? 'found' : 'missing'} />
                        </TableCell>
                        <TableCell className="max-w-md text-xs break-all">{t.version ?? t.error}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-1 border-b py-2 last:border-0 sm:flex-row sm:items-center sm:justify-between">
            <span className="text-muted-foreground text-sm">{label}</span>
            <span className="text-sm">{children}</span>
        </div>
    );
}

export default function SystemIndex({ canManage, settings, tools, security, destinations }: Props) {
    const form = useForm<{ age_public_key: string; stale_after_hours: number }>({
        age_public_key: settings.age_public_key,
        stale_after_hours: settings.stale_after_hours,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('system.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="System settings" />
            <div className="flex flex-col gap-4 p-4">
                <PageHeader title="System settings" description="Encryption key, monitoring threshold, installed tools and security status." />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Encryption & monitoring</CardTitle>
                            <CardDescription>
                                Every backup is encrypted with this age public key before it leaves the server. The private key is never stored here;
                                keep it offline and provide it only when restoring.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-4">
                                <FormField
                                    id="age_public_key"
                                    label="age public key (recipient)"
                                    error={form.errors.age_public_key}
                                    hint={
                                        <>
                                            Generate with <code>age-keygen -o backup.key</code> and copy the “public key: age1…” line.
                                        </>
                                    }
                                >
                                    <Textarea
                                        id="age_public_key"
                                        rows={2}
                                        className="font-mono text-xs"
                                        value={form.data.age_public_key}
                                        onChange={(e) => form.setData('age_public_key', e.target.value)}
                                        disabled={!canManage}
                                        placeholder="age1..."
                                    />
                                </FormField>
                                <FormField
                                    id="stale_after_hours"
                                    label="Stale after (hours)"
                                    error={form.errors.stale_after_hours}
                                    hint="A database without a successful backup newer than this is flagged on the dashboard and triggers an alert."
                                >
                                    <Input
                                        id="stale_after_hours"
                                        type="number"
                                        min={1}
                                        value={form.data.stale_after_hours}
                                        onChange={(e) => form.setData('stale_after_hours', Number(e.target.value))}
                                        disabled={!canManage}
                                    />
                                </FormField>
                                {canManage && (
                                    <div>
                                        <Button type="submit" disabled={form.processing}>
                                            Save settings
                                        </Button>
                                    </div>
                                )}
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Security & runtime</CardTitle>
                            <CardDescription>These values come from the .env file on the server.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Row label="IP allowlist">
                                {security.ip_allowlist.length > 0 ? (
                                    <span className="font-mono text-xs">{security.ip_allowlist.join(', ')}</span>
                                ) : (
                                    <StatusBadge status="inactive" label="disabled (all IPs allowed)" />
                                )}
                            </Row>
                            <Row label="Two-factor required">
                                <StatusBadge
                                    status={security.require_two_factor ? 'ok' : 'inactive'}
                                    label={security.require_two_factor ? 'yes' : 'no'}
                                />
                            </Row>
                            <Row label="age identity file (AGE_IDENTITY_FILE)">
                                {!security.age_identity_file_configured ? (
                                    <StatusBadge status="inactive" label="not set (paste key at restore)" />
                                ) : security.age_identity_file_readable ? (
                                    <StatusBadge status="ok" label="readable" />
                                ) : (
                                    <StatusBadge status="failed" label="not readable" />
                                )}
                            </Row>
                            <Row label="Queue connection">{security.queue_connection}</Row>
                            <Row label="Cache / lock store">{security.cache_store}</Row>
                            <Row label="Timezone">{security.timezone}</Row>
                        </CardContent>
                    </Card>
                </div>

                {canManage && (
                    <Card id="tools">
                        <CardHeader className="flex flex-row items-start justify-between gap-2">
                            <div className="space-y-1.5">
                                <CardTitle>Tool check</CardTitle>
                                <CardDescription>
                                    Binaries used for backups and restores. Paths are set in config/backup-manager.php and .env.
                                </CardDescription>
                            </div>
                            <Button variant="secondary" size="sm" onClick={() => router.reload({ only: ['tools'] })}>
                                <RefreshCw className="size-4" /> Re-check
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <Deferred data="tools" fallback={<Skeleton className="h-40 w-full" />}>
                                <ToolsTable tools={tools ?? []} />
                            </Deferred>
                        </CardContent>
                    </Card>
                )}

                {canManage && <CatalogRebuildCard destinations={destinations} />}
            </div>
        </AppLayout>
    );
}
