import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/native-select';
import { type Option } from '@/types';
import { router } from '@inertiajs/react';
import { DatabaseBackup } from 'lucide-react';
import { useState } from 'react';

export function CatalogRebuildCard({ destinations }: { destinations: Option[] }) {
    const [destinationId, setDestinationId] = useState(destinations[0]?.value ?? '');
    const [confirming, setConfirming] = useState(false);
    const label = destinations.find((d) => d.value === destinationId)?.label ?? '';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Rebuild catalog</CardTitle>
                <CardDescription>
                    Reads every manifest on a destination and adds missing backups and copies to this panel. Use it on a fresh install to see and
                    restore backups made by a previous server. Existing records are kept; nothing is deleted.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 sm:flex-row">
                <NativeSelect
                    className="sm:max-w-sm"
                    value={destinationId}
                    onChange={(e) => setDestinationId(e.target.value)}
                    aria-label="Destination"
                >
                    {destinations.length === 0 && <option value="">Add a destination first</option>}
                    {destinations.map((d) => (
                        <option key={d.value} value={d.value}>
                            {d.label}
                        </option>
                    ))}
                </NativeSelect>
                <Button variant="secondary" disabled={destinationId === ''} onClick={() => setConfirming(true)}>
                    <DatabaseBackup className="size-4" /> Rebuild from this destination
                </Button>
            </CardContent>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Rebuild catalog"
                description={`Scan ${label} for backup manifests and import them? This runs in the background and may take a while for large destinations.`}
                confirmLabel="Start rebuild"
                onConfirm={() =>
                    router.post(
                        route('system.rebuild-catalog'),
                        { destination_id: destinationId },
                        { preserveScroll: true, onFinish: () => setConfirming(false) },
                    )
                }
            />
        </Card>
    );
}
