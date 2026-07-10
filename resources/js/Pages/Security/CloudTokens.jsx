import { router, useForm } from '@inertiajs/react';

export default function CloudTokens({ tokens, canCreate, storeUrl }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        provider: 'hetzner',
        name: '',
        token: '',
    });

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            onSuccess: () => reset('name', 'token'),
        });
    }

    function validateToken(url) {
        router.post(url);
    }

    function destroyToken(url, name) {
        const confirmation = window.prompt(
            'This cloud provider token will be permanently deleted. Any servers using this token will need to be reconfigured. Type the token name to confirm:',
        );
        if (confirmation !== name) return;
        router.delete(url);
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Security</h1>
                <div className="subtitle">Security related settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 scrollbar min-h-10">
                        <a href="/security/private-key">Private Keys</a>
                        <a href="/security/cloud-tokens" className="dark:text-white">Cloud Tokens</a>
                        <a href="/security/cloud-init-scripts">Cloud-Init Scripts</a>
                        <a href="/security/api-tokens">API Tokens</a>
                    </nav>
                </div>
            </div>

            <h2>Cloud Provider Tokens</h2>
            <div className="pb-4">Manage API tokens for cloud providers (Hetzner, DigitalOcean, etc.).</div>

            {canCreate && (
                <>
                    <h3>New Token</h3>
                    <form className="flex flex-col gap-2" onSubmit={submit}>
                        <div className="flex gap-2 items-end flex-wrap">
                            <div className="w-64">
                                <label className="flex flex-col gap-1">
                                    Provider
                                    <select disabled value={data.provider} onChange={(e) => setData('provider', e.target.value)}>
                                        <option value="hetzner">Hetzner</option>
                                        <option value="digitalocean">DigitalOcean</option>
                                    </select>
                                </label>
                            </div>
                            <div className="flex-1 min-w-64">
                                <label className="flex flex-col gap-1">
                                    Token Name
                                    <input
                                        required
                                        placeholder="e.g., Production Hetzner. tip: add Hetzner project name to identify easier"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                            </div>
                        </div>
                        <div className="flex-1 min-w-64">
                            <label className="flex flex-col gap-1">
                                API Token
                                <input
                                    required
                                    type="password"
                                    placeholder="Enter your API token"
                                    value={data.token}
                                    onChange={(e) => setData('token', e.target.value)}
                                />
                                {errors.token && <span className="text-error">{errors.token}</span>}
                            </label>
                            <div className="text-sm text-neutral-500 dark:text-neutral-400 mt-2">
                                Create an API token in the{' '}
                                <a href="https://console.hetzner.com/projects" target="_blank" rel="noreferrer" className="underline dark:text-white">
                                    Hetzner Console
                                </a>{' '}
                                → choose Project → Security → API Tokens.
                            </div>
                        </div>
                        <div>
                            <button type="submit" disabled={processing}>
                                Validate & Add Token
                            </button>
                        </div>
                    </form>
                </>
            )}

            <h3 className="py-4">Saved Tokens</h3>
            <div className="grid gap-2 lg:grid-cols-1">
                {tokens.length === 0 && <div>No cloud provider tokens found.</div>}
                {tokens.map((token) => (
                    <div key={token.id} className="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                        <div className="flex items-center gap-2">
                            <span className="px-2 py-1 text-xs font-bold rounded dark:bg-coolgray-300 dark:text-white">
                                {token.provider.toUpperCase()}
                            </span>
                            <span className="font-bold dark:text-white">{token.name}</span>
                        </div>
                        <div className="text-sm">Created: {token.createdAgo}</div>
                        <div className="flex gap-2 pt-2">
                            <button type="button" onClick={() => validateToken(token.validateUrl)}>
                                Validate
                            </button>
                            <button type="button" className="text-error" onClick={() => destroyToken(token.destroyUrl, token.name)}>
                                Delete
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
