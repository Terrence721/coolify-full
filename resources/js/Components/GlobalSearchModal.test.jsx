import { render, screen, waitFor } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GlobalSearchModal from './GlobalSearchModal';

// Regression coverage for GlobalSearchModal.jsx - the ⌘K/`/` command palette. Untested until now
// despite being one of the largest, most logic-heavy components in the app. Also locks in the
// selectedIndex -> selectedIndexRef fix (see todo.md's ESLint cleanup pass): it was useState
// written 7 times but never read, forcing a wasted re-render on every arrow-key press since the
// value was never actually rendered - navigation works entirely through imperative DOM focus.

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
}));

vi.mock('./AddProjectModal', () => ({ default: () => <div data-testid="add-project-modal" /> }));
vi.mock('./AddServerModal', () => ({ default: () => <div data-testid="add-server-modal" /> }));
vi.mock('./AddStorageModal', () => ({ default: () => <div data-testid="add-storage-modal" /> }));
vi.mock('./AddTeamModal', () => ({ default: () => <div data-testid="add-team-modal" /> }));
vi.mock('./PrivateKeyCreateModal', () => ({ default: () => <div data-testid="private-key-modal" /> }));

const SEARCH_DATA = {
    searchableItems: [
        { type: 'application', id: 1, name: 'my-app', search_text: 'my-app production application', link: '/app/1' },
        { type: 'server', id: 2, name: 'prod-server', search_text: 'prod-server server hetzner', link: '/server/2' },
    ],
    creatableItems: [{ type: 'team', name: 'Team', description: 'Create a new team', component: 'team.create' }],
    createUrls: { team: '/team' },
};

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

async function openViaSlash() {
    await act(async () => {
        document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
    });
    await waitFor(() => expect(screen.getByPlaceholderText(/Search resources/)).toBeInTheDocument());
}

describe('GlobalSearchModal', () => {
    beforeEach(() => {
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(SEARCH_DATA) }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('opens on "/" when not focused in a text field', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        expect(screen.getByPlaceholderText(/Search resources/)).toBeInTheDocument();
    });

    it('does not open on "/" while an input or textarea is focused', async () => {
        render(
            <div>
                <input data-testid="other-input" />
                <GlobalSearchModal />
            </div>,
        );

        const otherInput = screen.getByTestId('other-input');
        otherInput.focus();
        act(() => {
            otherInput.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
        });

        expect(screen.queryByPlaceholderText(/Search resources/)).not.toBeInTheDocument();
    });

    it('opens on Cmd+K', async () => {
        render(<GlobalSearchModal />);
        await act(async () => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }));
        });

        await waitFor(() => expect(screen.getByPlaceholderText(/Search resources/)).toBeInTheDocument());
    });

    it('closes on Escape when the query is empty', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        });

        expect(screen.queryByPlaceholderText(/Search resources/)).not.toBeInTheDocument();
    });

    it('filters existing resources by search_text substring', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        const input = screen.getByPlaceholderText(/Search resources/);
        act(() => typeInto(input, 'hetzner'));

        await waitFor(() => expect(screen.getByText('prod-server')).toBeInTheDocument());
        expect(screen.queryByText('my-app')).not.toBeInTheDocument();
    });

    it('auto-navigates on an exact-match create command', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        const input = screen.getByPlaceholderText(/Search resources/);
        act(() => typeInto(input, 'new team'));

        await waitFor(() => expect(screen.getByTestId('add-team-modal')).toBeInTheDocument());
    });

    it('moves focus to the next result on ArrowDown, and back on ArrowUp', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        const input = screen.getByPlaceholderText(/Search resources/);
        act(() => typeInto(input, 'server'));
        await waitFor(() => expect(screen.getByText('prod-server')).toBeInTheDocument());

        const results = document.querySelectorAll('.search-result-item');
        expect(results).toHaveLength(1);

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        });
        expect(document.activeElement).toBe(results[0]);

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
        });
        expect(document.activeElement).toBe(input);
    });

    it('remembers position across consecutive ArrowDown presses (not just the first)', async () => {
        render(<GlobalSearchModal />);
        await openViaSlash();

        const input = screen.getByPlaceholderText(/Search resources/);
        act(() => typeInto(input, 'o'));
        await waitFor(() => expect(screen.getByText('my-app')).toBeInTheDocument());
        await waitFor(() => expect(screen.getByText('prod-server')).toBeInTheDocument());

        const results = document.querySelectorAll('.search-result-item');
        expect(results.length).toBeGreaterThanOrEqual(2);

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        });
        expect(document.activeElement).toBe(results[0]);

        act(() => {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        });
        expect(document.activeElement).toBe(results[1]);
    });
});
