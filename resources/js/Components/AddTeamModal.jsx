import { useForm } from '@inertiajs/react';

/**
 * React port of App\Livewire\Team\Create — creating a brand new team (distinct from Team/Index.jsx,
 * which edits the current one). Only reachable via GlobalSearch's "new team" quick action; the
 * Livewire version stays in place for GlobalSearch's own not-yet-converted consumers.
 */
export default function AddTeamModal({ createUrl, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ name: '', description: '' });

    function submit(e) {
        e.preventDefault();
        post(createUrl, { preserveScroll: true });
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex w-full flex-col rounded-sm border border-neutral-200 bg-white p-6 shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex items-center justify-between pb-4">
                    <h3 className="text-2xl font-bold">New Team</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form className="flex flex-col gap-2" onSubmit={submit}>
                    <label className="flex flex-col gap-1">
                        Name
                        <input required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        {errors.description && <span className="text-error">{errors.description}</span>}
                    </label>
                    <button type="submit" disabled={processing}>
                        Save
                    </button>
                </form>
            </div>
        </div>
    );
}
