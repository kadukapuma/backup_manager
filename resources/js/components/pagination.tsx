import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';

function cleanLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return page.total > 0 ? <p className="text-muted-foreground text-xs">{page.total} records</p> : null;
    }

    return (
        <div className="flex flex-col items-center justify-between gap-2 sm:flex-row">
            <p className="text-muted-foreground text-xs">
                Showing {page.from}–{page.to} of {page.total}
            </p>
            <nav className="flex flex-wrap gap-1">
                {page.links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
                            preserveState
                            className={cn(
                                'rounded-md border px-2.5 py-1 text-xs',
                                link.active ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted',
                            )}
                        >
                            {cleanLabel(link.label)}
                        </Link>
                    ) : (
                        <span key={i} className="text-muted-foreground rounded-md border px-2.5 py-1 text-xs opacity-50">
                            {cleanLabel(link.label)}
                        </span>
                    ),
                )}
            </nav>
        </div>
    );
}
