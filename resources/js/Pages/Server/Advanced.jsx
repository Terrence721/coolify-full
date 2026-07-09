import { useForm } from '@inertiajs/react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function Advanced({
    serverNavbar,
    sidebar,
    concurrentBuilds,
    dynamicTimeout,
    deploymentQueueLimit,
    serverDiskUsageNotificationThreshold,
    serverDiskUsageCheckFrequency,
    updateUrl,
}) {
    const { data, setData, put, processing, errors } = useForm({
        serverDiskUsageCheckFrequency,
        serverDiskUsageNotificationThreshold,
        concurrentBuilds,
        dynamicTimeout,
        deploymentQueueLimit,
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <form onSubmit={submit} className="w-full">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2>Advanced</h2>
                            <button type="submit" disabled={processing}>Save</button>
                        </div>
                        <div className="mb-4">Advanced configuration for your server.</div>
                    </div>

                    <h3>Disk Usage</h3>
                    <div className="flex flex-col gap-6">
                        <div className="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                            <label className="flex flex-col gap-1">
                                Disk usage check frequency
                                <input
                                    placeholder="0 23 * * *"
                                    required
                                    value={data.serverDiskUsageCheckFrequency}
                                    onChange={(e) => setData('serverDiskUsageCheckFrequency', e.target.value)}
                                />
                                {errors.serverDiskUsageCheckFrequency && (
                                    <span className="text-error">{errors.serverDiskUsageCheckFrequency}</span>
                                )}
                            </label>
                            <label className="flex flex-col gap-1">
                                Server disk usage notification threshold (%)
                                <input
                                    type="number"
                                    min="1"
                                    max="99"
                                    required
                                    value={data.serverDiskUsageNotificationThreshold}
                                    onChange={(e) => setData('serverDiskUsageNotificationThreshold', e.target.value)}
                                />
                                {errors.serverDiskUsageNotificationThreshold && (
                                    <span className="text-error">{errors.serverDiskUsageNotificationThreshold}</span>
                                )}
                            </label>
                        </div>

                        <div className="flex flex-col">
                            <h3>Builds</h3>
                            <div className="flex flex-wrap gap-2 sm:flex-nowrap pt-4">
                                <label className="flex flex-col gap-1">
                                    Number of concurrent builds
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={data.concurrentBuilds}
                                        onChange={(e) => setData('concurrentBuilds', e.target.value)}
                                    />
                                    {errors.concurrentBuilds && <span className="text-error">{errors.concurrentBuilds}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    Deployment timeout (seconds)
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={data.dynamicTimeout}
                                        onChange={(e) => setData('dynamicTimeout', e.target.value)}
                                    />
                                    {errors.dynamicTimeout && <span className="text-error">{errors.dynamicTimeout}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    Deployment queue limit
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={data.deploymentQueueLimit}
                                        onChange={(e) => setData('deploymentQueueLimit', e.target.value)}
                                    />
                                    {errors.deploymentQueueLimit && <span className="text-error">{errors.deploymentQueueLimit}</span>}
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}
