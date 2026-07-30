import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Index from './Index';

// Manually verified live end-to-end during the 2026-07-23 Storage smoke test (issue #25)
// against the real MinIO instance in this dev stack: the "+ Add" modal against real (and
// intentionally-wrong) S3 credentials, and a storage's "Not Usable" badge state. This suite
// locks that in as automated coverage - the page was previously entirely untested.
// AddStorageModal is mocked out (no dedicated suite exists for it yet, but its own internal
// form logic is a separate concern from this page's own rendering/wiring) so this stays
// focused on Index's own conditional rendering.

const addStorageModalSpy = vi.fn();

vi.mock('../../Components/AddStorageModal', () => ({
    default: (props) => {
        addStorageModalSpy(props);
        return (
            <div data-testid="add-storage-modal">
                <button type="button" onClick={props.onClose}>
                    Close Modal
                </button>
            </div>
        );
    },
}));

function baseStorage(overrides = {}) {
    return {
        uuid: 'storage-uuid-1',
        name: 'my-bucket',
        description: 'Backup storage',
        isUsable: true,
        showUrl: '/storages/storage-uuid-1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        storages: [baseStorage()],
        canCreate: true,
        createUrl: '/storages',
        ...overrides,
    };
}

describe('Storage/Index', () => {
    it('shows the empty state when there are no storages', () => {
        render(<Index {...baseProps({ storages: [] })} />);
        expect(screen.getByText('No storage found.')).toBeInTheDocument();
    });

    it("renders each storage's name, description, and links to its Show page", () => {
        render(<Index {...baseProps()} />);
        expect(screen.getByText('my-bucket')).toBeInTheDocument();
        expect(screen.getByText('Backup storage')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /my-bucket/ })).toHaveAttribute('href', '/storages/storage-uuid-1');
    });

    it('shows the "Not Usable" badge only when isUsable is false', () => {
        const { unmount } = render(<Index {...baseProps({ storages: [baseStorage({ isUsable: true })] })} />);
        expect(screen.queryByText('Not Usable')).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ storages: [baseStorage({ isUsable: false })] })} />);
        expect(screen.getByText('Not Usable')).toBeInTheDocument();
    });

    it('only shows the "+ Add" button when canCreate is true', () => {
        const { unmount } = render(<Index {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...baseProps({ canCreate: true })} />);
        expect(screen.getByRole('button', { name: '+ Add' })).toBeInTheDocument();
    });

    it('opens AddStorageModal with createUrl on "+ Add", and closes it via onClose', () => {
        addStorageModalSpy.mockClear();
        render(<Index {...baseProps()} />);

        expect(screen.queryByTestId('add-storage-modal')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByTestId('add-storage-modal')).toBeInTheDocument();
        expect(addStorageModalSpy).toHaveBeenCalledWith(expect.objectContaining({ createUrl: '/storages' }));

        act(() => screen.getByRole('button', { name: 'Close Modal' }).click());
        expect(screen.queryByTestId('add-storage-modal')).not.toBeInTheDocument();
    });

    it('renders multiple storages independently, each with their own badge state', () => {
        render(
            <Index
                {...baseProps({
                    storages: [
                        baseStorage({ uuid: 's1', name: 'bucket-one', isUsable: true }),
                        baseStorage({ uuid: 's2', name: 'bucket-two', isUsable: false }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('bucket-one')).toBeInTheDocument();
        expect(screen.getByText('bucket-two')).toBeInTheDocument();
        expect(screen.getAllByText('Not Usable')).toHaveLength(1);
    });
});
