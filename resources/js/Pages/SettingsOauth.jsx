import { useForm } from '@inertiajs/react';

const EXTRA_TENANT_PROVIDERS = ['azure', 'google'];
const EXTRA_BASE_URL_PROVIDERS = ['authentik', 'clerk', 'zitadel', 'gitlab'];

export default function SettingsOauth({ providers, updateUrl }) {
    const { data, setData, put, processing, errors } = useForm({ providers });

    function updateProvider(index, field, value) {
        const next = data.providers.map((p, i) => (i === index ? { ...p, [field]: value } : p));
        setData('providers', next);
    }

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
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
                        <a href="/settings/oauth" className="dark:text-white">
                            OAuth
                        </a>
                        <a href="/settings/scheduled-jobs">Scheduled Jobs</a>
                    </nav>
                </div>
            </div>

            <form onSubmit={submit} className="flex flex-col">
                <div className="flex flex-col">
                    <div className="flex items-center gap-2 pb-2">
                        <h2>Authentication</h2>
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    </div>
                    <div className="pb-4">Custom authentication (OAuth) configurations.</div>
                </div>

                <div className="flex flex-col gap-2 pt-4">
                    {data.providers.map((provider, index) => (
                        <div key={provider.provider} className="p-4 border dark:border-coolgray-300 border-neutral-200">
                            <h3>{provider.provider.charAt(0).toUpperCase() + provider.provider.slice(1)}</h3>
                            <div className="w-32">
                                <label className="flex items-center gap-2">
                                    <input
                                        id={`oauth-${provider.provider}-enabled`}
                                        type="checkbox"
                                        checked={provider.enabled}
                                        onChange={(e) => updateProvider(index, 'enabled', e.target.checked)}
                                    />
                                    Enabled
                                </label>
                            </div>
                            <div className="flex flex-col w-full gap-2 xl:flex-row">
                                <label className="flex flex-col gap-1">
                                    Client ID
                                    <input
                                        id={`oauth-${provider.provider}-client-id`}
                                        name={`oauth-${provider.provider}-client-id`}
                                        value={provider.client_id ?? ''}
                                        onChange={(e) => updateProvider(index, 'client_id', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    Client Secret
                                    <input
                                        id={`oauth-${provider.provider}-client-secret`}
                                        name={`oauth-${provider.provider}-client-secret`}
                                        type="password"
                                        autoComplete="new-password"
                                        value={provider.client_secret ?? ''}
                                        onChange={(e) => updateProvider(index, 'client_secret', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1">
                                    Redirect URI
                                    <input
                                        id={`oauth-${provider.provider}-redirect-uri`}
                                        name={`oauth-${provider.provider}-redirect-uri`}
                                        placeholder={provider.callbackUrl}
                                        value={provider.redirect_uri ?? ''}
                                        onChange={(e) => updateProvider(index, 'redirect_uri', e.target.value)}
                                    />
                                </label>
                                {EXTRA_TENANT_PROVIDERS.includes(provider.provider) && (
                                    <label className="flex flex-col gap-1">
                                        Tenant
                                        <input
                                            id={`oauth-${provider.provider}-tenant`}
                                            name={`oauth-${provider.provider}-tenant`}
                                            value={provider.tenant ?? ''}
                                            onChange={(e) => updateProvider(index, 'tenant', e.target.value)}
                                        />
                                    </label>
                                )}
                                {EXTRA_BASE_URL_PROVIDERS.includes(provider.provider) && (
                                    <label className="flex flex-col gap-1">
                                        Base URL
                                        <input
                                            id={`oauth-${provider.provider}-base-url`}
                                            name={`oauth-${provider.provider}-base-url`}
                                            value={provider.base_url ?? ''}
                                            onChange={(e) => updateProvider(index, 'base_url', e.target.value)}
                                        />
                                    </label>
                                )}
                            </div>
                            {errors[`providers.${index}.client_id`] && <span className="text-error">{errors[`providers.${index}.client_id`]}</span>}
                        </div>
                    ))}
                </div>
            </form>
        </div>
    );
}
