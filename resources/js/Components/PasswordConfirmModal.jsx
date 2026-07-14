import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function PasswordConfirmModal({ title, action, actions, checkboxes = [], confirmationText, confirmationLabel, withPassword = true, onClose, onDone }) {
    const [selectedActions, setSelectedActions] = useState(() => checkboxes.filter((cb) => cb.default).map((cb) => cb.id));
    const [confirmation, setConfirmation] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    function toggleAction(id) {
        setSelectedActions((prev) => (prev.includes(id) ? prev.filter((a) => a !== id) : [...prev, id]));
    }

    function submit(e) {
        e.preventDefault();
        if (confirmationText && confirmation !== confirmationText) return;
        setProcessing(true);
        setError(null);
        const data = withPassword ? { password } : {};
        selectedActions.forEach((id) => {
            data[id] = true;
        });
        const callbacks = {
            preserveScroll: true,
            onSuccess: () => onDone?.(),
            onError: (errors) => setError(errors.password ?? 'Something went wrong.'),
            onFinish: () => setProcessing(false),
        };
        if (action.method === 'delete') {
            router.delete(action.url, { ...callbacks, data });
        } else {
            router[action.method](action.url, data, callbacks);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex h-screen w-screen items-center justify-center p-4">
            <div className="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs" onClick={onClose} />
            <div className="relative flex max-h-[85vh] w-full flex-col overflow-y-auto rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-lg">
                <div className="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                    <h3 className="text-2xl font-bold">{title}</h3>
                    <button type="button" onClick={onClose}>
                        ✕
                    </button>
                </div>
                <form onSubmit={submit} className="flex flex-col gap-3 p-6">
                    <ul className="list-disc pl-4 text-sm">
                        {actions.map((a) => (
                            <li key={a}>{a}</li>
                        ))}
                    </ul>
                    {checkboxes.map((cb) => (
                        <label key={cb.id} className="flex items-center gap-2">
                            <input id={cb.id} name={cb.id} type="checkbox" checked={selectedActions.includes(cb.id)} onChange={() => toggleAction(cb.id)} />
                            {cb.label}
                        </label>
                    ))}
                    {confirmationText && (
                        <label className="flex flex-col gap-1">
                            {confirmationLabel}
                            <input id="confirmation" name="confirmation" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} placeholder={confirmationText} />
                        </label>
                    )}
                    {withPassword && (
                        <label className="flex flex-col gap-1">
                            Password
                            <input id="password" name="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
                        </label>
                    )}
                    {error && <span className="text-error">{error}</span>}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={onClose}>
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="text-error"
                            disabled={processing || (withPassword && !password) || (confirmationText && confirmation !== confirmationText)}
                        >
                            Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
