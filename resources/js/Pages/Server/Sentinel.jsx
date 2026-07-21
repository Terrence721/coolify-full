import { router } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function Sentinel({
    serverNavbar,
    sidebar,
    canUpdate,
    isDev,
    isSentinelEnabled: initialIsSentinelEnabled,
    isSentinelLive,
    isSentinelDebugEnabled: initialIsSentinelDebugEnabled,
    sentinelToken: initialSentinelToken,
    sentinelCustomUrl: initialSentinelCustomUrl,
    sentinelMetricsRefreshRateSeconds: initialSentinelMetricsRefreshRateSeconds,
    sentinelMetricsHistoryDays: initialSentinelMetricsHistoryDays,
    sentinelPushIntervalSeconds: initialSentinelPushIntervalSeconds,
    submitUrl,
    toggleUrl,
    restartUrl,
    regenerateTokenUrl,
}) {
    const [isSentinelEnabled, setIsSentinelEnabled] = useState(initialIsSentinelEnabled);
    const [isSentinelDebugEnabled, setIsSentinelDebugEnabled] = useState(initialIsSentinelDebugEnabled);
    const [sentinelToken, setSentinelToken] = useState(initialSentinelToken ?? '');
    const [sentinelCustomUrl, setSentinelCustomUrl] = useState(initialSentinelCustomUrl ?? '');
    const [sentinelMetricsRefreshRateSeconds, setSentinelMetricsRefreshRateSeconds] = useState(initialSentinelMetricsRefreshRateSeconds);
    const [sentinelMetricsHistoryDays, setSentinelMetricsHistoryDays] = useState(initialSentinelMetricsHistoryDays);
    const [sentinelPushIntervalSeconds, setSentinelPushIntervalSeconds] = useState(initialSentinelPushIntervalSeconds);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    function toggleSentinel() {
        router.post(
            toggleUrl,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setIsSentinelEnabled((current) => !current),
            },
        );
    }

    function restartSentinel() {
        router.post(restartUrl, {}, { preserveScroll: true });
    }

    function regenerateToken() {
        router.post(regenerateTokenUrl, {}, { preserveScroll: true });
    }

    function toggleDebug(e) {
        const value = e.target.checked;
        setIsSentinelDebugEnabled(value);
        router.post(submitUrl, { isSentinelDebugEnabled: value }, { preserveScroll: true });
    }

    function submit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        router.post(
            submitUrl,
            {
                sentinelToken,
                sentinelCustomUrl,
                sentinelMetricsRefreshRateSeconds,
                sentinelMetricsHistoryDays,
                sentinelPushIntervalSeconds,
                isSentinelDebugEnabled,
            },
            {
                preserveScroll: true,
                onError: (validationErrors) => setErrors(validationErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <form onSubmit={submit}>
                        <div className="flex gap-2 items-center pb-2">
                            <h2>Sentinel</h2>
                            {!isSentinelEnabled ? (
                                canUpdate && (
                                    <button type="button" onClick={toggleSentinel}>
                                        Enable Sentinel
                                    </button>
                                )
                            ) : (
                                <div className="flex gap-2 items-center">
                                    {canUpdate && (
                                        <button type="submit" disabled={submitting}>
                                            Save
                                        </button>
                                    )}
                                    {canUpdate && (
                                        <button type="button" onClick={restartSentinel}>
                                            {isSentinelLive ? 'Restart' : 'Sync'}
                                        </button>
                                    )}
                                    {canUpdate && (
                                        <button type="button" onClick={toggleSentinel}>
                                            Disable Sentinel
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        {isSentinelEnabled && !isSentinelLive && (
                            <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded mt-2">
                                <div className="font-bold">Out of Sync</div>
                                Sentinel is not in sync with your server. Click "Sync" to re-sync.
                            </div>
                        )}

                        <div className="flex flex-col gap-2 pt-2">
                            {isSentinelEnabled && isDev && (
                                <div className="w-full sm:w-96">
                                    <label className="flex items-center gap-2">
                                        <input
                                            id="sentinel-debug-enabled"
                                            type="checkbox"
                                            disabled={!canUpdate}
                                            checked={isSentinelDebugEnabled}
                                            onChange={toggleDebug}
                                        />
                                        Enable Sentinel (with debug)
                                    </label>
                                </div>
                            )}

                            {isSentinelEnabled && (
                                <>
                                    <div className="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                                        <label className="flex flex-col gap-1 w-full">
                                            Coolify URL
                                            <input
                                                id="sentinel-custom-url"
                                                name="sentinel-custom-url"
                                                disabled={!canUpdate}
                                                required
                                                value={sentinelCustomUrl}
                                                onChange={(e) => setSentinelCustomUrl(e.target.value)}
                                            />
                                            {errors.sentinelCustomUrl && <span className="text-error">{errors.sentinelCustomUrl}</span>}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Sentinel token
                                            <input
                                                id="sentinel-token"
                                                name="sentinel-token"
                                                type="password"
                                                disabled={!canUpdate}
                                                required
                                                value={sentinelToken}
                                                onChange={(e) => setSentinelToken(e.target.value)}
                                            />
                                            {errors.sentinelToken && <span className="text-error">{errors.sentinelToken}</span>}
                                        </label>
                                        {canUpdate && (
                                            <button type="button" onClick={regenerateToken}>
                                                Regenerate
                                            </button>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap gap-2 sm:flex-nowrap">
                                        <label className="flex flex-col gap-1 w-full">
                                            Metrics rate (seconds)
                                            <input
                                                id="sentinel-metrics-refresh-rate-seconds"
                                                name="sentinel-metrics-refresh-rate-seconds"
                                                type="number"
                                                min="1"
                                                disabled={!canUpdate}
                                                required
                                                value={sentinelMetricsRefreshRateSeconds}
                                                onChange={(e) => setSentinelMetricsRefreshRateSeconds(e.target.value)}
                                            />
                                            {errors.sentinelMetricsRefreshRateSeconds && (
                                                <span className="text-error">{errors.sentinelMetricsRefreshRateSeconds}</span>
                                            )}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Metrics history (days)
                                            <input
                                                id="sentinel-metrics-history-days"
                                                name="sentinel-metrics-history-days"
                                                type="number"
                                                min="1"
                                                disabled={!canUpdate}
                                                required
                                                value={sentinelMetricsHistoryDays}
                                                onChange={(e) => setSentinelMetricsHistoryDays(e.target.value)}
                                            />
                                            {errors.sentinelMetricsHistoryDays && (
                                                <span className="text-error">{errors.sentinelMetricsHistoryDays}</span>
                                            )}
                                        </label>
                                        <label className="flex flex-col gap-1 w-full">
                                            Push interval (seconds)
                                            <input
                                                id="sentinel-push-interval-seconds"
                                                name="sentinel-push-interval-seconds"
                                                type="number"
                                                min="10"
                                                disabled={!canUpdate}
                                                required
                                                value={sentinelPushIntervalSeconds}
                                                onChange={(e) => setSentinelPushIntervalSeconds(e.target.value)}
                                            />
                                            {errors.sentinelPushIntervalSeconds && (
                                                <span className="text-error">{errors.sentinelPushIntervalSeconds}</span>
                                            )}
                                        </label>
                                    </div>
                                </>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
