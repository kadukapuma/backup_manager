import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface FormFieldProps {
    id: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}

export function FormField({ id, label, error, hint, className, children }: FormFieldProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
