import { router, useForm } from '@inertiajs/react';

export default function Updates({ autoUpdateFrequency, updateCheckFrequency, isAutoUpdateEnabled, updateUrl, checkManuallyUrl }) {
    const { data, setData, put, processing, errors } = useForm({
        update_check_frequency: updateCheckFrequency,
        auto_update_frequency: autoUpdateFrequency,
        is_auto_update_enabled: isAutoUpdateEnabled,
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function checkManually() {
        router.post(checkManuallyUrl);
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
                        <a href="/settings/email">Transactional Email</a>
                        <a href="/settings/oauth">OAuth</a>
                        <a href="/settings/scheduled-jobs">Scheduled Jobs</a>
                    </nav>
                </div>
            </div>
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
                    <a className="sub-menu-item" href="/settings">
                        General
                    </a>
                    <a className="sub-menu-item" href="/settings/advanced">
                        Advanced
                    </a>
                    <a className="sub-menu-item menu-item-active" href="/settings/updates">
                        Updates
                    </a>
                </div>
                <form onSubmit={submit} className="flex flex-col w-full">
                    <div className="flex items-center gap-2">
                        <h2>Updates</h2>
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    </div>
                    <div className="pb-4">Your instance's update settings.</div>

                    <div className="flex flex-col gap-2">
                        <div className="flex items-end gap-2">
                            <label className="flex flex-col gap-1">
                                Update Check Frequency
                                <input
                                    id="update_check_frequency"
                                    name="update_check_frequency"
                                    required
                                    placeholder="0 * * * *"
                                    value={data.update_check_frequency}
                                    onChange={(e) => setData('update_check_frequency', e.target.value)}
                                />
                            </label>
                            <button type="button" onClick={checkManually}>
                                Check Manually
                            </button>
                        </div>
                        {errors.update_check_frequency && <span className="text-error">{errors.update_check_frequency}</span>}
                        <div className="text-xs opacity-70">
                            Frequency (cron expression) to check for new Coolify versions and pull new Service Templates from CDN. You can use
                            every_minute, hourly, daily, weekly, monthly, yearly. Default is every hour.
                        </div>

                        <h4 className="pt-4">Auto Update</h4>
                        <div className="text-right md:w-64">
                            <label className="flex items-center gap-2 justify-end">
                                Enabled
                                <input
                                    id="is_auto_update_enabled"
                                    type="checkbox"
                                    checked={data.is_auto_update_enabled}
                                    onChange={(e) => setData('is_auto_update_enabled', e.target.checked)}
                                />
                            </label>
                        </div>
                        {data.is_auto_update_enabled ? (
                            <label className="flex flex-col gap-1">
                                Frequency (cron expression)
                                <input
                                    id="auto_update_frequency"
                                    name="auto_update_frequency"
                                    required
                                    placeholder="0 0 * * *"
                                    value={data.auto_update_frequency}
                                    onChange={(e) => setData('auto_update_frequency', e.target.value)}
                                />
                            </label>
                        ) : (
                            <label className="flex flex-col gap-1">
                                Frequency (cron expression)
                                <input id="auto_update_frequency_disabled" name="auto_update_frequency_disabled" disabled placeholder="disabled" />
                            </label>
                        )}
                        {errors.auto_update_frequency && <span className="text-error">{errors.auto_update_frequency}</span>}
                        <div className="text-xs opacity-70">
                            Frequency (cron expression) to automatically update Coolify. You can use every_minute, hourly, daily, weekly, monthly,
                            yearly. Default is every day at 00:00.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
