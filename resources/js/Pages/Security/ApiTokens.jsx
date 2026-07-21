import { router, useForm } from '@inertiajs/react';

export default function ApiTokens({
    isApiEnabled,
    canCreate,
    canUseRootPermissions,
    canUseWritePermissions,
    canViewCloudTokens,
    canViewCloudInitScripts,
    expirationOptions,
    tokens,
    storeUrl,
    newlyCreatedToken,
}) {
    const { data, setData, post, processing, errors } = useForm({
        description: '',
        expires_in_days: '90',
        permissions: ['read'],
    });

    function togglePermission(permission) {
        let next;

        if (permission === 'root') {
            next = data.permissions.includes('root') ? ['read'] : ['root'];
        } else if (data.permissions.includes('root')) {
            return;
        } else if (permission === 'deploy') {
            next = data.permissions.includes('deploy') ? ['read'] : ['deploy'];
        } else {
            const has = data.permissions.includes(permission);
            next = has ? data.permissions.filter((p) => p !== permission) : [...data.permissions, permission];
            if (next.length === 0) next = ['read'];
        }

        setData('permissions', next);
    }

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            onSuccess: () => setData('description', ''),
        });
    }

    function revoke(revokeUrl) {
        if (!window.confirm('This API Token will be revoked and permanently deleted. Any API call made with this token will fail. Continue?')) {
            return;
        }
        router.delete(revokeUrl);
    }

    const hasRoot = data.permissions.includes('root');

    return (
        <div>
            <div className="pb-6">
                <h1>Security</h1>
                <div className="subtitle">Security related settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 scrollbar min-h-10">
                        <a href="/security/private-key">
                            <button>Private Keys</button>
                        </a>
                        {canViewCloudTokens && (
                            <a href="/security/cloud-tokens">
                                <button>Cloud Tokens</button>
                            </a>
                        )}
                        {canViewCloudInitScripts && (
                            <a href="/security/cloud-init-scripts">
                                <button>Cloud-Init Scripts</button>
                            </a>
                        )}
                        <a href="/security/api-tokens" className="dark:text-white">
                            <button>API Tokens</button>
                        </a>
                    </nav>
                </div>
            </div>

            <div className="pb-4">
                <h2>API Tokens</h2>
                {!isApiEnabled ? (
                    <div>
                        API is disabled. If you want to use the API, please enable it in the{' '}
                        <a href="/settings/advanced" className="underline dark:text-white">
                            Settings
                        </a>{' '}
                        menu.
                    </div>
                ) : (
                    <div>Tokens are created with the current team as scope.</div>
                )}
            </div>

            <h3>New Token</h3>
            {canCreate && (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <div className="flex gap-2 items-end w-lg">
                        <label className="flex flex-col gap-1 w-64">
                            Description
                            <input
                                id="api-token-description"
                                name="api-token-description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                        <label className="flex flex-col gap-1">
                            Expires in
                            <select
                                id="api-token-expires-in-days"
                                name="api-token-expires-in-days"
                                value={data.expires_in_days}
                                onChange={(e) => setData('expires_in_days', e.target.value)}
                            >
                                {Object.entries(expirationOptions).map(([days, label]) => (
                                    <option key={days} value={days}>
                                        {label}
                                    </option>
                                ))}
                                <option value="">Never</option>
                            </select>
                        </label>
                        <button type="submit" disabled={processing}>
                            Create
                        </button>
                    </div>
                    <div className="flex">
                        Permissions<span className="pr-1">:</span>
                        <div className="flex gap-1 font-bold dark:text-white">
                            {data.permissions.map((permission) => (
                                <div key={permission}>{permission}</div>
                            ))}
                        </div>
                    </div>
                    <div className="w-64">
                        <label className="flex items-center gap-2">
                            <input
                                id="api-token-permission-root"
                                type="checkbox"
                                disabled={!canUseRootPermissions}
                                checked={hasRoot}
                                onChange={() => togglePermission('root')}
                            />
                            {canUseRootPermissions ? 'root' : 'root (admin/owner only)'}
                        </label>
                        {!hasRoot && (
                            <>
                                <label className="flex items-center gap-2">
                                    <input
                                        id="api-token-permission-write"
                                        type="checkbox"
                                        disabled={!canUseWritePermissions}
                                        checked={data.permissions.includes('write')}
                                        onChange={() => togglePermission('write')}
                                    />
                                    {canUseWritePermissions ? 'write' : 'write (admin/owner only)'}
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        id="api-token-permission-deploy"
                                        type="checkbox"
                                        checked={data.permissions.includes('deploy')}
                                        onChange={() => togglePermission('deploy')}
                                    />
                                    deploy
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        id="api-token-permission-read"
                                        type="checkbox"
                                        checked={data.permissions.includes('read')}
                                        onChange={() => togglePermission('read')}
                                    />
                                    read
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        id="api-token-permission-read-sensitive"
                                        type="checkbox"
                                        checked={data.permissions.includes('read:sensitive')}
                                        onChange={() => togglePermission('read:sensitive')}
                                    />
                                    read:sensitive
                                </label>
                            </>
                        )}
                    </div>
                    {hasRoot && <div className="font-bold dark:text-warning">Root access, be careful!</div>}
                </form>
            )}

            {newlyCreatedToken && (
                <>
                    <div className="py-4 font-bold dark:text-warning">Please copy this token now. For your security, it won't be shown again.</div>
                    <div className="pb-4 font-bold dark:text-white">{newlyCreatedToken}</div>
                </>
            )}

            <h3 className="py-4">Issued Tokens</h3>
            <div className="overflow-x-auto">
                <table className="min-w-full">
                    <thead>
                        <tr>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Description</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Permissions</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Last used</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Created</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Expires</th>
                            <th className="px-5 py-3 text-xs font-medium text-left uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {tokens.length === 0 && (
                            <tr>
                                <td className="px-5 py-4 text-sm whitespace-nowrap" colSpan={6}>
                                    No API tokens found.
                                </td>
                            </tr>
                        )}
                        {tokens.map((token) => (
                            <tr key={token.id}>
                                <td className="px-5 py-4 text-sm whitespace-nowrap">{token.name}</td>
                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                    <div className="flex gap-1">
                                        {token.abilities?.map((ability) => (
                                            <div key={ability} className="font-bold dark:text-white">
                                                {ability}
                                            </div>
                                        ))}
                                    </div>
                                </td>
                                <td className="px-5 py-4 text-sm whitespace-nowrap">{token.lastUsedAt ?? 'Never'}</td>
                                <td className="px-5 py-4 text-sm whitespace-nowrap">{token.createdAt}</td>
                                <td className="px-5 py-4 text-sm whitespace-nowrap">
                                    {!token.expiresAt ? (
                                        'Never'
                                    ) : token.isExpired ? (
                                        <span className="font-bold dark:text-error">Expired {token.expiresAt}</span>
                                    ) : (
                                        token.expiresAt
                                    )}
                                </td>
                                <td className="px-5 py-4 text-sm font-medium whitespace-nowrap">
                                    {token.ownedByCurrentUser && (
                                        <button type="button" onClick={() => revoke(token.revokeUrl)}>
                                            Revoke token
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
