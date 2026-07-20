import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Toast from './Toast';

// Regression coverage for the bug documented in Toast.jsx's own docblock and todo.md's
// "Cleanup opportunities" section: window.toast() silently no-op'd across the entire app
// because nothing on the Inertia side ever defined it. These tests assert the contract
// every real call site (AppLayout, ServerNavbar, TerminalWindow, Server/Proxy.jsx) depends
// on: window.toast becomes a real function once <Toast /> mounts, and stops being one once
// it unmounts - so a future regression of the same shape fails a test, not just a live click.

describe('Toast', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        delete window.toast;
    });

    it('renders nothing before any toast is triggered', () => {
        const { container } = render(<Toast />);
        expect(container).toBeEmptyDOMElement();
    });

    it('defines window.toast on mount and removes it on unmount', () => {
        const { unmount } = render(<Toast />);
        expect(typeof window.toast).toBe('function');

        unmount();
        expect(window.toast).toBeUndefined();
    });

    it('renders a toast with the title and description passed to window.toast()', () => {
        render(<Toast />);

        act(() => {
            window.toast('Success', { type: 'success', description: 'Team updated.' });
        });

        expect(screen.getByText('Success')).toBeInTheDocument();
        expect(screen.getByText('Team updated.')).toBeInTheDocument();
    });

    it('applies the type-specific color class, falling back to default for an unknown type', () => {
        render(<Toast />);

        act(() => {
            window.toast('Heads up', { type: 'not-a-real-type' });
        });

        expect(screen.getByText('Heads up')).toHaveClass('text-neutral-800');
    });

    it('dismisses a toast when clicked', () => {
        render(<Toast />);

        act(() => {
            window.toast('Click me');
        });
        expect(screen.getByText('Click me')).toBeInTheDocument();

        act(() => {
            screen.getByRole('alert').click();
        });
        expect(screen.queryByText('Click me')).not.toBeInTheDocument();
    });

    it('auto-dismisses after 4 seconds', () => {
        render(<Toast />);

        act(() => {
            window.toast('Fading out');
        });
        expect(screen.getByText('Fading out')).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(4000);
        });
        expect(screen.queryByText('Fading out')).not.toBeInTheDocument();
    });
});
