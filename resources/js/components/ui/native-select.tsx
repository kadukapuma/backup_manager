import * as React from 'react';

import { cn } from '@/lib/utils';

const NativeSelect = React.forwardRef<HTMLSelectElement, React.ComponentProps<'select'>>(({ className, children, ...props }, ref) => (
    <select
        ref={ref}
        className={cn(
            'border-input bg-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
            className,
        )}
        {...props}
    >
        {children}
    </select>
));
NativeSelect.displayName = 'NativeSelect';

export { NativeSelect };
