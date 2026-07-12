import { useForm } from '@inertiajs/react';

export default function CreateBackupModal({ open, onClose, storeUrl, s3Storages }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        frequency: '',
        save_to_s3: false,
        s3_storage_id: s3Storages[0]?.id ?? null,
    });

    function handleClose() {
        reset();
        clearErrors();
        onClose();
    }

    function submit(e) {
        e.preventDefault();
        post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                clearErrors();
                onClose();
            },
        });
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={handleClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Scheduled Backup</h3>
                    <button type="button" onClick={handleClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Frequency
                        <input
                            placeholder="e.g. 0 0 * * * or 'every night'"
                            required
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                        />
                        {errors.frequency && <span className="text-error">{errors.frequency}</span>}
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={data.save_to_s3}
                            onChange={(e) => setData('save_to_s3', e.target.checked)}
                        />
                        Save to S3?
                    </label>
                    {data.save_to_s3 && (
                        <label className="flex flex-col gap-1">
                            S3 Storage
                            <select value={data.s3_storage_id ?? ''} onChange={(e) => setData('s3_storage_id', e.target.value ? Number(e.target.value) : null)}>
                                <option value="">Choose an S3 storage...</option>
                                {s3Storages.map((s3) => (
                                    <option key={s3.id} value={s3.id}>
                                        {s3.name}
                                    </option>
                                ))}
                            </select>
                            {errors.s3_storage_id && <span className="text-error">{errors.s3_storage_id}</span>}
                        </label>
                    )}
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}
