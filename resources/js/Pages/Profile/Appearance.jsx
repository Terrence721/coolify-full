import { useEffect, useState } from 'react';

function applyTheme(theme) {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = theme === 'dark' || (theme === 'system' && prefersDark);
    document.documentElement.classList.toggle('dark', isDark);
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
}

function optionClass(active) {
    return `flex items-center gap-2 rounded-sm border border-neutral-300 bg-white px-2 py-1 text-sm hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-coollabs dark:border-coolgray-300 dark:bg-coolgray-100 dark:hover:bg-coolgray-200 dark:focus-visible:ring-warning ${active ? 'border-coollabs text-coollabs dark:border-warning dark:text-warning' : ''}`;
}

export default function Appearance() {
    const [theme, setThemeState] = useState(() => localStorage.getItem('theme') || 'dark');
    const [pageWidth, setPageWidthState] = useState(() => localStorage.getItem('pageWidth') || 'full');
    const [zoom, setZoomState] = useState(() => localStorage.getItem('zoom') || '100');

    useEffect(() => {
        applyTheme(theme);
    }, []);

    function setTheme(type) {
        setThemeState(type);
        localStorage.setItem('theme', type);
        applyTheme(type);
    }

    function setWidth(width) {
        setPageWidthState(width);
        localStorage.setItem('pageWidth', width);
        window.location.reload();
    }

    function setZoom(value) {
        setZoomState(value);
        localStorage.setItem('zoom', value);
        window.location.reload();
    }

    return (
        <div>
            <div className="pb-6">
                <h1>Profile</h1>
                <div className="subtitle">Your user profile settings.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10">
                        <a href="/profile">General</a>
                        <a href="/profile/appearance" className="dark:text-white">
                            Appearance
                        </a>
                        <div className="flex-1" />
                    </nav>
                </div>
            </div>

            <div className="flex max-w-2xl flex-col">
                <section className="space-y-1.5">
                    <h2>Appearance</h2>
                    <div>Choose how Coolify looks in this browser.</div>
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            aria-label="Use light theme"
                            className={optionClass(theme === 'light')}
                            onClick={() => setTheme('light')}
                        >
                            Light
                        </button>
                        <button
                            type="button"
                            aria-label="Use system theme"
                            className={optionClass(theme === 'system')}
                            onClick={() => setTheme('system')}
                        >
                            System
                        </button>
                        <button type="button" aria-label="Use dark theme" className={optionClass(theme === 'dark')} onClick={() => setTheme('dark')}>
                            Dark
                        </button>
                    </div>
                </section>

                <section className="space-y-1.5">
                    <h2>Width</h2>
                    <div>Choose the maximum page width for this browser.</div>
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            aria-label="Use centered width"
                            className={optionClass(pageWidth === 'center')}
                            onClick={() => setWidth('center')}
                        >
                            Center
                        </button>
                        <button
                            type="button"
                            aria-label="Use full width"
                            className={optionClass(pageWidth === 'full')}
                            onClick={() => setWidth('full')}
                        >
                            Full
                        </button>
                    </div>
                </section>

                <section className="space-y-1.5">
                    <h2>Zoom</h2>
                    <div>Choose interface density for this browser.</div>
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            aria-label="Use 100 percent zoom"
                            className={optionClass(zoom === '100')}
                            onClick={() => setZoom('100')}
                        >
                            100%
                        </button>
                        <button type="button" aria-label="Use 90 percent zoom" className={optionClass(zoom === '90')} onClick={() => setZoom('90')}>
                            90%
                        </button>
                    </div>
                </section>
            </div>
        </div>
    );
}
