import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';

interface Props {
    enabled: boolean;
    confirmed: boolean;
    required: boolean;
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-factor authentication', href: '/settings/two-factor' }];

export default function TwoFactor({ enabled, confirmed, required, qrCodeSvg, setupKey, recoveryCodes }: Props) {
    const confirmForm = useForm<{ code: string }>({ code: '' });

    const enable = () => router.post(route('two-factor.enable'), {}, { preserveScroll: true });
    const disable = () => router.delete(route('two-factor.disable'), { preserveScroll: true });
    const regenerate = () => router.post(route('two-factor.regenerate-recovery-codes'), {}, { preserveScroll: true });

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        confirmForm.post(route('two-factor.confirm'), {
            errorBag: 'confirmTwoFactorAuthentication',
            preserveScroll: true,
            onFinish: () => confirmForm.reset('code'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor authentication" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Two-factor authentication"
                        description="Protect your account with a time-based code from an authenticator app."
                    />

                    {required && !confirmed && (
                        <Alert variant="destructive">
                            <AlertTitle>Required</AlertTitle>
                            <AlertDescription>Two-factor authentication must be enabled before you can use the panel.</AlertDescription>
                        </Alert>
                    )}

                    {!enabled && (
                        <div className="space-y-4">
                            <p className="text-muted-foreground text-sm">Two-factor authentication is not enabled.</p>
                            <Button onClick={enable}>
                                <ShieldCheck className="size-4" />
                                Enable two-factor authentication
                            </Button>
                        </div>
                    )}

                    {enabled && !confirmed && (
                        <div className="space-y-4">
                            <p className="text-sm">
                                Scan this QR code with your authenticator app (Google Authenticator, 1Password, Authy…), then enter the 6-digit code
                                to finish.
                            </p>
                            {qrCodeSvg && <div className="inline-block rounded-lg bg-white p-3" dangerouslySetInnerHTML={{ __html: qrCodeSvg }} />}
                            {setupKey && (
                                <p className="text-sm">
                                    Setup key: <span className="font-mono">{setupKey}</span>
                                </p>
                            )}
                            <form onSubmit={confirm} className="flex max-w-xs flex-col gap-2">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={confirmForm.data.code}
                                    onChange={(e) => confirmForm.setData('code', e.target.value)}
                                />
                                <InputError message={confirmForm.errors.code} />
                                <div className="flex gap-2">
                                    <Button type="submit" disabled={confirmForm.processing}>
                                        Confirm
                                    </Button>
                                    <Button type="button" variant="secondary" onClick={disable}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </div>
                    )}

                    {confirmed && (
                        <div className="space-y-4">
                            <p className="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                <ShieldCheck className="size-4" /> Two-factor authentication is on.
                            </p>
                            <div className="space-y-2">
                                <p className="text-sm">
                                    Store these recovery codes in a password manager. Each code can be used once if you lose your device.
                                </p>
                                <div className="bg-muted grid max-w-md grid-cols-2 gap-1 rounded-lg p-3 font-mono text-sm">
                                    {recoveryCodes.map((code) => (
                                        <span key={code}>{code}</span>
                                    ))}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button variant="secondary" onClick={regenerate}>
                                    Regenerate recovery codes
                                </Button>
                                <Button variant="destructive" onClick={disable}>
                                    <ShieldOff className="size-4" />
                                    Disable
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
