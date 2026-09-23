import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Reload the given props every `interval` ms while `active` is true.
 */
export function usePollWhile(active: boolean, only: string[], interval = 3000): void {
    const { start, stop } = usePoll(interval, { only }, { autoStart: false, keepAlive: false });

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
        return () => stop();
    }, [active, start, stop]);
}
