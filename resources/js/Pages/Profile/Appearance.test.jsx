import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Appearance from './Appearance';

// The per-browser (localStorage-backed) appearance settings page: theme (light/system/dark),
// max page width (center/full), and interface zoom (100%/90%). Real logic: three independently
// persisted settings read from localStorage on mount, an own (not shared with ThemeSwitcher.jsx)
// applyTheme() implementation for the theme option, and a deliberate asymmetry - changing theme
// applies live via applyTheme(), while changing width/zoom persists to localStorage and then
// forces a full window.location.reload() instead, since those two aren't handled by any live
// client-side re-render path.

function mockMatchMedia(initialMatches) {
    window.matchMedia = vi.fn().mockReturnValue({ matches: initialMatches, addEventListener: vi.fn(), removeEventListener: vi.fn() });
}

const originalLocation = window.location;

beforeEach(() => {
    localStorage.clear();
    document.documentElement.className = '';
    document.head.innerHTML = '<meta name="theme-color" content="#ffffff">';
    mockMatchMedia(false);
    window.location = { ...originalLocation, reload: vi.fn() };
});

afterEach(() => {
    delete window.matchMedia;
    window.location = originalLocation;
    vi.restoreAllMocks();
});

it('defaults to dark/full/100% when localStorage has nothing saved', () => {
    render(<Appearance />);

    expect(screen.getByRole('button', { name: 'Use dark theme' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use light theme' })).not.toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use full width' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use centered width' })).not.toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use 100 percent zoom' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use 90 percent zoom' })).not.toHaveClass('border-coollabs');
    expect(document.documentElement).toHaveClass('dark');
});

it('reads persisted settings from localStorage and highlights the matching buttons', () => {
    localStorage.setItem('theme', 'light');
    localStorage.setItem('pageWidth', 'center');
    localStorage.setItem('zoom', '90');

    render(<Appearance />);

    expect(screen.getByRole('button', { name: 'Use light theme' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use dark theme' })).not.toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use centered width' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use full width' })).not.toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use 90 percent zoom' })).toHaveClass('border-coollabs');
    expect(screen.getByRole('button', { name: 'Use 100 percent zoom' })).not.toHaveClass('border-coollabs');
    expect(document.documentElement).not.toHaveClass('dark');
});

it('applies dark/light directly and persists, without reloading the page', () => {
    render(<Appearance />);

    fireEvent.click(screen.getByRole('button', { name: 'Use light theme' }));

    expect(localStorage.getItem('theme')).toBe('light');
    expect(document.documentElement).not.toHaveClass('dark');
    expect(document.querySelector('meta[name=theme-color]')).toHaveAttribute('content', '#ffffff');
    expect(window.location.reload).not.toHaveBeenCalled();
});

it('resolves system theme against the OS preference on mount', () => {
    mockMatchMedia(true);
    localStorage.setItem('theme', 'system');

    render(<Appearance />);

    expect(document.documentElement).toHaveClass('dark');
    expect(document.querySelector('meta[name=theme-color]')).toHaveAttribute('content', '#101010');
});

it('persists and reloads the page when changing page width', () => {
    render(<Appearance />);

    fireEvent.click(screen.getByRole('button', { name: 'Use centered width' }));

    expect(localStorage.getItem('pageWidth')).toBe('center');
    expect(window.location.reload).toHaveBeenCalledTimes(1);
});

it('persists and reloads the page when changing zoom', () => {
    render(<Appearance />);

    fireEvent.click(screen.getByRole('button', { name: 'Use 90 percent zoom' }));

    expect(localStorage.getItem('zoom')).toBe('90');
    expect(window.location.reload).toHaveBeenCalledTimes(1);
});
