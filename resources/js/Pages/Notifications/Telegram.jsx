import { router, useForm } from '@inertiajs/react';

const EVENT_GROUPS = [
    {
        title: 'Deployments',
        fields: [
            ['deployment_success_telegram_notifications', 'telegram_notifications_deployment_success_thread_id', 'Deployment Success'],
            ['deployment_failure_telegram_notifications', 'telegram_notifications_deployment_failure_thread_id', 'Deployment Failure'],
            ['status_change_telegram_notifications', 'telegram_notifications_status_change_thread_id', 'Container Status Changes'],
        ],
    },
    {
        title: 'Backups',
        fields: [
            ['backup_success_telegram_notifications', 'telegram_notifications_backup_success_thread_id', 'Backup Success'],
            ['backup_failure_telegram_notifications', 'telegram_notifications_backup_failure_thread_id', 'Backup Failure'],
        ],
    },
    {
        title: 'Scheduled Tasks',
        fields: [
            ['scheduled_task_success_telegram_notifications', 'telegram_notifications_scheduled_task_success_thread_id', 'Scheduled Task Success'],
            ['scheduled_task_failure_telegram_notifications', 'telegram_notifications_scheduled_task_failure_thread_id', 'Scheduled Task Failure'],
        ],
    },
    {
        title: 'Server',
        fields: [
            ['docker_cleanup_success_telegram_notifications', 'telegram_notifications_docker_cleanup_success_thread_id', 'Docker Cleanup Success'],
            ['docker_cleanup_failure_telegram_notifications', 'telegram_notifications_docker_cleanup_failure_thread_id', 'Docker Cleanup Failure'],
            ['server_disk_usage_telegram_notifications', 'telegram_notifications_server_disk_usage_thread_id', 'Server Disk Usage'],
            ['server_reachable_telegram_notifications', 'telegram_notifications_server_reachable_thread_id', 'Server Reachable'],
            ['server_unreachable_telegram_notifications', 'telegram_notifications_server_unreachable_thread_id', 'Server Unreachable'],
            ['server_patch_telegram_notifications', 'telegram_notifications_server_patch_thread_id', 'Server Patching'],
            ['traefik_outdated_telegram_notifications', 'telegram_notifications_traefik_outdated_thread_id', 'Traefik Proxy Outdated'],
        ],
    },
];

export default function Telegram({ settings, updateUrl, sendTestUrl }) {
    const { data, setData, put, processing } = useForm(settings);

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
                        <a href="/notifications/telegram" className="dark:text-white">
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
                    <h2>Telegram</h2>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                    <button
                        type="button"
                        disabled={!data.telegram_enabled}
                        className="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                        onClick={sendTest}
                    >
                        Send Test Notification
                    </button>
                </div>
                <div className="w-32">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.telegram_enabled}
                            onChange={(e) => setData('telegram_enabled', e.target.checked)}
                        />
                        Enabled
                    </label>
                </div>
                <div className="flex gap-2">
                    <label className="flex flex-col gap-1">
                        Bot API Token
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={data.telegram_token ?? ''}
                            onChange={(e) => setData('telegram_token', e.target.value)}
                        />
                        <span className="text-sm text-neutral-500">
                            Get it from the{' '}
                            <a className="inline-block underline dark:text-white" href="https://t.me/botfather" target="_blank" rel="noreferrer">
                                BotFather Bot
                            </a>{' '}
                            on Telegram.
                        </span>
                    </label>
                    <label className="flex flex-col gap-1">
                        Chat ID
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={data.telegram_chat_id ?? ''}
                            onChange={(e) => setData('telegram_chat_id', e.target.value)}
                        />
                        <span className="text-sm text-neutral-500">Add your bot to a group chat and add its Chat ID here.</span>
                    </label>
                </div>
            </form>

            <h2 className="mt-4">Notification Settings</h2>
            <p className="mb-4">Select events for which you would like to receive Telegram notifications.</p>
            <div className="flex flex-col gap-4">
                {EVENT_GROUPS.map((group) => (
                    <div key={group.title} className="border dark:border-coolgray-300 border-neutral-200 p-4 rounded-lg">
                        <h3 className="text-lg font-medium mb-3">{group.title}</h3>
                        <div className="flex flex-col gap-1.5 pl-1">
                            {group.fields.map(([toggleField, threadField, label]) => (
                                <div key={toggleField} className="pl-1 flex gap-2">
                                    <div className="w-full sm:w-96">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={data[toggleField]}
                                                onChange={(e) => setData(toggleField, e.target.checked)}
                                            />
                                            {label}
                                        </label>
                                    </div>
                                    <input
                                        type="password"
                                        placeholder="Custom Telegram Thread ID"
                                        value={data[threadField] ?? ''}
                                        onChange={(e) => setData(threadField, e.target.value)}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
