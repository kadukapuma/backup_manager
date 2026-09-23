import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type ChallengeForm = {
    code: string;
    recovery_code: string;
};

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<ChallengeForm>({
        code: '',
        recovery_code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.login.store'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={useRecovery ? 'Enter one of your emergency recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        >
            <Head title="Two-factor authentication" />
            <form className="flex flex-col gap-6" onSubmit={submit}>
                {useRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Recovery code</Label>
                        <Input
                            id="recovery_code"
                            autoFocus
                            autoComplete="one-time-code"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Authentication code</Label>
                        <Input
                            id="code"
                            inputMode="numeric"
                            autoFocus
                            autoComplete="one-time-code"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Log in
                </Button>

                <button
                    type="button"
                    className="text-muted-foreground text-center text-sm underline underline-offset-4"
                    onClick={() => setUseRecovery(!useRecovery)}
                >
                    {useRecovery ? 'Use an authentication code' : 'Use a recovery code'}
                </button>
            </form>
        </AuthLayout>
    );
}
