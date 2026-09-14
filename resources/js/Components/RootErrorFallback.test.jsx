import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RootErrorFallback from './RootErrorFallback';

// jsdom's window.location.reload() is a stubbed no-op (and non-configurable, so vi.spyOn can't
// redefine it directly) - replacing the whole object is the established pattern in this codebase,
// see hooks/reloadOnBFCacheRestore.test.jsx.
const originalLocation = window.location;

describe('RootErrorFallback', () => {
    beforeEach(() => {
        window.location = { ...originalLocation, reload: vi.fn() };
    });

    afterEach(() => {
        window.location = originalLocation;
        vi.restoreAllMocks();
    });

    it('renders a message telling the user to reload', () => {
        render(<RootErrorFallback />);

        expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
    });

    it('reloads the page when the Reload button is clicked', () => {
        render(<RootErrorFallback />);

        fireEvent.click(screen.getByText('Reload'));

        expect(window.location.reload).toHaveBeenCalled();
    });
});
