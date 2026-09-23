import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useState } from 'react';

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel?: string;
    destructive?: boolean;
    processing?: boolean;
    /** When set, the user must type this exact text before confirming. */
    typeToConfirm?: string;
    onConfirm: () => void;
}

export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Confirm',
    destructive = false,
    processing = false,
    typeToConfirm,
    onConfirm,
}: ConfirmDialogProps) {
    const [typed, setTyped] = useState('');
    const blocked = typeToConfirm !== undefined && typed !== typeToConfirm;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) setTyped('');
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {typeToConfirm !== undefined && (
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-text">
                            Type <span className="font-mono font-semibold">{typeToConfirm}</span> to confirm
                        </Label>
                        <Input id="confirm-text" value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" />
                    </div>
                )}
                <DialogFooter className="gap-2">
                    <Button variant="secondary" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button variant={destructive ? 'destructive' : 'default'} disabled={blocked || processing} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
