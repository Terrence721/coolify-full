import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const EVENT_GROUPS = [
    {
        title: 'Deployments',
        fields: [
            ['deployment_success_email_notifications', 'Deployment Success'],
            ['deployment_failure_email_notifications', 'Deployment Failure'],
            ['status_change_email_notifications', 'Container Status Changes'],
        ],
    },
    {
        title: 'Backups',
        fields: [
            ['backup_success_email_notifications', 'Backup Success'],
            ['backup_failure_email_notifications', 'Backup Failure'],
        ],
    },
    {
        title: 'Scheduled Tasks',
        fields: [
            ['scheduled_task_success_email_notifications', 'Scheduled Task Success'],
            ['scheduled_task_failure_email_notifications', 'Scheduled Task Failure'],
        ],
    },
    {
        title: 'Server',
        fields: [
            ['docker_cleanup_success_email_notifications', 'Docker Cleanup Success'],
            ['docker_cleanup_failure_email_notifications', 'Docker Cleanup Failure'],
            ['server_disk_usage_email_notifications', 'Server Disk Usage'],
            ['server_reachable_email_notifications', 'Server Reachable'],
            ['server_unreachable_email_notifications', 'Server Unreachable'],
            ['server_patch_email_notifications', 'Server Patching'],
            ['traefik_outdated_email_notifications', 'Traefik Proxy Outdated'],
        ],
    },
];

