import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ActivityLog from '../../../Components/ActivityLog';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export default function Patches({
    serverNavbar,
    sidebar,
    isDev,
    checkUpdatesUrl,
    updateAllUrl,
    updatePackageUrl,
    notifyUpdatedUrl,
    sendTestEmailUrl,
}) {
    const { props } = usePage();
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState(null);
    const [totalUpdates, setTotalUpdates] = useState(null);
    const [updates, setUpdates] = useState(null);
    const [osId, setOsId] = useState(null);
    const [packageManager, setPackageManager] = useState(null);
    const [showLogs, setShowLogs] = useState(false);
    const [activityId, setActivityId] = useState(null);

    useEffect(() => {
        if (props.flash?.activityContext === 'patches-update' && props.flash?.activityId) {
            setActivityId(props.flash.activityId);
            setShowLogs(true);
        }
    }, [props.flash?.activityId, props.flash?.activityContext]);

    async function checkForUpdates() {
        setChecking(true);
        setError(null);
        try {
            const response = await fetch(checkUpdatesUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await response.json();
            if (data.error) {
                setError(data.error);
                setTotalUpdates(null);
                setUpdates(null);
            } else {
                setTotalUpdates(data.totalUpdates);
                setUpdates(data.updates);
                setOsId(data.osId);
                setPackageManager(data.packageManager);
            }
        } catch {
            setError('Something went wrong.');
        } finally {
            setChecking(false);
        }
    }

    function updateAllPackages() {
        const confirmation = window.prompt(
            'All packages will be updated to the latest version. This action could restart your currently running containers if docker will be updated. Type "Update All Packages" to confirm:',
        );
        if (confirmation !== 'Update All Packages') return;
        router.post(updateAllUrl, { packageManager, osId }, { preserveScroll: true });
    }

    function updatePackage(pkg) {
        router.post(updatePackageUrl, { package: pkg, packageManager, osId }, { preserveScroll: true });
    }

    function onUpdateFinished() {
        router.post(notifyUpdatedUrl, {}, { preserveScroll: true });
    }

    function sendTestEmail() {
        router.post(sendTestEmailUrl, {}, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />

            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <h3 className="text-2xl font-bold">Updating Packages</h3>
                            <button type="button" onClick={() => setShowLogs(false)}>
                                ✕
                            </button>
                        </div>
                        <div className="flex-1 min-h-0 overflow-hidden p-6">
                            <ActivityLog activityId={activityId} header="Logs" fullHeight onFinished={onUpdateFinished} />
                        </div>
                    </div>
                </div>
            )}

            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex items-center gap-2 flex-row">
                        <h2>Server Patching</h2>
                        <span className="text-xs text-neutral-500">(experimental)</span>
                        <span
                            className="cursor-help text-xs text-neutral-500"
                            title="Only available for apt, dnf and zypper package managers atm, more coming soon. Status notifications sent every week. You can disable notifications in the notification settings."
                        >
                            (?)
                        </span>
                        {isDev && (
                            <button type="button" onClick={sendTestEmail}>
                                Send Test Email (dev only)
                            </button>
                        )}
                    </div>
                    <div>Update your servers semi-automatically.</div>

                    <div className="flex flex-col gap-6 pt-4">
                        <div>
                            <button type="button" onClick={checkForUpdates} disabled={checking}>
                                Check for Updates
                            </button>
                        </div>
                        <div className="flex flex-col">
                            {checking && <div className="pb-2">Checking for updates. It may take a few minutes.</div>}
                            {error && <div className="text-red-500">{error}</div>}
                            {!error && totalUpdates === 0 && <div className="text-green-500">Your server is up to date.</div>}
                            {!error && updates && updates.length > 0 && (
                                <>
                                    <div className="pb-2">
                                        <button type="button" onClick={updateAllPackages}>
                                            Update All Packages
                                        </button>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full">
                                            <thead>
                                                <tr>
                                                    <th>Package</th>
                                                    <th>Version</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {updates.map((update) => (
                                                    <tr key={update.package}>
                                                        <td>
                                                            <span className="break-all">{update.package}</span>
                                                        </td>
                                                        <td className="whitespace-nowrap">
                                                            <span>{update.new_version}</span>
                                                            {packageManager !== 'dnf' && update.current_version && (
                                                                <span
                                                                    className="text-xs text-neutral-500 pl-1"
                                                                    title={`Current: ${update.current_version}`}
                                                                >
                                                                    (current: {update.current_version})
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="whitespace-nowrap">
                                                            <button type="button" onClick={() => updatePackage(update.package)}>
                                                                Update
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
