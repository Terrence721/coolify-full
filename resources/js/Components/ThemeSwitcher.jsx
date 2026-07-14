import { useEffect, useState } from 'react';

const THEMES = ['light', 'system', 'dark'];

/**
 * Light/system/dark theme toggle, mirroring navbar.blade.php's own inline Alpine
 * queryTheme()/setTheme() logic exactly (same `theme` localStorage key, same
 * `dark` class + `data-theme` attribute on <html>, same theme-color meta tag
 * update) so switching theme on a React page stays consistent with the still-Livewire
 * pages sharing the same browser storage. `app-inertia.blade.php`'s own inline
 * FOUC-prevention script already sets the initial class/attribute before this
 * component ever mounts; this only needs to handle later changes.
 */
function applyTheme(type) {
    const isDark = type === 'dark' || (type === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
}

export default function ThemeSwitcher() {
    const [theme, setThemeState] = useState(() => localStorage.getItem('theme') || 'dark');

    useEffect(() => {
        if (theme !== 'system') return undefined;
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => applyTheme('system');
        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, [theme]);

    function setTheme(type) {
        setThemeState(type);
        localStorage.setItem('theme', type);
        applyTheme(type);
    }

    return (
        <div className="flex items-center gap-0.5 rounded-sm bg-neutral-100 p-0.5 dark:bg-coolgray-200" title="Theme">
            {THEMES.map((type) => (
                <button
                    key={type}
                    type="button"
                    onClick={() => setTheme(type)}
                    title={type === 'system' ? 'System default' : type.charAt(0).toUpperCase() + type.slice(1)}
                    aria-label={`Use ${type} theme`}
                    className={`grid size-6 place-items-center rounded-sm text-xs hover:bg-white hover:text-coollabs dark:hover:bg-base dark:hover:text-warning ${
                        theme === type ? 'bg-white text-coollabs shadow-sm dark:bg-base dark:text-warning' : ''
                    }`}
                >
                    {type === 'light' && (
                        <svg className="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"
                            />
                        </svg>
                    )}
                    {type === 'system' && (
                        <svg className="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                            />
                        </svg>
                    )}
                    {type === 'dark' && (
                        <svg className="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"
                            />
                        </svg>
                    )}
                </button>
            ))}
        </div>
    );
}
