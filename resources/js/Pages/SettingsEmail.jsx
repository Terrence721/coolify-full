import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function SettingsEmail({ settings, canSendTest, testEmailAddress, smtpUpdateUrl, resendUpdateUrl, sendTestUrl }) {
    const smtp = useForm({
        smtp_enabled: settings.smtp_enabled,
        smtp_from_address: settings.smtp_from_address,
        smtp_from_name: settings.smtp_from_name,
        smtp_host: settings.smtp_host,
        smtp_port: settings.smtp_port,
        smtp_encryption: settings.smtp_encryption ?? 'starttls',
        smtp_username: settings.smtp_username,
        smtp_password: settings.smtp_password,
        smtp_timeout: settings.smtp_timeout,
    });

    const resend = useForm({
        resend_enabled: settings.resend_enabled,
        resend_api_key: settings.resend_api_key,
        smtp_from_address: settings.smtp_from_address,
        smtp_from_name: settings.smtp_from_name,
    });

    const testForm = useForm({ test_email_address: testEmailAddress });
    const [testModalOpen, setTestModalOpen] = useState(false);

    function setFromField(field, value) {
        smtp.setData(field, value);
        resend.setData(field, value);
    }

    function submitSmtp(e) {
        e.preventDefault();
        smtp.put(smtpUpdateUrl);
    }

    function submitResend(e) {
        e.preventDefault();
        resend.put(resendUpdateUrl);
    }

    function submitTest(e) {
        e.preventDefault();
        testForm.post(sendTestUrl, { onSuccess: () => setTestModalOpen(false) });
    }

    return (
        <div>
            <div className="pb-5">
                <h1>Settings</h1>
                <div className="subtitle">Instance wide settings for Coolify.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a href="/settings">Configuration</a>
                        <a href="/settings/backup">Backup</a>
                        <a href="/settings/email" className="dark:text-white">
                            Transactional Email
                        </a>
                        <a href="/settings/oauth">OAuth</a>
                        <a href="/settings/scheduled-jobs">Scheduled Jobs</a>
                    </nav>
                </div>
            </div>

            <div className="flex flex-col gap-2 pb-4">
                <div className="flex items-center gap-2">
                    <h2>Transactional Email</h2>
                    {canSendTest && (
                        <button type="button" onClick={() => setTestModalOpen(true)}>
                            Send Test Email
                        </button>
                    )}
                </div>
                {testModalOpen && (
                    <form onSubmit={submitTest} className="flex flex-col w-full gap-2 max-w-sm">
                        <label className="flex flex-col gap-1">
                            Recipient
                            <input
                                id="settings-email-test-recipient"
                                name="settings-email-test-recipient"
                                type="email"
                                placeholder="test@example.com"
                                value={testForm.data.test_email_address}
                                onChange={(e) => testForm.setData('test_email_address', e.target.value)}
                            />
                            {testForm.errors.test_email_address && <span className="text-error">{testForm.errors.test_email_address}</span>}
                        </label>
                        <button type="submit" disabled={testForm.processing}>
                            Send Email
                        </button>
                    </form>
                )}
                <div className="pb-4">Instance wide email settings for password resets, invitations, etc.</div>
                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        From Name
                        <input
                            id="settings-email-from-name"
                            name="settings-email-from-name"
                            value={smtp.data.smtp_from_name ?? ''}
                            onChange={(e) => setFromField('smtp_from_name', e.target.value)}
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        From Address
                        <input
                            id="settings-email-from-address"
                            name="settings-email-from-address"
                            value={smtp.data.smtp_from_address ?? ''}
                            onChange={(e) => setFromField('smtp_from_address', e.target.value)}
                        />
                    </label>
                </div>
            </div>

            <div className="flex flex-col gap-4">
                <form onSubmit={submitSmtp} className="p-4 border dark:border-coolgray-300 border-neutral-200 rounded-lg flex flex-col gap-2">
                    <div className="flex gap-2">
                        <h3>SMTP Server</h3>
                        <button type="submit" disabled={smtp.processing}>
                            Save
                        </button>
                    </div>
                    <div className="w-32">
                        <label className="flex items-center gap-2">
                            <input
                                id="settings-email-smtp-enabled"
                                type="checkbox"
                                checked={smtp.data.smtp_enabled}
                                onChange={(e) => smtp.setData('smtp_enabled', e.target.checked)}
                            />
                            Enabled
                        </label>
                    </div>
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col w-full gap-2 xl:flex-row">
                            <label className="flex flex-col gap-1">
                                Host
                                <input
                                    id="settings-email-smtp-host"
                                    name="settings-email-smtp-host"
                                    placeholder="smtp.mailgun.org"
                                    value={smtp.data.smtp_host ?? ''}
                                    onChange={(e) => smtp.setData('smtp_host', e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Port
                                <input
                                    id="settings-email-smtp-port"
                                    name="settings-email-smtp-port"
                                    type="number"
                                    placeholder="587"
                                    value={smtp.data.smtp_port ?? ''}
                                    onChange={(e) => smtp.setData('smtp_port', e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Encryption
                                <select
                                    id="settings-email-smtp-encryption"
                                    name="settings-email-smtp-encryption"
                                    value={smtp.data.smtp_encryption}
                                    onChange={(e) => smtp.setData('smtp_encryption', e.target.value)}
                                >
                                    <option value="starttls">StartTLS</option>
                                    <option value="tls">TLS/SSL</option>
                                    <option value="none">None</option>
                                </select>
                            </label>
                        </div>
                        <div className="flex flex-col w-full gap-2 xl:flex-row">
                            <label className="flex flex-col gap-1">
                                SMTP Username
                                <input
                                    id="settings-email-smtp-username"
                                    name="settings-email-smtp-username"
                                    value={smtp.data.smtp_username ?? ''}
                                    onChange={(e) => smtp.setData('smtp_username', e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                SMTP Password
                                <input
                                    id="settings-email-smtp-password"
                                    name="settings-email-smtp-password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={smtp.data.smtp_password ?? ''}
                                    onChange={(e) => smtp.setData('smtp_password', e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                Timeout
                                <input
                                    id="settings-email-smtp-timeout"
                                    name="settings-email-smtp-timeout"
                                    type="number"
                                    value={smtp.data.smtp_timeout ?? ''}
                                    onChange={(e) => smtp.setData('smtp_timeout', e.target.value)}
                                />
                            </label>
                        </div>
                    </div>
                    {Object.keys(smtp.errors).length > 0 && (
                        <ul className="text-error text-sm">
                            {Object.values(smtp.errors).map((err) => (
                                <li key={err}>{err}</li>
                            ))}
                        </ul>
                    )}
                </form>

                <form onSubmit={submitResend} className="p-4 border dark:border-coolgray-300 border-neutral-200 rounded-lg flex flex-col gap-2">
                    <div className="flex gap-2">
                        <h3>Resend</h3>
                        <button type="submit" disabled={resend.processing}>
                            Save
                        </button>
                    </div>
                    <div className="w-32">
                        <label className="flex items-center gap-2">
                            <input
                                id="settings-email-resend-enabled"
                                type="checkbox"
                                checked={resend.data.resend_enabled}
                                onChange={(e) => resend.setData('resend_enabled', e.target.checked)}
                            />
                            Enabled
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        API Key
                        <input
                            id="settings-email-resend-api-key"
                            name="settings-email-resend-api-key"
                            type="password"
                            autoComplete="new-password"
                            placeholder="API key"
                            value={resend.data.resend_api_key ?? ''}
                            onChange={(e) => resend.setData('resend_api_key', e.target.value)}
                        />
                    </label>
                    {Object.keys(resend.errors).length > 0 && (
                        <ul className="text-error text-sm">
                            {Object.values(resend.errors).map((err) => (
                                <li key={err}>{err}</li>
                            ))}
                        </ul>
                    )}
                </form>
            </div>
        </div>
    );
}
