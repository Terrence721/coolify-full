import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Show({
    storage,
    backupCount,
    canUpdate,
    canDelete,
    canValidateConnection,
    showUrl,
    resourcesUrl,
    updateUrl,
    testConnectionUrl,
    deleteUrl,
}) {
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [confirmation, setConfirmation] = useState('');
    const { data, setData, put, processing, errors } = useForm({
        name: storage.name ?? '',
        description: storage.description ?? '',
        endpoint: storage.endpoint ?? '',
        bucket: storage.bucket ?? '',
        region: storage.region ?? '',
        key: storage.key ?? '',
        secret: storage.secret ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function testConnection() {
        router.post(testConnectionUrl, {}, { preserveScroll: true });
    }

    function destroy() {
        if (confirmation !== storage.name) return;
        router.delete(deleteUrl);
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>Storage Details</h1>
                {storage.isUsable ? (
                    <span className="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                        Usable
                    </span>
                ) : (
                    <span className="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                        Not Usable
                    </span>
                )}
                {canUpdate && (
                    <button type="submit" form="storage-form" disabled={processing}>
                        Save
                    </button>
                )}
                {canDelete && (
                    <button type="button" onClick={() => setShowDeleteModal(true)}>
                        Delete
                    </button>
                )}
            </div>
            <div className="subtitle">{storage.name}</div>

            <div className="navbar-main">
                <nav className="flex shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
                    <a className="dark:text-white" href={showUrl}>
                        General
                    </a>
                    <a href={resourcesUrl}>Resources</a>
                </nav>
            </div>

            <div className="pt-4">
                <form id="storage-form" className="flex flex-col gap-2 pb-6" onSubmit={submit}>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Name
                            <input
                                id="storage-name"
                                name="storage-name"
                                disabled={!canUpdate}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Description
                            <input
                                id="storage-description"
                                name="storage-description"
                                disabled={!canUpdate}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                    </div>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Endpoint
                            <input
                                id="storage-endpoint"
                                name="storage-endpoint"
                                required
                                disabled={!canUpdate}
                                value={data.endpoint}
                                onChange={(e) => setData('endpoint', e.target.value)}
                            />
                            {errors.endpoint && <span className="text-error">{errors.endpoint}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Bucket
                            <input
                                id="storage-bucket"
                                name="storage-bucket"
                                required
                                disabled={!canUpdate}
                                value={data.bucket}
                                onChange={(e) => setData('bucket', e.target.value)}
                            />
                            {errors.bucket && <span className="text-error">{errors.bucket}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Region
                            <input
                                id="storage-region"
                                name="storage-region"
                                required
                                disabled={!canUpdate}
                                value={data.region}
                                onChange={(e) => setData('region', e.target.value)}
                            />
                            {errors.region && <span className="text-error">{errors.region}</span>}
                        </label>
                    </div>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Access Key
                            <input
                                id="storage-key"
                                name="storage-key"
                                type="password"
                                required
                                disabled={!canUpdate}
                                value={data.key}
                                onChange={(e) => setData('key', e.target.value)}
                            />
                            {errors.key && <span className="text-error">{errors.key}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Secret Key
                            <input
                                id="storage-secret"
                                name="storage-secret"
                                type="password"
                                required
                                disabled={!canUpdate}
                                value={data.secret}
                                onChange={(e) => setData('secret', e.target.value)}
                            />
                            {errors.secret && <span className="text-error">{errors.secret}</span>}
                        </label>
                    </div>
                    {canValidateConnection && (
                        <button type="button" className="mt-4" onClick={testConnection}>
                            Validate Connection
                        </button>
                    )}
                </form>
            </div>

            {showDeleteModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div
                        className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs"
                        onClick={() => {
                            setShowDeleteModal(false);
                            setConfirmation('');
                        }}
                    />
                    <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                        <h3 className="text-2xl font-bold pb-4">Confirm Storage Deletion?</h3>
                        <ul className="list-disc pl-4 pb-4 text-sm">
                            <li>The selected storage location will be permanently deleted from Coolify.</li>
                            {backupCount > 0 && (
                                <li>
                                    {backupCount} backup schedule(s) will be updated to no longer save to S3 and will only store backups locally on
                                    the server.
                                </li>
                            )}
                        </ul>
                        <label className="flex flex-col gap-1 pb-4">
                            Please confirm the execution of the actions by entering the Storage Name below
                            <input
                                id="storage-delete-confirm"
                                name="storage-delete-confirm"
                                value={confirmation}
                                onChange={(e) => setConfirmation(e.target.value)}
                                placeholder={storage.name}
                            />
                        </label>
                        <div className="flex gap-2 justify-end">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowDeleteModal(false);
                                    setConfirmation('');
                                }}
                            >
                                Cancel
                            </button>
                            <button type="button" disabled={confirmation !== storage.name} onClick={destroy}>
                                Permanently Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
