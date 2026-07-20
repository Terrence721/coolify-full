import { useEffect, useState } from 'react';

const AUTO_DISMISS_MS = 4000;

const TYPE_STYLES = {
    success: 'text-green-500',
    info: 'text-blue-500',
    warning: 'text-orange-400',
    danger: 'text-red-500',
    default: 'text-neutral-800 dark:text-neutral-200',
};

/**
 * React port of the old Alpine-based components/toast.blade.php, which stopped working
 * silently the moment Alpine.js was removed from the app (its x-data/x-init/x-teleport
 * setup was the only place window.toast() was ever actually defined) - app-inertia.blade.php,
 * the real Inertia root view, never included it in the first place. Every window.toast(...)
 * call site (AppLayout's flash handler, ServerNavbar, TerminalWindow, Server/Proxy.jsx) has
 * been silently no-op'ing since the migration completed, guarded by a
 * typeof window.toast === 'function' check that was never true on any React page. Found via
 * a real browser session during manual smoke-test QA (todo.md), not by inspection.
 *
 * Deliberately only covers the (title, options: { type, description }) surface every real
 * call site actually uses - the old Alpine version also supported position/html/copy-to-
 * clipboard/stacking-scale animations that nothing in this codebase ever exercised.
 */
export default function Toast() {
    const [toasts, setToasts] = useState([]);

    useEffect(() => {
        window.toast = (title, options = {}) => {
            const id = `toast-${Date.now()}-${Math.random().toString(16).slice(2)}`;
            const toast = {
                id,
                title,
                description: options.description ?? '',
                type: TYPE_STYLES[options.type] ? options.type : 'default',
            };

            setToasts((current) => [...current, toast]);
            setTimeout(() => {
                setToasts((current) => current.filter((t) => t.id !== id));
            }, AUTO_DISMISS_MS);
        };

        return () => {
            delete window.toast;
        };
    }, []);

    function dismiss(id) {
        setToasts((current) => current.filter((t) => t.id !== id));
    }

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="fixed left-1/2 top-0 z-9999 mt-6 flex w-full max-w-xs -translate-x-1/2 flex-col gap-2.5">
            {toasts.map((toast) => (
                <div
                    key={toast.id}
                    role="alert"
                    onClick={() => dismiss(toast.id)}
                    className="w-full cursor-pointer rounded-sm bg-white p-4 shadow-[0_5px_15px_-3px_rgb(0_0_0_/_0.08)] dark:border dark:border-coolgray-200 dark:bg-coolgray-100"
                >
                    <p className={`font-medium ${TYPE_STYLES[toast.type]}`}>{toast.title}</p>
                    {toast.description && <p className="mt-1 whitespace-pre-wrap text-xs opacity-90">{toast.description}</p>}
                </div>
            ))}
        </div>
    );
}
