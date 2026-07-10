import { useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ storages, canCreate, createUrl }) {
    const [showAddModal, setShowAddModal] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        description: '',
        region: 'us-east-1',
        key: '',
        secret: '',
        bucket: '',
        endpoint: '',
    });

    function openAddModal() {
        reset();
        clearErrors();
        setShowAddModal(true);
    }

    function closeAddModal() {
        setShowAddModal(false);
    }

    function submit(e) {
        e.preventDefault();
        post(createUrl, {
            preserveScroll: true,
            onSuccess: () => closeAddModal(),
        });
    }

    return (
        <div>
            <div className="flex items-center gap-2">
                <h1>S3 Storages</h1>
                {canCreate && (
                    <button type="button" onClick={openAddModal}>
                        + Add
                    </button>
                )}
            </div>
            <div className="subtitle">S3 storages for backups.</div>
            <div className="grid gap-4 lg:grid-cols-2 -mt-1">
                {storages.length === 0 && <div>No storage found.</div>}
                {storages.map((storage) => (
                    <a key={storage.uuid} href={storage.showUrl} className="gap-2 border cursor-pointer coolbox group">
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">{storage.name}</div>
                            <div className="box-description">{storage.description}</div>
                            {!storage.isUsable && (
                                <span className="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded dark:text-red-100 dark:bg-red-800">
                                    Not Usable
                                </span>
                            )}
                        </div>
                    </a>
                ))}
            </div>

            {showAddModal && (
                <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
                    <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={closeAddModal} />
                    <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                        <div className="flex items-center justify-between pb-4">
                            <h3 className="text-2xl font-bold">New S3 Storage</h3>
                            <button type="button" onClick={closeAddModal}>
                                ✕
                            </button>
                        </div>
                        <form className="flex flex-col gap-2" onSubmit={submit}>
                            <div className="flex gap-2">
                                <label className="flex flex-col gap-1 w-full">
                                    Name
                                    <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                    {errors.name && <span className="text-error">{errors.name}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Description
                                    <input value={data.description} onChange={(e) => setData('description', e.target.value)} />
                                    {errors.description && <span className="text-error">{errors.description}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                Region
                                <input required value={data.region} onChange={(e) => setData('region', e.target.value)} />
                                {errors.region && <span className="text-error">{errors.region}</span>}
                            </label>
                            <div className="flex gap-2">
                                <label className="flex flex-col gap-1 w-full">
                                    Access Key
                                    <input required value={data.key} onChange={(e) => setData('key', e.target.value)} />
                                    {errors.key && <span className="text-error">{errors.key}</span>}
                                </label>
                                <label className="flex flex-col gap-1 w-full">
                                    Secret Key
                                    <input
                                        type="password"
                                        required
                                        value={data.secret}
                                        onChange={(e) => setData('secret', e.target.value)}
                                    />
                                    {errors.secret && <span className="text-error">{errors.secret}</span>}
                                </label>
                            </div>
                            <label className="flex flex-col gap-1">
                                Bucket
                                <input required value={data.bucket} onChange={(e) => setData('bucket', e.target.value)} />
                                {errors.bucket && <span className="text-error">{errors.bucket}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                Endpoint
                                <input
                                    placeholder="https://s3.us-east-1.amazonaws.com"
                                    value={data.endpoint}
                                    onChange={(e) => setData('endpoint', e.target.value)}
                                />
                                {errors.endpoint && <span className="text-error">{errors.endpoint}</span>}
                            </label>
                            <button type="submit" disabled={processing}>
                                Save
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