export default function Email({
    settings,
    isCloud,
    isInstanceAdmin,
    canSendTest,
    testEmailAddress,
    updateUrl,
    smtpUpdateUrl,
    resendUpdateUrl,
    sendTestUrl,
    copyFromInstanceUrl,
}) {
    const main = useForm({
        use_instance_email_settings: settings.use_instance_email_settings,
        smtp_from_name: settings.smtp_from_name,
        smtp_from_address: settings.smtp_from_address,
        ...Object.fromEntries(EVENT_GROUPS.flatMap((g) => g.fields).map(([field]) => [field, settings[field]])),
    });

    const smtp = useForm({
        smtp_enabled: settings.smtp_enabled,
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
    });

    const testForm = useForm({ test_email_address: testEmailAddress });
    const [testModalOpen, setTestModalOpen] = useState(false);

    function submitMain(e) {
        e.preventDefault();
        main.put(updateUrl);
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

    function copyFromInstance(e) {
        e.preventDefault();
        router.post(copyFromInstanceUrl);
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Notifications</h1>
                <div className="subtitle">Get notified about your infrastructure.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a href="/notifications/email" className="dark:text-white">
                            <button>Email</button>
                        </a>
                        <a href="/notifications/discord">
                            <button>Discord</button>
                        </a>
                        <a href="/notifications/telegram">
                            <button>Telegram</button>
                        </a>
                        <a href="/notifications/slack">
                            <button>Slack</button>
                        </a>
                        <a href="/notifications/pushover">
                            <button>Pushover</button>
                        </a>
                        <a href="/notifications/webhook">
                            <button>Webhook</button>
                        </a>
                    </nav>
                </div>
            </div>

            <form onSubmit={submitMain} className="flex flex-col gap-4 pb-4">
                <div className="flex items-center gap-2">
                    <h2>Email</h2>
                    <button type="submit" disabled={main.processing}>
                        Save
                    </button>
                    {canSendTest && (
                        <button type="button" className="normal-case dark:text-white btn btn-xs no-animation btn-primary" onClick={() => setTestModalOpen(true)}>
                            Send Test Email
                        </button>
                    )}
                </div>

                {testModalOpen && (
                    <form onSubmit={submitTest} className="flex flex-col w-full gap-2 max-w-sm">
                        <label className="flex flex-col gap-1">
                            Recipient
                            <input
                                id="email-test-recipient"
                                name="email-test-recipient"
                                type="email"
                                placeholder="test@example.com"
                                value={testForm.data.test_email_address}
                                onChange={(e) => testForm.setData('test_email_address', e.target.value)}
                            />
                            {testForm.errors.test_email_address && (
                                <span className="text-error">{testForm.errors.test_email_address}</span>
                            )}
                        </label>
                        <button type="submit" disabled={testForm.processing}>
                            Send Email
                        </button>
                    </form>
                )}

                {!isCloud && (
                    <div className="w-full sm:w-96">
                        <label className="flex items-center gap-2">
                            <input
                                id="email-use-instance-settings"
                                type="checkbox"
                                checked={main.data.use_instance_email_settings}
                                onChange={(e) => {
                                    // Auto-saves immediately, matching the original Livewire component's
                                    // instantSave() on this exact field. Without this, toggling it off only
                                    // updates client state - the SMTP/Resend blocks below appear and can be
                                    // filled in and saved successfully, but a page reload reverts the toggle
                                    // to whatever's still persisted (true), re-hiding the block even though
                                    // the SMTP/Resend data itself did save. Found via manual smoke-test QA.
                                    main.setData('use_instance_email_settings', e.target.checked);
                                    main.put(updateUrl);
                                }}
                            />
                            Use system wide (transactional) email settings
                        </label>
                    </div>
                )}

                {!main.data.use_instance_email_settings && (
                    <>
                        <div className="flex gap-2">
                            <label className="flex flex-col gap-1">
                                From Name
                                <input
                                    id="email-smtp-from-name"
                                    name="email-smtp-from-name"
                                    value={main.data.smtp_from_name ?? ''}
                                    onChange={(e) => main.setData('smtp_from_name', e.target.value)}
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                From Address
                                <input
                                    id="email-smtp-from-address"
                                    name="email-smtp-from-address"
                                    value={main.data.smtp_from_address ?? ''}
                                    onChange={(e) => main.setData('smtp_from_address', e.target.value)}
                                />
                            </label>
                        </div>
                        {isInstanceAdmin && (
                            <button type="button" onClick={copyFromInstance}>
                                Copy from Instance Settings
                            </button>
                        )}
                    </>
                )}
            </form>

            {isCloud && (
                <div className="w-64 py-4">
                    <label className="flex items-center gap-2">
                        <input
                            id="email-use-instance-settings"
                            type="checkbox"
                            checked={main.data.use_instance_email_settings}
                            onChange={(e) => {
                                main.setData('use_instance_email_settings', e.target.checked);
                                main.put(updateUrl);
                            }}
                        />
                        Use Hosted Email Service
                    </label>
                </div>
            )}

            {!main.data.use_instance_email_settings && (
                <div className="flex flex-col gap-4">
                    <form onSubmit={submitSmtp} className="p-4 border dark:border-coolgray-300 border-neutral-200 rounded-lg flex flex-col gap-2">
                        <div className="flex items-center gap-2">
                            <h3>SMTP Server</h3>
                            <button type="submit" disabled={smtp.processing}>
                                Save
                            </button>
                        </div>
                        <div className="w-32">
                            <label className="flex items-center gap-2">
                                <input
                                    id="email-smtp-enabled"
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
                                        id="email-smtp-host"
                                        name="email-smtp-host"
                                        placeholder="smtp.mailgun.org"
                                        value={smtp.data.smtp_host ?? ''}
                                        onChange={(e) => smtp.setData('smtp_host', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    Port
                                    <input
                                        id="email-smtp-port"
                                        name="email-smtp-port"
                                        type="number"
                                        placeholder="587"
                                        value={smtp.data.smtp_port ?? ''}
                                        onChange={(e) => smtp.setData('smtp_port', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    Encryption
                                    <select
                                        id="email-smtp-encryption"
                                        name="email-smtp-encryption"
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
                                        id="email-smtp-username"
                                        name="email-smtp-username"
                                        value={smtp.data.smtp_username ?? ''}
                                        onChange={(e) => smtp.setData('smtp_username', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    SMTP Password
                                    <input
                                        id="email-smtp-password"
                                        name="email-smtp-password"
                                        type="password"
                                        value={smtp.data.smtp_password ?? ''}
                                        onChange={(e) => smtp.setData('smtp_password', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    Timeout
                                    <input
                                        id="email-smtp-timeout"
                                        name="email-smtp-timeout"
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
                        <div className="flex items-center gap-2">
                            <h3>Resend</h3>
                            <button type="submit" disabled={resend.processing}>
                                Save
                            </button>
                        </div>
                        <div className="w-32">
                            <label className="flex items-center gap-2">
                                <input
                                    id="email-resend-enabled"
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
                                id="email-resend-api-key"
                                name="email-resend-api-key"
                                type="password"
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
            )}

            <h2 className="mt-4">Notification Settings</h2>
            <p className="mb-4">Select events for which you would like to receive email notifications.</p>
            <div className="flex flex-col gap-4 max-w-2xl">
                {EVENT_GROUPS.map((group) => (
                    <div key={group.title} className="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
                        <h3 className="font-medium mb-3">{group.title}</h3>
                        <div className="flex flex-col gap-1.5 pl-1">
                            {group.fields.map(([field, label]) => (
                                <label key={field} className="flex items-center gap-2">
                                    <input
                                        id={field}
                                        type="checkbox"
                                        checked={main.data[field]}
                                        onChange={(e) => {
                                            main.setData(field, e.target.checked);
                                            main.put(updateUrl);
                                        }}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
