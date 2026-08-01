import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ThemeSwitcher from './ThemeSwitcher';

// Mirrors navbar.blade.php's own inline Alpine queryTheme()/setTheme() logic - same localStorage
// key, same <html> class/data-theme attribute, same theme-color meta tag. jsdom has no real
// matchMedia implementation, so "system" theme support needs a manual mock with a working
// addEventListener/removeEventListener pair to exercise the live-preference-change listener.
function mockMatchMedia(initialMatches) {
    const listeners = new Set();
    const mql = {
        matches: initialMatches,
        addEventListener: (_event, cb) => listeners.add(cb),
        removeEventListener: (_event, cb) => listeners.delete(cb),
    };
    window.matchMedia = vi.fn().mockReturnValue(mql);

    return {
        fireChange(newMatches) {
            mql.matches = newMatches;
            listeners.forEach((cb) => cb());
        },
        listenerCount: () => listeners.size,
    };
}

describe('ThemeSwitcher', () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.className = '';
        document.documentElement.removeAttribute('data-theme');
        document.head.innerHTML = '<meta name="theme-color" content="#ffffff">';
        mockMatchMedia(false);
    });

    afterEach(() => {
        delete window.matchMedia;
    });

    it('renders all three theme buttons with accessible labels', () => {
        render(<ThemeSwitcher />);

        expect(screen.getByRole('button', { name: 'Use light theme' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Use system theme' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Use dark theme' })).toBeInTheDocument();
    });

    it('defaults to dark when localStorage has no saved theme', () => {
        render(<ThemeSwitcher />);

        expect(screen.getByRole('button', { name: 'Use dark theme' }).className).toContain('shadow-sm');
        expect(screen.getByRole('button', { name: 'Use light theme' }).className).not.toContain('shadow-sm');
    });

    it('reads the initial theme from localStorage and highlights it', () => {
        localStorage.setItem('theme', 'light');

        render(<ThemeSwitcher />);

        expect(screen.getByRole('button', { name: 'Use light theme' }).className).toContain('shadow-sm');
        expect(screen.getByRole('button', { name: 'Use dark theme' }).className).not.toContain('shadow-sm');
    });

    it('clicking light removes the dark class, sets data-theme, and updates the meta tag', () => {
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use light theme' }));

        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
        expect(document.querySelector('meta[name=theme-color]').getAttribute('content')).toBe('#ffffff');
        expect(localStorage.getItem('theme')).toBe('light');
        expect(screen.getByRole('button', { name: 'Use light theme' }).className).toContain('shadow-sm');
    });

    it('clicking dark adds the dark class, sets data-theme, and updates the meta tag', () => {
        localStorage.setItem('theme', 'light');
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use dark theme' }));

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(document.querySelector('meta[name=theme-color]').getAttribute('content')).toBe('#101010');
        expect(localStorage.getItem('theme')).toBe('dark');
    });

    it('clicking system applies dark when the OS preference currently matches dark', () => {
        mockMatchMedia(true);
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use system theme' }));

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(localStorage.getItem('theme')).toBe('system');
    });

    it('clicking system applies light when the OS preference currently does not match dark', () => {
        mockMatchMedia(false);
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use system theme' }));

        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    });

    it('re-applies the theme live when the OS preference changes while on system', () => {
        const media = mockMatchMedia(false);
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use system theme' }));
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');

        media.fireChange(true);

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('stops listening for OS preference changes after switching away from system', () => {
        const media = mockMatchMedia(false);
        render(<ThemeSwitcher />);

        fireEvent.click(screen.getByRole('button', { name: 'Use system theme' }));
        expect(media.listenerCount()).toBe(1);

        fireEvent.click(screen.getByRole('button', { name: 'Use light theme' }));
        expect(media.listenerCount()).toBe(0);

        // A stale change event on the old media query object must not resurrect dark mode.
        media.fireChange(true);
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    });
});
