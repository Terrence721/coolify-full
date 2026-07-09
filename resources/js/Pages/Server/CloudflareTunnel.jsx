import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ActivityLog from '../../Components/ActivityLog';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function CloudflareTunnel({
    serverNavbar,
    sidebar,
    isCloudflareTunnelsEnabled,
    isFunctional,
    hasPreviousIp,
    canUpdate,
    toggleUrl,
    manualConfigUrl,
    automatedConfigUrl,
}) {
    const { props } = usePage();
    const [showLogs, setShowLogs] = useState(false);
    const [activityId, setActivityId] = useState(null);
    const { data, setData, post, processing, errors } = useForm({ cloudflare_token: '', ssh_domain: '' });

    useEffect(() => {
        if (props.flash?.activityContext === 'cloudflare-tunnel' && props.flash?.activityId) {
            setActivityId(props.flash.activityId);
            setShowLogs(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.flash?.activityId, props.flash?.activityContext]);

    function disableTunnel() {
        const confirmation = window.prompt(
            'Cloudflare Tunnel will be disabled for this server. Type "DISABLE CLOUDFLARE TUNNEL" to confirm:',
        );
        if (confirmation !== 'DISABLE CLOUDFLARE TUNNEL') return;
        router.post(toggleUrl, {}, { preserveScroll: true });
    }

    function manualConfig() {
        const confirmation = window.prompt(
            'You set everything up manually, including in Cloudflare and on the server (cloudflared is running). Type "I manually configured Cloudflare Tunnel" to confirm:',
        );
        if (confirmation !== 'I manually configured Cloudflare Tunnel') return;
        router.post(manualConfigUrl, {}, { preserveScroll: true });
    }

    function submitAutomatedConfig(e) {
        e.preventDefault();
        post(automatedConfigUrl, { preserveScroll: true });
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />

            {showLogs && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={() => setShowLogs(false)} />
                    <div className="relative flex h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                            <h3 className="text-2xl font-bold">Cloudflare Tunnel Configuration</h3>
                            <button type="button" onClick={() => setShowLogs(false)}>✕</button>
                        </div>
                        <div className="flex-1 min-h-0 overflow-hidden p-6">
                            <ActivityLog activityId={activityId} header="Logs" fullHeight />
                        </div>
                    </div>
                </div>
            )}

            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <div className="flex flex-col">
                        <div className="flex gap-2 items-center">
                            <h2>Cloudflare Tunnel</h2>
                            <span
                                className="cursor-help text-xs text-neutral-500"
                                title="If you are using Cloudflare Tunnel, enable this. It will proxy all SSH requests to your server through Cloudflare. You then can close your server's SSH port in the firewall of your hosting provider. If you choose manual configuration, Coolify does not install or set up Cloudflare (cloudflared) on your server."
                            >
                                (?)
                            </span>
                            {isCloudflareTunnelsEnabled && (
                                <span className="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                                    Enabled
                                </span>
                            )}
                        </div>
                        <div>Secure your servers with Cloudflare Tunnel.</div>
                    </div>

                    <div className="flex flex-col gap-2 pt-6">
                        {isCloudflareTunnelsEnabled ? (
                            <div className="flex flex-col gap-4">
                                <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded">
                                    Warning! If you disable Cloudflare Tunnel, you will need to update the server's IP
                                    address back to its real IP address in the server "General" settings. The server may
                                    become inaccessible if the IP address is not updated correctly.
                                </div>
                                <div className="w-64">
                                    <button type="button" className="text-error" onClick={disableTunnel}>
                                        Disable Cloudflare Tunnel
                                    </button>
                                </div>
                            </div>
                        ) : (
                            !isFunctional && (
                                <div className="p-3 border border-sky-500/30 bg-sky-500/10 text-sm rounded mb-4">
                                    To <strong>automatically</strong> configure Cloudflare Tunnel, please validate your
                                    server first. Then you will need a Cloudflare token and an SSH domain configured.
                                    <br />
                                    To <strong>manually</strong> configure Cloudflare Tunnel, please click below, then
                                    you should validate the server.
                                </div>
                            )
                        )}

                        {!isCloudflareTunnelsEnabled && isFunctional && (
                            <div className="flex flex-col pb-2">
                                <h3>Automated</h3>
                                {canUpdate ? (
                                    <form onSubmit={submitAutomatedConfig} className="flex flex-col gap-2 w-full max-w-md">
                                        <label className="flex flex-col gap-1">
                                            Cloudflare Token
                                            <input
                                                type="password"
                                                required
                                                value={data.cloudflare_token}
                                                onChange={(e) => setData('cloudflare_token', e.target.value)}
                                            />
                                            {errors.cloudflare_token && <span className="text-error">{errors.cloudflare_token}</span>}
                                        </label>
                                        <label className="flex flex-col gap-1">
                                            Configured SSH Domain
                                            <input
                                                required
                                                value={data.ssh_domain}
                                                onChange={(e) => setData('ssh_domain', e.target.value)}
                                            />
                                            {errors.ssh_domain && <span className="text-error">{errors.ssh_domain}</span>}
                                        </label>
                                        <button type="submit" disabled={processing}>Continue</button>
                                    </form>
                                ) : (
                                    <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded mb-4">
                                        You don't have permission to configure Cloudflare Tunnel for this server.
                                    </div>
                                )}
                            </div>
                        )}

                        <h3 className="pt-6 pb-2">Manual</h3>
                        <div className="pl-2">
                            {canUpdate ? (
                                <button type="button" onClick={manualConfig}>
                                    I manually configured Cloudflare Tunnel
                                </button>
                            ) : (
                                <div className="p-3 border border-warning/30 bg-warning/10 text-warning text-sm rounded mb-4">
                                    You don't have permission to configure Cloudflare Tunnel for this server.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
