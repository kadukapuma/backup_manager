const TIME_ZONE = 'Asia/Colombo';

export function formatBytes(bytes: number | null | undefined, decimals = 1): string {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : decimals)} ${units[i]}`;
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: TIME_ZONE,
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date(iso));
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', { timeZone: TIME_ZONE, year: 'numeric', month: 'short', day: '2-digit' }).format(new Date(iso));
}

export function formatDuration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) return '—';
    const seconds = Math.round(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ${seconds % 60}s`;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export function timeAgo(iso: string | null | undefined): string {
    if (!iso) return 'never';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    const future = diff < 0;
    const abs = Math.abs(diff);
    const units: [number, string][] = [
        [86400, 'day'],
        [3600, 'hour'],
        [60, 'minute'],
    ];
    for (const [size, name] of units) {
        if (abs >= size) {
            const n = Math.floor(abs / size);
            const label = `${n} ${name}${n === 1 ? '' : 's'}`;
            return future ? `in ${label}` : `${label} ago`;
        }
    }
    return future ? 'in a moment' : 'just now';
}
