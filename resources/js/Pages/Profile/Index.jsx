import { router, useForm } from '@inertiajs/react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export default function Index({
    name,
    email,
    pendingEmail,
    showVerification,
    verificationExpiryMinutes,
    twoFactor,
    updateUrl,
    requestEmailChangeUrl,
    verifyEmailChangeUrl,
    resendCodeUrl,
    cancelEmailChangeUrl,
    updatePasswordUrl,
}) {
    const profile = useForm({ name });
    const emailChange = useForm({ new_email: '' });
    const verify = useForm({ email_verification_code: '' });
    const password = useForm({ current_password: '', new_password: '', new_password_confirmation: '' });

    function submitProfile(e) {
        e.preventDefault();
        profile.put(updateUrl);
    }

    function submitEmailChange(e) {
        e.preventDefault();
        emailChange.post(requestEmailChangeUrl);
    }

    function submitVerify(e) {
        e.preventDefault();
        verify.post(verifyEmailChangeUrl);
    }

    function resendCode(e) {
        e.preventDefault();
        router.post(resendCodeUrl);
    }

    function cancelEmailChange(e) {
        e.preventDefault();
        router.post(cancelEmailChangeUrl);
    }

    function submitPassword(e) {
        e.preventDefault();
        password.put(updatePasswordUrl, {
            onSuccess: () => password.reset(),
        });
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Profile</h1>
                <div className="subtitle">Your user profile settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10">
                        <a href="/profile" className="dark:text-white">
                            General
                        </a>
                        <a href="/profile/appearance">Appearance</a>
                        <div className="flex-1" />
                    </nav>
                </div>
            </div>

            <form onSubmit={submitProfile} className="flex flex-col">
                <div className="flex items-center gap-2">
                    <h2>General</h2>
                    <button type="submit" disabled={profile.processing}>
                        Save
                    </button>
                </div>
                <div className="flex flex-col gap-2 lg:flex-row items-end">
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="profile-name"
                            name="profile-name"
                            value={profile.data.name}
                            onChange={(e) => profile.setData('name', e.target.value)}
                        />
                        {profile.errors.name && <span className="text-error">{profile.errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Email
                        <input id="profile-email" name="profile-email" value={email} readOnly />
                    </label>
                </div>
            </form>

            <div className="flex flex-col pt-4">
                {!showVerification && (
                    <form onSubmit={submitEmailChange}>
                        <div className="flex gap-2 items-end">
                            <label className="flex flex-col gap-1">
                                New Email Address
                                <input
                                    id="profile-new-email"
                                    name="profile-new-email"
                                    type="email"
                                    value={emailChange.data.new_email}
                                    onChange={(e) => emailChange.setData('new_email', e.target.value)}
                                />
                                {emailChange.errors.new_email && <span className="text-error">{emailChange.errors.new_email}</span>}
                            </label>
                            <button type="submit" disabled={emailChange.processing}>
                                Send Verification Code
                            </button>
                        </div>
                        <div className="text-xs font-bold dark:text-warning pt-2">A verification code will be sent to your new email address.</div>
                    </form>
                )}

                {showVerification && (
                    <form onSubmit={submitVerify}>
                        <div className="flex gap-2 items-end">
                            <label className="flex flex-col gap-1">
                                Verification Code (6 digits)
                                <input
                                    id="profile-email-verification-code"
                                    name="profile-email-verification-code"
                                    maxLength={6}
                                    value={verify.data.email_verification_code}
                                    onChange={(e) => verify.setData('email_verification_code', e.target.value)}
                                />
                                {verify.errors.email_verification_code && <span className="text-error">{verify.errors.email_verification_code}</span>}
                            </label>
                            <button type="submit" disabled={verify.processing}>
                                Verify &amp; Update Email
                            </button>
                            <button type="button" onClick={resendCode}>
                                Resend Code
                            </button>
                            <button type="button" onClick={cancelEmailChange}>
                                Cancel
                            </button>
                        </div>
                        <div className="text-xs font-bold dark:text-warning pt-2">
                            Verification code sent to {pendingEmail}. The code is valid for {verificationExpiryMinutes} minutes.
                        </div>
                    </form>
                )}
            </div>

            <form onSubmit={submitPassword} className="flex flex-col pt-4">
                <div className="flex items-center gap-2 pb-2">
                    <h2>Change Password</h2>
                    <button type="submit" disabled={password.processing}>
                        Save
                    </button>
                </div>
                <div className="text-xs font-bold dark:text-warning pb-2">Resetting the password will logout all sessions.</div>
                <div className="flex flex-col gap-2">
                    <label className="flex flex-col gap-1">
                        Current Password
                        <input
                            id="profile-current-password"
                            name="profile-current-password"
                            type="password"
                            value={password.data.current_password}
                            onChange={(e) => password.setData('current_password', e.target.value)}
                        />
                        {password.errors.current_password && <span className="text-error">{password.errors.current_password}</span>}
                    </label>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1">
                            New Password
                            <input
                                id="profile-new-password"
                                name="profile-new-password"
                                type="password"
                                value={password.data.new_password}
                                onChange={(e) => password.setData('new_password', e.target.value)}
                            />
                            {password.errors.new_password && <span className="text-error">{password.errors.new_password}</span>}
                        </label>
                        <label className="flex flex-col gap-1">
                            New Password Again
                            <input
                                id="profile-new-password-confirmation"
                                name="profile-new-password-confirmation"
                                type="password"
                                value={password.data.new_password_confirmation}
                                onChange={(e) => password.setData('new_password_confirmation', e.target.value)}
                            />
                        </label>
                    </div>
                </div>
            </form>

            <h2 className="py-4">Two-factor Authentication</h2>
            {twoFactor.status === 'two-factor-authentication-enabled' && (
                <div>
                    <div className="mb-4 font-medium">
                        Please finish configuring two factor authentication below. Read the QR code or enter the secret key manually.
                    </div>
                    <div className="flex flex-col gap-4">
                        <form action="/user/confirmed-two-factor-authentication" method="POST" className="flex items-end gap-2">
                            <input type="hidden" name="_token" value={csrfToken()} />
                            <label className="flex flex-col gap-1">
                                One time (OTP) code
                                <input type="text" inputMode="numeric" pattern="[0-9]*" name="code" required />
                            </label>
                            <button type="submit">Validate 2FA</button>
                        </form>
                        <div className="flex flex-col items-start">
                            <div
                                className="flex items-center justify-center w-80 h-80 bg-white p-4 border-4 border-gray-300 rounded-lg shadow-lg"
                                dangerouslySetInnerHTML={{ __html: twoFactor.qrCodeSvg }}
                            />
                            <div className="py-4 w-full">
                                <div className="flex flex-col gap-2 pb-2">
                                    <code>{twoFactor.secret}</code>
                                    <code>{twoFactor.qrCodeUrl}</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            {twoFactor.status === 'two-factor-authentication-confirmed' && (
                <div>
                    <div className="mb-4">Two factor authentication confirmed and enabled successfully.</div>
                    <div>
                        <div className="pb-6">Here are the recovery codes for your account. Please store them in a secure location.</div>
                        <div className="dark:text-white">
                            {twoFactor.recoveryCodes?.map((code) => (
                                <div key={code}>{code}</div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
            {!twoFactor.status && (
                <>
                    {twoFactor.confirmed ? (
                        <>
                            <div className="pb-4">
                                Two factor authentication is <span className="text-helper">enabled</span>.
                            </div>
                            <div className="flex gap-2">
                                <form action="/user/two-factor-authentication" method="POST">
                                    <input type="hidden" name="_token" value={csrfToken()} />
                                    <input type="hidden" name="_method" value="DELETE" />
                                    <button type="submit">Disable</button>
                                </form>
                                <form action="/user/two-factor-recovery-codes" method="POST">
                                    <input type="hidden" name="_token" value={csrfToken()} />
                                    <button type="submit">Regenerate Recovery Codes</button>
                                </form>
                            </div>
                            {twoFactor.status === 'recovery-codes-generated' && (
                                <div>
                                    <div className="py-6">Here are the recovery codes for your account. Please store them in a secure location.</div>
                                    <div className="dark:text-white">
                                        {twoFactor.recoveryCodes?.map((code) => (
                                            <div key={code}>{code}</div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    ) : (
                        <form action="/user/two-factor-authentication" method="POST">
                            <input type="hidden" name="_token" value={csrfToken()} />
                            <button type="submit">Configure</button>
                        </form>
                    )}
                </>
            )}
        </div>
    );
}
