import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * React port of the last page anywhere in the app that used <x-layout>/layouts/app.blade.php
 * (Phase 78's cascade re-verification found this was the only remaining consumer). Bare layout,
 * not AppLayout — matches ForcePasswordReset.jsx's precedent for a pre-condition gate screen.
 * SwitchTeam/DeploymentsIndicator (part of the old chrome this page dragged along) are both built
 * for a user actively working in the app, not someone who hasn't verified their email yet.
 */
export default function VerifyEmail({ resendUrl }) {
    const { props } = usePage();
    const [sending, setSending] = useState(false);

    function resend() {
        setSending(true);
        router.post(
            resendUrl,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSending(false),
            },
        );
    }

    return (
        <section className="bg-gray-50 dark:bg-base">
            <div className="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
                <a className="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">Coolify</a>
                <div className="w-full bg-white shadow-sm md:mt-0 sm:max-w-md xl:p-0 dark:bg-base">
                    <div className="p-6 space-y-4 md:space-y-6 sm:p-8 text-center">
                        <h1>Verification Email Sent</h1>
                        <div>To activate your account, please open the email and follow the instructions.</div>
                        {props.flash?.success && <div className="text-success">{props.flash.success}</div>}
                        {props.flash?.error && <div className="text-error">{props.flash.error}</div>}
                        <button type="button" onClick={resend} disabled={sending}>
                            Send Verification Email Again
                        </button>
                    </div>
                </div>
            </div>
        </section>
    );
}

VerifyEmail.layout = (page) => page;
