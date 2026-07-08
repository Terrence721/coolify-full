import { router, useForm } from '@inertiajs/react';

const EVENT_GROUPS = [
    {
        title: 'Deployments',
        fields: [
            ['deployment_success_webhook_notifications', 'Deployment Success'],
            ['deployment_failure_webhook_notifications', 'Deployment Failure'],
            ['status_change_webhook_notifications', 'Container Status Changes'],
        ],
    },
    {
        title: 'Backups',
        fields: [
            ['backup_success_webhook_notifications', 'Backup Success'],
            ['backup_failure_webhook_notifications', 'Backup Failure'],
        ],
    },
    {
        title: 'Scheduled Tasks',
        fields: [
            ['scheduled_task_success_webhook_notifications', 'Scheduled Task Success'],
            ['scheduled_task_failure_webhook_notifications', 'Scheduled Task Failure'],
        ],
    },
    {
        title: 'Server',
        fields: [
            ['docker_cleanup_success_webhook_notifications', 'Docker Cleanup Success'],
            ['docker_cleanup_failure_webhook_notifications', 'Docker Cleanup Failure'],
            ['server_disk_usage_webhook_notifications', 'Server Disk Usage'],
            ['server_reachable_webhook_notifications', 'Server Reachable'],
            ['server_unreachable_webhook_notifications', 'Server Unreachable'],
            ['server_patch_webhook_notifications', 'Server Patching'],
            ['traefik_outdated_webhook_notifications', 'Traefik Proxy Outdated'],
        ],
    },
];

export default function Webhook({ settings, updateUrl, sendTestUrl }) {
    const { data, setData, put, processing, errors } = useForm(settings);

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function sendTest(e) {
        e.preventDefault();
        router.post(sendTestUrl);
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Notifications</h1>
                <div className="subtitle">Get notified about your infrastructure.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a href="/notifications/email">
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
                        <a href="/notifications/webhook" className="dark:text-white">
                            <button>Webhook</button>
                        </a>
                    </nav>
                </div>
            </div>

            <form onSubmit={submit} className="flex flex-col gap-4 pb-4">
                <div className="flex items-center gap-2">
                    <h2>Webhook</h2>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                    <button
                        type="button"
                        disabled={!data.webhook_enabled}
                        className="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                        onClick={sendTest}
                    >
                        Send Test Notification
                    </button>
                </div>
                <div className="w-48">
                    <label>
                        <input
                            type="checkbox"
                            checked={data.webhook_enabled}
                            onChange={(e) => setData('webhook_enabled', e.target.checked)}
                        />
                        Enabled
                    </label>
                </div>
                <div className="flex items-end gap-2">
                    <label className="flex flex-col gap-1">
                        Webhook URL (POST)
                        <input
                            type="password"
                            value={data.webhook_url ?? ''}
                            onChange={(e) => setData('webhook_url', e.target.value)}
                        />
                        {errors.webhook_url && <span className="text-error">{errors.webhook_url}</span>}
                        <span className="text-sm text-neutral-500">
                            Enter a valid HTTP or HTTPS URL. Coolify will send POST requests to this endpoint when events occur.
                        </span>
                    </label>
                </div>
            </form>

            <h2 className="mt-4">Notification Settings</h2>
            <p className="mb-4">Select events for which you would like to receive webhook notifications.</p>
            <div className="flex flex-col gap-4 max-w-2xl">
                {EVENT_GROUPS.map((group) => (
                    <div key={group.title} className="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
                        <h3 className="font-medium mb-3">{group.title}</h3>
                        <div className="flex flex-col gap-1.5 pl-1">
                            {group.fields.map(([field, label]) => (
                                <label key={field} className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={data[field]}
                                        onChange={(e) => setData(field, e.target.checked)}
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
