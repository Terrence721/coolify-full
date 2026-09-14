import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ErrorBoundary from './ErrorBoundary';

function Bomb() {
    throw new Error('boom');
}

describe('ErrorBoundary', () => {
    // React logs the caught error to the console by default even with a boundary in place -
    // silence it here so this test's own intentional throw doesn't spam real test output.
    beforeEach(() => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders children normally when nothing throws', () => {
        render(
            <ErrorBoundary fallback={<div>fallback</div>}>
                <div>real content</div>
            </ErrorBoundary>,
        );

        expect(screen.getByText('real content')).toBeInTheDocument();
        expect(screen.queryByText('fallback')).not.toBeInTheDocument();
    });

    it('renders the fallback instead of blanking the page when a child throws during render', () => {
        render(
            <ErrorBoundary fallback={<div>fallback</div>}>
                <Bomb />
            </ErrorBoundary>,
        );

        expect(screen.getByText('fallback')).toBeInTheDocument();
    });

    it('logs the caught error rather than swallowing it silently', () => {
        render(
            <ErrorBoundary fallback={<div>fallback</div>}>
                <Bomb />
            </ErrorBoundary>,
        );

        expect(console.error).toHaveBeenCalledWith('ErrorBoundary caught a render error:', expect.any(Error), expect.anything());
    });
});
