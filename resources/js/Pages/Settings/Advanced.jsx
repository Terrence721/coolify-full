import { router, useForm } from '@inertiajs/react';

export default function Advanced({ settings, mcpUrl, updateUrl, enableRegistrationUrl, disableTwoStepConfirmationUrl }) {
    const { data, setData, put, processing, errors } = useForm({ ...settings });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function enableRegistration() {
        const confirmation = window.prompt('Type ENABLE REGISTRATION to confirm enabling registration for everyone:');
        if (confirmation !== 'ENABLE REGISTRATION') return;
        const password = window.prompt('Enter your password to confirm:');
        if (!password) return;
        router.post(enableRegistrationUrl, { password });
    }

    function disableTwoStepConfirmation() {
        const confirmation = window.prompt('Type DISABLE TWO STEP CONFIRMATION to confirm:');
        if (confirmation !== 'DISABLE TWO STEP CONFIRMATION') return;
        const password = window.prompt('Enter your password to confirm:');
        if (!password) return;
        router.post(disableTwoStepConfirmationUrl, { password });
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
                    <a className="sub-menu-item menu-item-active" href="/settings/advanced">
                        Advanced
                    </a>
                    <a className="sub-menu-item" href="/settings/updates">
                        Updates
                    </a>
                </div>
                <form onSubmit={submit} className="flex flex-col w-full">
                    <div className="flex items-center gap-2">
                        <h2>Advanced</h2>
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    </div>
                    <div className="pb-4">Advanced settings for your Coolify instance.</div>

                    <div className="flex flex-col gap-1">
                        {data.is_registration_enabled ? (
                            <div className="md:w-96">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={data.is_registration_enabled}
                                        onChange={(e) => setData('is_registration_enabled', e.target.checked)}
                                    />
                                    Registration Allowed
                                </label>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between gap-2 md:w-96">
                                <label>Registration Allowed</label>
                                <button type="button" onClick={enableRegistration}>
                                    Enable
                                </button>
                            </div>
                        )}
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.do_not_track}
                                    onChange={(e) => setData('do_not_track', e.target.checked)}
                                />
                                Do Not Track
                            </label>
                        </div>

                        <h4 className="pt-4">DNS Settings</h4>
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_dns_validation_enabled}
                                    onChange={(e) => setData('is_dns_validation_enabled', e.target.checked)}
                                />
                                DNS Validation
                            </label>
                        </div>
                        <label className="flex flex-col gap-1">
                            Custom DNS Servers
                            <input
                                placeholder="1.1.1.1,8.8.8.8"
                                value={data.custom_dns_servers ?? ''}
                                onChange={(e) => setData('custom_dns_servers', e.target.value)}
                            />
                            {errors.custom_dns_servers && <span className="text-error">{errors.custom_dns_servers}</span>}
                        </label>

                        <h4 className="pt-4">API Settings</h4>
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_api_enabled}
                                    onChange={(e) => setData('is_api_enabled', e.target.checked)}
                                />
                                API Access
                            </label>
                        </div>
                        <label className="flex flex-col gap-1">
                            Allowed IPs for API Access
                            <input
                                placeholder="192.168.1.100,10.0.0.0/8,203.0.113.0/24"
                                value={data.allowed_ips ?? ''}
                                onChange={(e) => setData('allowed_ips', e.target.value)}
                            />
                            {errors.allowed_ips && <span className="text-error">{errors.allowed_ips}</span>}
                        </label>
                        {(!data.allowed_ips || data.allowed_ips.split(',').map((s) => s.trim()).includes('0.0.0.0')) && (
                            <div className="mt-2 text-sm text-warning">
                                Using 0.0.0.0 (or empty) allows API access from anywhere. This is not recommended for
                                production environments!
                            </div>
                        )}

                        <h4 className="pt-4">MCP Server</h4>
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_mcp_server_enabled}
                                    onChange={(e) => setData('is_mcp_server_enabled', e.target.checked)}
                                />
                                Enable MCP Server
                            </label>
                        </div>
                        {data.is_mcp_server_enabled && (
                            <div className="mt-2 text-sm">
                                Endpoint: <code>{mcpUrl}</code>
                                <br />
                                Authenticate with <code>Authorization: Bearer &lt;token&gt;</code> using a token created in
                                Security &raquo; API Tokens.
                            </div>
                        )}

                        <h4 className="pt-4">UI Settings</h4>
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_wire_navigate_enabled}
                                    onChange={(e) => setData('is_wire_navigate_enabled', e.target.checked)}
                                />
                                SPA Navigation
                            </label>
                        </div>

                        <h4 className="pt-4">Confirmation Settings</h4>
                        <div className="md:w-96">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_sponsorship_popup_enabled}
                                    onChange={(e) => setData('is_sponsorship_popup_enabled', e.target.checked)}
                                />
                                Show Sponsorship Popup
                            </label>
                        </div>
                    </div>

                    <div className="flex flex-col gap-1 pt-4">
                        {data.disable_two_step_confirmation ? (
                            <div className="pb-4 md:w-96">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={data.disable_two_step_confirmation}
                                        onChange={(e) => setData('disable_two_step_confirmation', e.target.checked)}
                                    />
                                    Disable Two Step Confirmation
                                </label>
                            </div>
                        ) : (
                            <>
                                <div className="pb-4 flex items-center justify-between gap-2 md:w-96">
                                    <label>Disable Two Step Confirmation</label>
                                    <button type="button" onClick={disableTwoStepConfirmation}>
                                        Disable
                                    </button>
                                </div>
                                <div className="mb-4 text-sm text-error">
                                    Disabling two step confirmation reduces security (as anyone can easily delete anything)
                                    and increases the risk of accidental actions. This is not recommended for production
                                    servers.
                                </div>
                            </>
                        )}
                    </div>
                </form>
            </div>
        </div>
    );
}
