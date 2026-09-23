import { cn } from '@/lib/utils';

type Tone = 'green' | 'red' | 'amber' | 'blue' | 'gray';

const TONES: Record<Tone, string> = {
    green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    red: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    blue: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    gray: 'bg-muted text-muted-foreground',
};

const STATUS_TONES: Record<string, Tone> = {
    ok: 'green',
    success: 'green',
    verified: 'green',
    included: 'green',
    passed: 'green',
    active: 'green',
    uploaded: 'blue',
    running: 'blue',
    queued: 'blue',
    safety_backup: 'blue',
    downloading: 'blue',
    verifying: 'blue',
    restoring: 'blue',
    post_check: 'blue',
    pending: 'amber',
    partial: 'amber',
    missing: 'amber',
    failed: 'red',
    excluded: 'gray',
    deleted: 'gray',
    inactive: 'gray',
};

export function StatusBadge({ status, label, className }: { status: string | null | undefined; label?: string; className?: string }) {
    if (!status) {
        return <span className="text-muted-foreground text-xs">—</span>;
    }
    const tone = STATUS_TONES[status] ?? 'gray';
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', TONES[tone], className)}>
            {label ?? status.replace(/_/g, ' ')}
        </span>
    );
}
