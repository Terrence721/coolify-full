import { router, useForm } from '@inertiajs/react';

const EVENT_GROUPS = [
    {
        title: 'Deployments',
        fields: [
            ['deployment_success_discord_notifications', 'Deployment Success'],
            ['deployment_failure_discord_notifications', 'Deployment Failure'],
            ['status_change_discord_notifications', 'Container Status Changes'],
        ],
    },
    {
        title: 'Backups',
        fields: [
            ['backup_success_discord_notifications', 'Backup Success'],
            ['backup_failure_discord_notifications', 'Backup Failure'],
        ],
    },
    {
        title: 'Scheduled Tasks',
        fields: [
            ['scheduled_task_success_discord_notifications', 'Scheduled Task Success'],
            ['scheduled_task_failure_discord_notifications', 'Scheduled Task Failure'],
        ],
    },
    {
        title: 'Server',
        fields: [
            ['docker_cleanup_success_discord_notifications', 'Docker Cleanup Success'],
            ['docker_cleanup_failure_discord_notifications', 'Docker Cleanup Failure'],
            ['server_disk_usage_discord_notifications', 'Server Disk Usage'],
            ['server_reachable_discord_notifications', 'Server Reachable'],
            ['server_unreachable_discord_notifications', 'Server Unreachable'],
            ['server_patch_discord_notifications', 'Server Patching'],
            ['traefik_outdated_discord_notifications', 'Traefik Proxy Outdated'],
        ],
    },
];

export default function Discord({ settings, updateUrl, sendTestUrl }) {
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
                        <a href="/notifications/discord" className="dark:text-white">
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

            <form onSubmit={submit} className="flex flex-col gap-4 pb-4">
                <div className="flex items-center gap-2">
                    <h2>Discord</h2>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                    <button
                        type="button"
                        disabled={!data.discord_enabled}
                        className="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                        onClick={sendTest}
                    >
                        Send Test Notification
                    </button>
                </div>
                <div className="w-48">
                    <label className="flex items-center gap-2">
                        <input
                            id="discord_enabled"
                            type="checkbox"
                            checked={data.discord_enabled}
                            onChange={(e) => setData('discord_enabled', e.target.checked)}
                        />
                        Enabled
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            id="discord_ping_enabled"
                            type="checkbox"
                            checked={data.discord_ping_enabled}
                            onChange={(e) => setData('discord_ping_enabled', e.target.checked)}
                        />
                        Ping Enabled
                    </label>
                    <span className="text-sm text-neutral-500">
                        If enabled, a ping (@here) will be sent to the notification when a critical event happens.
                    </span>
                </div>
                <label className="flex flex-col gap-1">
                    Webhook
                    <input
                        id="discord_webhook_url"
                        name="discord_webhook_url"
                        type="password"
                        value={data.discord_webhook_url ?? ''}
                        onChange={(e) => setData('discord_webhook_url', e.target.value)}
                    />
                    {errors.discord_webhook_url && <span className="text-error">{errors.discord_webhook_url}</span>}
                    <span className="text-sm text-neutral-500">
                        Create a Discord Server and generate a Webhook URL.{' '}
                        <a
                            className="inline-block underline dark:text-white"
                            href="https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Webhook Documentation
                        </a>
                    </span>
                </label>
            </form>

            <h2 className="mt-4">Notification Settings</h2>
            <p className="mb-4">Select events for which you would like to receive Discord notifications.</p>
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
