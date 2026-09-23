import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

export function FlashMessages() {
    const { flash } = usePage<SharedData>().props;
    const [visible, setVisible] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    useEffect(() => {
        if (flash.error) {
            setVisible({ type: 'error', text: flash.error });
        } else if (flash.success) {
            setVisible({ type: 'success', text: flash.success });
        }
    }, [flash.error, flash.success]);

    useEffect(() => {
        if (visible?.type !== 'success') return;
        const timer = window.setTimeout(() => setVisible(null), 5000);
        return () => window.clearTimeout(timer);
    }, [visible]);

    if (!visible) return null;

    const isError = visible.type === 'error';

    return (
        <div className="fixed right-4 bottom-4 z-50 max-w-sm" role={isError ? 'alert' : 'status'}>
            <div
                className={
                    'flex items-start gap-2 rounded-lg border p-3 text-sm shadow-lg ' +
                    (isError
                        ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100')
                }
            >
                {isError ? <XCircle className="mt-0.5 size-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 size-4 shrink-0" />}
                <span className="flex-1">{visible.text}</span>
                <button type="button" onClick={() => setVisible(null)} aria-label="Dismiss">
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
