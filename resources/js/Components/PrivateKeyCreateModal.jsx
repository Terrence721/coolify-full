import { useForm } from '@inertiajs/react';
import { useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

/**
 * React port of the former App\Livewire\Security\PrivateKey\Create's modal_mode=true usage
 * (that Livewire component no longer exists) — reused by Dashboard, Boarding\Index,
 * Server/PrivateKey/Show, Security/PrivateKey/Index, and GlobalSearchModal.
 */
export default function PrivateKeyCreateModal({ open, onClose, createKeyUrl, generateKeyUrl, onCreated }) {
    const [publicKey, setPublicKey] = useState('');
    const [generating, setGenerating] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        description: '',
        value: '',
        modal_mode: true,
    });

    function handleClose() {
        onClose();
    }

    async function generateKey(type) {
        setGenerating(true);
        try {
            const response = await fetch(generateKeyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ type }),
            });
            const result = await response.json();
            setData((prev) => ({ ...prev, name: result.name, description: result.description, value: result.value }));
            setPublicKey(result.publicKey);
        } finally {
            setGenerating(false);
        }
    }

    function submitCreate(e) {
        e.preventDefault();
        post(createKeyUrl, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearErrors();
                setPublicKey('');
                onCreated?.();
            },
        });
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={handleClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Private Key</h3>
                    <button type="button" onClick={handleClose}>
                        ✕
                    </button>
                </div>
                <div className="pb-2 text-sm">
                    <div>Private Keys are used to connect to your servers without passwords.</div>
                    <div className="font-bold">You should not use passphrase protected keys.</div>
                </div>
                <div className="flex gap-2 mb-4 w-full">
                    <button type="button" className="w-full" disabled={generating} onClick={() => generateKey('ed25519')}>
                        Generate new ED25519 SSH Key
                    </button>
                    <button type="button" className="w-full" disabled={generating} onClick={() => generateKey('rsa')}>
                        Generate new RSA SSH Key
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submitCreate}>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Name
                            <input
                                id="private-key-create-name"
                                name="private-key-create-name"
                                required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Description
                            <input
                                id="private-key-create-description"
                                name="private-key-create-description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        Private Key
                        <textarea
                            id="private-key-create-value"
                            name="private-key-create-value"
                            rows="10"
                            className="font-mono"
                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                            required
                            value={data.value}
                            onChange={(e) => setData('value', e.target.value)}
                        />
                        {errors.value && <span className="text-error">{errors.value}</span>}
                    </label>
                    {publicKey && (
                        <label className="flex flex-col gap-1">
                            Public Key
                            <input id="private-key-create-public-key" name="private-key-create-public-key" readOnly value={publicKey} />
                        </label>
                    )}
                    {publicKey && (
                        <span className="pt-2 pb-4 font-bold dark:text-warning">
                            ACTION REQUIRED: Copy the &apos;Public Key&apos; to your server&apos;s ~/.ssh/authorized_keys file
                        </span>
                    )}
                    <button type="submit" disabled={processing}>
                        Continue
                    </button>
                </form>
            </div>
        </div>
    );
}
