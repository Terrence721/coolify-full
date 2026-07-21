import { router, useForm } from '@inertiajs/react';

export default function AddServerModal({ privateKeys, defaultPrivateKeyId, defaultName, storeUrl, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        name: defaultName,
        description: '',
        ip: '',
        user: 'root',
        port: 22,
        private_key_id: defaultPrivateKeyId ?? '',
        is_build_server: false,
    });

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">Add Server by IP Address</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <button
                    type="button"
                    className="self-start pb-2 text-sm underline"
                    onClick={() => {
                        onClose();
                        router.visit('/servers/new/hetzner');
                    }}
                >
                    Add via Hetzner Cloud →
                </button>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <div className="flex w-full gap-2 flex-wrap sm:flex-nowrap">
                        <label className="flex flex-col gap-1 w-full">
                            Name
                            <input
                                id="add-server-name"
                                name="add-server-name"
                                required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Description
                            <input
                                id="add-server-description"
                                name="add-server-description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                    </div>
                    <div className="flex gap-2 flex-wrap sm:flex-nowrap">
                        <label className="flex flex-col gap-1 w-full">
                            IP Address/Domain
                            <input id="add-server-ip" name="add-server-ip" required value={data.ip} onChange={(e) => setData('ip', e.target.value)} />
                            {errors.ip && <span className="text-error">{errors.ip}</span>}
                        </label>
                        <label className="flex flex-col gap-1">
                            Port
                            <input
                                id="add-server-port"
                                name="add-server-port"
                                type="number"
                                required
                                value={data.port}
                                onChange={(e) => setData('port', e.target.value)}
                            />
                            {errors.port && <span className="text-error">{errors.port}</span>}
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        User
                        <input
                            id="add-server-user"
                            name="add-server-user"
                            required
                            value={data.user}
                            onChange={(e) => setData('user', e.target.value)}
                        />
                        {errors.user && <span className="text-error">{errors.user}</span>}
                    </label>
                    <div className="text-xs dark:text-warning text-coollabs">
                        Non-root user is experimental:{' '}
                        <a
                            className="font-bold underline"
                            target="_blank"
                            rel="noreferrer"
                            href="https://coolify.io/docs/knowledge-base/server/non-root-user"
                        >
                            docs
                        </a>
                        .
                    </div>
                    <label className="flex flex-col gap-1">
                        Private Key
                        <select
                            id="add-server-private-key-id"
                            name="add-server-private-key-id"
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
                    <label className="flex items-center gap-2">
                        <input
                            id="add-server-is-build-server"
                            type="checkbox"
                            checked={data.is_build_server}
                            onChange={(e) => setData('is_build_server', e.target.checked)}
                        />
                        Use it as a build server?
                    </label>
                    <button type="submit" disabled={processing}>
                        Continue
                    </button>
                </form>
            </div>
        </div>
    );
}
