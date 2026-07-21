import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ActivityLog from '../../Components/ActivityLog';
import DomainConflictModal from '../../Components/DomainConflictModal';

export default function Index({ settings, timezones, isDev, hasServer, defaultHelperVersion, updateUrl, buildHelperImageUrl }) {
    const { props } = usePage();
    const { data, setData, put, processing, errors } = useForm({ ...settings });
    const [tzOpen, setTzOpen] = useState(false);
    const [tzSearch, setTzSearch] = useState(settings.instance_timezone ?? '');
    const [showConflictModal, setShowConflictModal] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [activityId, setActivityId] = useState(null);

    useEffect(() => {
        if (props.flash?.domainConflicts?.length) {
            setShowConflictModal(true);
        }
    }, [props.flash?.domainConflicts]);

    useEffect(() => {
        if (props.flash?.activityContext === 'settings-helper-image' && props.flash?.activityId) {
            setActivityId(props.flash.activityId);
        }
         
    }, [props.flash?.activityId, props.flash?.activityContext]);

    function submit(e) {
        e.preventDefault();
        put(updateUrl, { preserveScroll: true });
    }

    function confirmDomainUsage() {
        setConfirming(true);
        router.put(
            updateUrl,
            { ...data, force_save_domains: true },
            {
                preserveScroll: true,
                onFinish: () => {
                    setConfirming(false);
                    setShowConflictModal(false);
                },
            },
        );
    }

    function selectTimezone(tz) {
        setTzSearch(tz);
        setTzOpen(false);
        setData('instance_timezone', tz);
        router.put(updateUrl, { ...data, instance_timezone: tz }, { preserveScroll: true });
    }

    function buildHelperImage(e) {
        e.preventDefault();
        router.post(buildHelperImageUrl, { dev_helper_version: data.dev_helper_version }, { preserveScroll: true });
    }

    const filteredTimezones = timezones.filter((tz) => tz.toLowerCase().includes(tzSearch.toLowerCase()));

    return (
        <div>
            <div className="pb-5">
                <h1>Settings</h1>
                <div className="subtitle">Instance wide settings for Coolify.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10 whitespace-nowrap">
                        <a className="dark:text-white" href="/settings">
                            Configuration
                        </a>
                        <a href="/settings/backup">Backup</a>
                        <a href="/settings/email">Transactional Email</a>
                        <a href="/settings/oauth">OAuth</a>
                        <a href="/settings/scheduled-jobs">Scheduled Jobs</a>
                    </nav>
                </div>
            </div>

            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <div className="sub-menu-wrapper">
                    <a className="sub-menu-item menu-item-active" href="/settings">
                        General
                    </a>
                    <a className="sub-menu-item" href="/settings/advanced">
                        Advanced
                    </a>
                    <a className="sub-menu-item" href="/settings/updates">
                        Updates
                    </a>
                </div>

                <form onSubmit={submit} className="flex flex-col w-full max-w-3xl gap-2">
                    <div className="flex items-center gap-2">
                        <h2>General</h2>
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    </div>
                    <div className="pb-4">General configuration for your Coolify instance.</div>

                    <div className="flex flex-col gap-4">
                        <div className="flex gap-2 md:flex-row flex-col">
                            <label className="flex flex-col gap-1 w-full">
                                URL
                                <input
                                    id="settings-fqdn"
                                    name="settings-fqdn"
                                    value={data.fqdn ?? ''}
                                    onChange={(e) => setData('fqdn', e.target.value)}
                                    placeholder="https://coolify.yourdomain.com"
                                />
                                <span className="text-xs opacity-70">
                                    Enter the full URL of the instance (for example, https://dashboard.example.com). Important: if you want the
                                    dashboard to be accessible over HTTPS, you must include https:// at the start of the URL — without it, the
                                    dashboard will use HTTP and won't be secured.
                                </span>
                                {errors.fqdn && <span className="text-error">{errors.fqdn}</span>}
                            </label>
                            <label className="flex flex-col gap-1 w-full">
                                Name
                                <input
                                    id="settings-instance-name"
                                    name="settings-instance-name"
                                    value={data.instance_name ?? ''}
                                    onChange={(e) => setData('instance_name', e.target.value)}
                                    placeholder="Coolify"
                                />
                                <span className="text-xs opacity-70">Custom name for your Coolify instance, shown in the URL.</span>
                            </label>
                            <div className="relative w-full">
                                <label className="flex flex-col gap-1" htmlFor="instance_timezone">
                                    Instance Timezone
                                    <input
                                        id="instance_timezone"
                                        autoComplete="off"
                                        value={tzSearch}
                                        onChange={(e) => {
                                            setTzSearch(e.target.value);
                                            setTzOpen(true);
                                        }}
                                        onFocus={() => setTzOpen(true)}
                                        onBlur={() => setTimeout(() => setTzOpen(false), 150)}
                                        placeholder={data.instance_timezone ? 'Search timezone...' : 'Select Server Timezone'}
                                    />
                                </label>
                                <span className="text-xs opacity-70">
                                    Timezone for the Coolify instance, used for the update check and automatic update frequency.
                                </span>
                                {errors.instance_timezone && <span className="text-error">{errors.instance_timezone}</span>}
                                {tzOpen && (
                                    <div className="absolute z-50 mt-1 w-full max-h-60 overflow-auto overflow-x-hidden rounded-md border bg-white shadow-lg dark:border-coolgray-200 dark:bg-coolgray-100">
                                        {filteredTimezones.map((tz) => (
                                            <div
                                                key={tz}
                                                onMouseDown={() => selectTimezone(tz)}
                                                className="cursor-pointer px-4 py-2 text-gray-800 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-coolgray-300"
                                            >
                                                {tz}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex gap-2 md:flex-row flex-col">
                            <label className="flex flex-col gap-1 w-full">
                                Instance's Public IPv4
                                <input
                                    id="settings-public-ipv4"
                                    name="settings-public-ipv4"
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.public_ipv4 ?? ''}
                                    onChange={(e) => setData('public_ipv4', e.target.value)}
                                    placeholder="1.2.3.4"
                                />
                                <span className="text-xs opacity-70">
                                    Useful if you have several IPv4 addresses and Coolify could not detect the correct one.
                                </span>
                                {errors.public_ipv4 && <span className="text-error">{errors.public_ipv4}</span>}
                            </label>
                            <label className="flex flex-col gap-1 w-full">
                                Instance's Public IPv6
                                <input
                                    id="settings-public-ipv6"
                                    name="settings-public-ipv6"
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.public_ipv6 ?? ''}
                                    onChange={(e) => setData('public_ipv6', e.target.value)}
                                    placeholder="2001:db8::1"
                                />
                                <span className="text-xs opacity-70">
                                    Useful if you have several IPv6 addresses and Coolify could not detect the correct one.
                                </span>
                                {errors.public_ipv6 && <span className="text-error">{errors.public_ipv6}</span>}
                            </label>
                        </div>

                        {activityId && (
                            <div className="w-full mt-4">
                                <ActivityLog activityId={activityId} header="Building Helper Image" />
                            </div>
                        )}

                        {isDev && (
                            <div className="flex gap-2 items-end md:flex-row flex-col">
                                <label className="flex flex-col gap-1 w-full">
                                    Dev Helper Version (Development Only)
                                    <input
                                        id="settings-dev-helper-version"
                                        name="settings-dev-helper-version"
                                        value={data.dev_helper_version ?? ''}
                                        onChange={(e) => setData('dev_helper_version', e.target.value)}
                                        placeholder={defaultHelperVersion}
                                    />
                                    <span className="text-xs opacity-70">
                                        Override the default coolify-helper image version. Leave empty to use the default ({defaultHelperVersion}).
                                        Examples: 1.0.11, latest, dev
                                    </span>
                                    {errors.dev_helper_version && <span className="text-error">{errors.dev_helper_version}</span>}
                                </label>
                                {hasServer && (
                                    <button type="button" onClick={buildHelperImage}>
                                        Build Helper Image
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                </form>
            </div>

            {showConflictModal && (
                <DomainConflictModal
                    conflicts={props.flash?.domainConflicts ?? []}
                    confirming={confirming}
                    onCancel={() => setShowConflictModal(false)}
                    onConfirm={confirmDomainUsage}
                />
            )}
        </div>
    );
}
