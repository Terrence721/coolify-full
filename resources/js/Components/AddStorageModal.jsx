import { useForm } from '@inertiajs/react';

export default function AddStorageModal({ createUrl, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        region: 'us-east-1',
        key: '',
        secret: '',
        bucket: '',
        endpoint: '',
    });

    function submit(e) {
        e.preventDefault();
        post(createUrl, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-2xl">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New S3 Storage</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Name
                            <input id="add-storage-name" name="add-storage-name" required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <span className="text-error">{errors.name}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Description
                            <input id="add-storage-description" name="add-storage-description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                            {errors.description && <span className="text-error">{errors.description}</span>}
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        Region
                        <input id="add-storage-region" name="add-storage-region" required value={data.region} onChange={(e) => setData('region', e.target.value)} />
                        {errors.region && <span className="text-error">{errors.region}</span>}
                    </label>
                    <div className="flex gap-2">
                        <label className="flex flex-col gap-1 w-full">
                            Access Key
                            <input id="add-storage-key" name="add-storage-key" required value={data.key} onChange={(e) => setData('key', e.target.value)} />
                            {errors.key && <span className="text-error">{errors.key}</span>}
                        </label>
                        <label className="flex flex-col gap-1 w-full">
                            Secret Key
                            <input id="add-storage-secret" name="add-storage-secret" type="password" required value={data.secret} onChange={(e) => setData('secret', e.target.value)} />
                            {errors.secret && <span className="text-error">{errors.secret}</span>}
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        Bucket
                        <input id="add-storage-bucket" name="add-storage-bucket" required value={data.bucket} onChange={(e) => setData('bucket', e.target.value)} />
                        {errors.bucket && <span className="text-error">{errors.bucket}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Endpoint
                        <input
                            id="add-storage-endpoint"
                            name="add-storage-endpoint"
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
    );
}
