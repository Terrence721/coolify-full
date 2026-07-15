import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import PrivateKeyCreateModal from '../../../Components/PrivateKeyCreateModal';

function cpuVendorInfo(serverType) {
    const name = (serverType.name || '').toLowerCase();
    if (name.startsWith('ccx')) return 'AMD Milan EPYC™';
    if (name.startsWith('cpx')) return 'AMD EPYC™';
    if (name.startsWith('cax')) return 'Ampere®';
    if (name.startsWith('cx')) return 'Intel®/AMD';
    return null;
}

/**
 * New React flow for Hetzner Cloud server creation. The original Livewire wizard
 * (App\Livewire\Server\New\ByHetzner) was deleted along with the rest of the Livewire UI;
 * this is a from-scratch rebuild against the same still-intact HetznerService/CreateHetznerServer
 * backend, following the same 2-step shape: pick a token, then configure and create the server.
 */
export default function Hetzner({ tokens: initialTokens, privateKeys: initialPrivateKeys, cloudInitScripts, defaultName, urls }) {
    const [tokens, setTokens] = useState(initialTokens);
    const [privateKeys, setPrivateKeys] = useState(initialPrivateKeys);
    const [step, setStep] = useState(1);
    const [showAddTokenForm, setShowAddTokenForm] = useState(initialTokens.length === 0);
    const [showAddKeyModal, setShowAddKeyModal] = useState(false);
    const [loadingData, setLoadingData] = useState(false);
    const [dataError, setDataError] = useState(null);
    const [locations, setLocations] = useState([]);
    const [serverTypes, setServerTypes] = useState([]);
    const [images, setImages] = useState([]);
    const [hetznerSshKeys, setHetznerSshKeys] = useState([]);

    const tokenForm = useForm({
        provider: 'hetzner',
        name: '',
        token: '',
    });

    const { data, setData, post, processing, errors } = useForm({
        token_id: '',
        private_key_id: privateKeys[0]?.id ?? '',
        location: '',
        server_type: '',
        image: '',
        name: defaultName,
        enable_ipv4: true,
        enable_ipv6: true,
        hetzner_ssh_key_ids: [],
        cloud_init_script: '',
        save_cloud_init_script: false,
        cloud_init_script_name: '',
        selected_cloud_init_script_id: '',
        instant_validate: false,
    });

    const availableServerTypes = useMemo(() => {
        if (!data.location) return serverTypes;

        return serverTypes
            .filter((type) => (type.locations || []).some((location) => location.name === data.location))
            .map((type) => ({ ...type, cpuVendorInfo: cpuVendorInfo(type) }));
    }, [serverTypes, data.location]);

    const availableImages = useMemo(() => {
        if (!data.server_type) return images;

        const serverType = serverTypes.find((type) => type.name === data.server_type);
        if (!serverType?.architecture) return images;

        return images.filter((image) => image.architecture === serverType.architecture);
    }, [images, serverTypes, data.server_type]);

    const selectedServerPrice = useMemo(() => {
        const serverType = serverTypes.find((type) => type.name === data.server_type);
        const price = serverType?.prices?.[0]?.price_monthly?.gross;

        return price ? `€${Number(price).toFixed(2)}` : null;
    }, [serverTypes, data.server_type]);

    function submitAddToken(e) {
        e.preventDefault();
        tokenForm.post(urls.tokenStore, {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({
                    only: ['tokens'],
                    onSuccess: (page) => {
                        const refreshed = page.props.tokens;
                        setTokens(refreshed);
                        setShowAddTokenForm(false);
                        tokenForm.reset();
                        const newest = refreshed[refreshed.length - 1];
                        if (newest) {
                            setData('token_id', newest.id);
                        }
                    },
                });
            },
        });
    }

    async function selectTokenAndContinue() {
        if (!data.token_id) return;
        setLoadingData(true);
        setDataError(null);
        try {
            const response = await fetch(`${urls.data}?token_id=${data.token_id}`, {
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();
            if (!response.ok) {
                setDataError(result.message || 'Failed to load Hetzner Cloud data.');
                return;
            }
            setLocations(result.locations);
            setServerTypes(result.serverTypes);
            setImages(result.images.slice().sort((a, b) => a.name.localeCompare(b.name)));
            setHetznerSshKeys(result.sshKeys);
            setStep(2);
        } catch {
            setDataError('Failed to load Hetzner Cloud data.');
        } finally {
            setLoadingData(false);
        }
    }

    function onKeyCreated() {
        setShowAddKeyModal(false);
        router.reload({
            only: ['privateKeys'],
            onSuccess: (page) => {
                const refreshed = page.props.privateKeys;
                setPrivateKeys(refreshed);
                const newest = refreshed[refreshed.length - 1];
                if (newest) {
                    setData('private_key_id', newest.id);
                }
            },
        });
    }

    function selectSavedCloudInitScript(id) {
        const script = id ? cloudInitScripts.find((s) => String(s.id) === String(id)) : null;
        setData((prev) => ({
            ...prev,
            selected_cloud_init_script_id: id,
            cloud_init_script: script ? script.script : prev.cloud_init_script,
            cloud_init_script_name: script ? script.name : prev.cloud_init_script_name,
        }));
    }

    function toggleHetznerSshKey(id) {
        setData(
            'hetzner_ssh_key_ids',
            data.hetzner_ssh_key_ids.includes(id) ? data.hetzner_ssh_key_ids.filter((k) => k !== id) : [...data.hetzner_ssh_key_ids, id],
        );
    }

    function submitCreate(e) {
        e.preventDefault();
        post(urls.store, { preserveScroll: true });
    }

    return (
        <div>
            <h1>New Server via Hetzner Cloud</h1>
            <div className="subtitle">Provision and register a new server on Hetzner Cloud.</div>

            {step === 1 && (
                <div className="flex flex-col gap-4 max-w-xl">
                    <h2>Step 1: Choose a Hetzner Cloud token</h2>
                    {tokens.length > 0 && (
                        <div className="grid gap-2">
                            {tokens.map((token) => (
                                <label
                                    key={token.id}
                                    className="flex items-center gap-2 box-without-bg dark:bg-coolgray-100 bg-white cursor-pointer"
                                >
                                    <input
                                        id={`hetzner-token-${token.id}`}
                                        name="hetzner-token"
                                        type="radio"
                                        checked={String(data.token_id) === String(token.id)}
                                        onChange={() => setData('token_id', token.id)}
                                    />
                                    {token.name}
                                </label>
                            ))}
                        </div>
                    )}
                    {!showAddTokenForm && (
                        <button type="button" onClick={() => setShowAddTokenForm(true)}>
                            + Add another token
                        </button>
                    )}
                    {showAddTokenForm && (
                        <form className="flex flex-col gap-2" onSubmit={submitAddToken}>
                            <label className="flex flex-col gap-1">
                                Token Name
                                <input
                                    id="hetzner-new-token-name"
                                    name="hetzner-new-token-name"
                                    required
                                    placeholder="e.g., Production Hetzner"
                                    value={tokenForm.data.name}
                                    onChange={(e) => tokenForm.setData('name', e.target.value)}
                                />
                                {tokenForm.errors.name && <span className="text-error">{tokenForm.errors.name}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                API Token
                                <input
                                    id="hetzner-new-token-value"
                                    name="hetzner-new-token-value"
                                    type="password"
                                    required
                                    placeholder="Enter your API token"
                                    value={tokenForm.data.token}
                                    onChange={(e) => tokenForm.setData('token', e.target.value)}
                                />
                                {tokenForm.errors.token && <span className="text-error">{tokenForm.errors.token}</span>}
                            </label>
                            <div className="text-sm text-neutral-500 dark:text-neutral-400">
                                Create an API token in the{' '}
                                <a href="https://console.hetzner.com/projects" target="_blank" rel="noreferrer" className="underline dark:text-white">
                                    Hetzner Console
                                </a>{' '}
                                → choose Project → Security → API Tokens.
                            </div>
                            <button type="submit" disabled={tokenForm.processing}>
                                Validate & Add Token
                            </button>
                        </form>
                    )}
                    {dataError && <div className="text-error">{dataError}</div>}
                    <button type="button" disabled={!data.token_id || loadingData} onClick={selectTokenAndContinue}>
                        {loadingData ? 'Loading...' : 'Continue'}
                    </button>
                </div>
            )}

            {step === 2 && (
                <form className="flex flex-col gap-4 max-w-2xl" onSubmit={submitCreate}>
                    <div className="flex items-center gap-2">
                        <h2>Step 2: Configure your server</h2>
                        <button type="button" onClick={() => setStep(1)}>
                            ← Back
                        </button>
                    </div>

                    <label className="flex flex-col gap-1">
                        Location
                        <select
                            id="hetzner-location"
                            name="hetzner-location"
                            required
                            value={data.location}
                            onChange={(e) => {
                                setData('location', e.target.value);
                                setData('server_type', '');
                                setData('image', '');
                            }}
                        >
                            <option disabled value="">
                                Select a location
                            </option>
                            {locations.map((location) => (
                                <option key={location.id} value={location.name}>
                                    {location.city} ({location.country}) — {location.name}
                                </option>
                            ))}
                        </select>
                        {errors.location && <span className="text-error">{errors.location}</span>}
                    </label>

                    <label className="flex flex-col gap-1">
                        Server Type
                        <select
                            id="hetzner-server-type"
                            name="hetzner-server-type"
                            required
                            disabled={!data.location}
                            value={data.server_type}
                            onChange={(e) => {
                                setData('server_type', e.target.value);
                                setData('image', '');
                            }}
                        >
                            <option disabled value="">
                                Select a server type
                            </option>
                            {availableServerTypes.map((type) => (
                                <option key={type.name} value={type.name}>
                                    {type.name} — {type.cores} vCPU / {type.memory}GB RAM / {type.disk}GB disk
                                    {type.cpuVendorInfo ? ` (${type.cpuVendorInfo})` : ''}
                                </option>
                            ))}
                        </select>
                        {selectedServerPrice && <span className="text-xs text-neutral-500">Estimated: {selectedServerPrice}/month</span>}
                        {errors.server_type && <span className="text-error">{errors.server_type}</span>}
                    </label>

                    <label className="flex flex-col gap-1">
                        Image
                        <select
                            id="hetzner-image"
                            name="hetzner-image"
                            required
                            disabled={!data.server_type}
                            value={data.image}
                            onChange={(e) => setData('image', e.target.value)}
                        >
                            <option disabled value="">
                                Select an image
                            </option>
                            {availableImages.map((image) => (
                                <option key={image.id} value={image.id}>
                                    {image.name || image.description}
                                </option>
                            ))}
                        </select>
                        {errors.image && <span className="text-error">{errors.image}</span>}
                    </label>

                    <label className="flex flex-col gap-1">
                        Server Name
                        <input
                            id="hetzner-server-name"
                            name="hetzner-server-name"
                            required
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>

                    <div className="flex items-end gap-2">
                        <label className="flex flex-col gap-1 flex-1">
                            Private Key
                            <select
                                id="hetzner-private-key-id"
                                name="hetzner-private-key-id"
                                required
                                value={data.private_key_id}
                                onChange={(e) => setData('private_key_id', e.target.value)}
                            >
                                <option disabled value="">
                                    Select a private key
                                </option>
                                {privateKeys.map((key) => (
                                    <option key={key.id} value={key.id}>
                                        {key.name}
                                    </option>
                                ))}
                            </select>
                            {errors.private_key_id && <span className="text-error">{errors.private_key_id}</span>}
                        </label>
                        <button type="button" onClick={() => setShowAddKeyModal(true)}>
                            + New key
                        </button>
                    </div>

                    {hetznerSshKeys.length > 0 && (
                        <div className="flex flex-col gap-1">
                            <span>Additional Hetzner SSH Keys (optional)</span>
                            <div className="grid gap-1">
                                {hetznerSshKeys.map((key) => (
                                    <label key={key.id} className="flex items-center gap-2">
                                        <input
                                            id={`hetzner-ssh-key-${key.id}`}
                                            name={`hetzner-ssh-key-${key.id}`}
                                            type="checkbox"
                                            checked={data.hetzner_ssh_key_ids.includes(key.id)}
                                            onChange={() => toggleHetznerSshKey(key.id)}
                                        />
                                        {key.name}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="flex gap-4">
                        <label className="flex items-center gap-2">
                            <input
                                id="hetzner-enable-ipv4"
                                name="hetzner-enable-ipv4"
                                type="checkbox"
                                checked={data.enable_ipv4}
                                onChange={(e) => setData('enable_ipv4', e.target.checked)}
                            />
                            Enable IPv4
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                id="hetzner-enable-ipv6"
                                name="hetzner-enable-ipv6"
                                type="checkbox"
                                checked={data.enable_ipv6}
                                onChange={(e) => setData('enable_ipv6', e.target.checked)}
                            />
                            Enable IPv6
                        </label>
                    </div>
                    {errors.enable_ipv4 && <span className="text-error">{errors.enable_ipv4}</span>}

                    {cloudInitScripts.length > 0 && (
                        <label className="flex flex-col gap-1">
                            Load a saved Cloud-Init script (optional)
                            <select
                                id="hetzner-saved-cloud-init"
                                name="hetzner-saved-cloud-init"
                                value={data.selected_cloud_init_script_id}
                                onChange={(e) => selectSavedCloudInitScript(e.target.value)}
                            >
                                <option value="">None</option>
                                {cloudInitScripts.map((script) => (
                                    <option key={script.id} value={script.id}>
                                        {script.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}

                    <label className="flex flex-col gap-1">
                        Cloud-Init Script (optional)
                        <textarea
                            id="hetzner-cloud-init-script"
                            name="hetzner-cloud-init-script"
                            rows="6"
                            className="font-mono"
                            placeholder="#cloud-config or #!/bin/bash"
                            value={data.cloud_init_script}
                            onChange={(e) => setData('cloud_init_script', e.target.value)}
                        />
                        {errors.cloud_init_script && <span className="text-error">{errors.cloud_init_script}</span>}
                    </label>

                    {data.cloud_init_script && (
                        <>
                            <label className="flex items-center gap-2">
                                <input
                                    id="hetzner-save-cloud-init-script"
                                    name="hetzner-save-cloud-init-script"
                                    type="checkbox"
                                    checked={data.save_cloud_init_script}
                                    onChange={(e) => setData('save_cloud_init_script', e.target.checked)}
                                />
                                Save this script for reuse
                            </label>
                            {data.save_cloud_init_script && (
                                <label className="flex flex-col gap-1">
                                    Script Name
                                    <input
                                        id="hetzner-cloud-init-script-name"
                                        name="hetzner-cloud-init-script-name"
                                        required
                                        value={data.cloud_init_script_name}
                                        onChange={(e) => setData('cloud_init_script_name', e.target.value)}
                                    />
                                    {errors.cloud_init_script_name && <span className="text-error">{errors.cloud_init_script_name}</span>}
                                </label>
                            )}
                        </>
                    )}

                    <button type="submit" disabled={processing}>
                        Create Server
                    </button>
                </form>
            )}

            <PrivateKeyCreateModal
                open={showAddKeyModal}
                onClose={() => setShowAddKeyModal(false)}
                createKeyUrl={urls.privateKeyStore}
                generateKeyUrl={urls.privateKeyGenerate}
                onCreated={onKeyCreated}
            />
        </div>
    );
}
