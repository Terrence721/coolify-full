import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StoragesTab from './StoragesTab';

// The persistent-storage tab, shared across Application/Database/Service Configuration pages (all
// 3 import it) - same broad blast radius as ResourceTabs.jsx, and the largest untested component
// left in the backlog (433 lines). Real logic: volume/file/directory add-edit-delete, a default-tab
// derivation that depends on which category has entries, typed-confirmation gating on directory
// conversion, and several mutually-exclusive banner states (too-large takes priority over
// read-only) that are easy to get subtly wrong.

const routerPatch = vi.fn();
const routerPost = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, opts) => routerPatch(url, data, opts),
        post: (url, data, opts) => routerPost(url, data, opts),
    },
}));

vi.mock('./PasswordConfirmModal', () => ({
    default: ({ title, onClose }) => (
        <div data-testid="PasswordConfirmModal">
            <div>{title}</div>
            <button type="button" onClick={onClose}>
                close-modal
            </button>
        </div>
    ),
}));

afterEach(() => {
    routerPatch.mockClear();
    routerPost.mockClear();
});

function volume(overrides = {}) {
    return {
        id: 1,
        name: 'pv-1',
        mountPath: '/data',
        hostPath: '',
        isReadOnly: false,
        isFirst: true,
        urls: { update: '/volumes/1', destroy: '/volumes/1' },
        ...overrides,
    };
}

function file(overrides = {}) {
    return {
        id: 1,
        fsPath: '/host/nginx.conf',
        mountPath: '/etc/nginx.conf',
        content: 'server {}',
        isDirectory: false,
        isReadOnly: false,
        isBinary: false,
        isTooLarge: false,
        urls: { update: '/files/1', destroy: '/files/1', load: '/files/1/load', convert: '/files/1/convert' },
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        sections: [{ name: null, volumes: [volume()], files: [] }],
        isService: false,
        canAddMounts: true,
        canUpdate: true,
        storageUrls: { volumeStore: '/volumes', fileStore: '/files', directoryStore: '/directories' },
        sourceDirPlaceholder: '',
        ...overrides,
    };
}

it('shows "No storage found." for a section with no volumes, files, or directories', () => {
    render(<StoragesTab {...baseProps({ sections: [{ name: 'Empty', volumes: [], files: [] }] })} />);
    expect(screen.getByText('No storage found.')).toBeInTheDocument();
});

it('hides the Add dropdown when canAddMounts is false, even if canUpdate is true', () => {
    render(<StoragesTab {...baseProps({ canAddMounts: false })} />);
    expect(screen.queryByRole('button', { name: '+ Add ▾' })).not.toBeInTheDocument();
});

it('shows the docker-compose read-only warning only when isService is true', () => {
    const { rerender } = render(<StoragesTab {...baseProps({ isService: false })} />);
    expect(screen.queryByText(/volume mounts are read-only in the dashboard/)).not.toBeInTheDocument();

    rerender(<StoragesTab {...baseProps({ isService: true })} />);
    expect(screen.getByText(/volume mounts are read-only in the dashboard/)).toBeInTheDocument();
});

describe('tab selection', () => {
    it('defaults to the Volumes tab when volumes exist', () => {
        render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [volume()], files: [file({ isDirectory: true })] }] })} />);
        expect(screen.getByDisplayValue('/data')).toBeInTheDocument();
    });

    it('defaults to the Files tab when there are no volumes but there are files', () => {
        render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [file()] }] })} />);
        expect(screen.getByDisplayValue('/etc/nginx.conf')).toBeInTheDocument();
    });

    it('disables a tab button for an empty category and switches tabs on click', () => {
        render(
            <StoragesTab
                {...baseProps({
                    sections: [{ name: null, volumes: [volume()], files: [file({ id: 2, isDirectory: true, fsPath: '/etc/dir' })] }],
                })}
            />,
        );

        expect(screen.getByRole('button', { name: 'Files (0)' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Directories (1)' }));
        expect(screen.getByDisplayValue('/etc/dir')).toBeInTheDocument();
    });
});

describe('VolumeCard', () => {
    it('shows the read-only banner and hides Update/Delete for a read-only volume', () => {
        render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [volume({ isReadOnly: true })], files: [] }] })} />);
        expect(screen.getByText(/read-only in the UI/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Update' })).not.toBeInTheDocument();
    });

    it('submits the edited fields via router.patch to the volume update URL', () => {
        render(<StoragesTab {...baseProps()} />);
        fireEvent.change(screen.getByDisplayValue('pv-1'), { target: { value: 'pv-renamed' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update' }));

        expect(routerPatch).toHaveBeenCalledWith('/volumes/1', { name: 'pv-renamed', mount_path: '/data', host_path: '' }, { preserveScroll: true });
    });

    it('opens the delete-confirmation modal from the Delete button', () => {
        render(<StoragesTab {...baseProps()} />);
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(screen.getByTestId('PasswordConfirmModal')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'close-modal' }));
        expect(screen.queryByTestId('PasswordConfirmModal')).not.toBeInTheDocument();
    });
});

describe('FileCard', () => {
    function renderWithFile(fileOverrides = {}) {
        return render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [file(fileOverrides)] }] })} />);
    }

    it('shows the too-large banner and not the read-only banner when both are true', () => {
        renderWithFile({ isTooLarge: true, isReadOnly: true });
        expect(screen.getByText(/exceeds 5 MB/)).toBeInTheDocument();
        expect(screen.queryByText(/mounted as read-only/)).not.toBeInTheDocument();
    });

    it('shows the read-only banner when only read-only is true', () => {
        renderWithFile({ isReadOnly: true });
        expect(screen.getByText(/mounted as read-only/)).toBeInTheDocument();
    });

    it('hides "Load from server" for a directory, shows it for a file', () => {
        const { rerender } = renderWithFile();
        expect(screen.getByRole('button', { name: 'Load from server' })).toBeInTheDocument();

        rerender(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [file({ isDirectory: true })] }] })} />);
        expect(screen.queryByRole('button', { name: 'Load from server' })).not.toBeInTheDocument();
    });

    it('Load from server posts to the file load URL', () => {
        renderWithFile();
        fireEvent.click(screen.getByRole('button', { name: 'Load from server' }));
        expect(routerPost).toHaveBeenCalledWith('/files/1/load', {}, { preserveScroll: true });
    });

    it('hides the convert button for a binary or too-large file', () => {
        const { rerender } = renderWithFile({ isBinary: true });
        expect(screen.queryByRole('button', { name: 'Convert to directory' })).not.toBeInTheDocument();

        rerender(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [file({ isTooLarge: true })] }] })} />);
        expect(screen.queryByRole('button', { name: 'Convert to directory' })).not.toBeInTheDocument();
    });

    it('gates the convert confirmation on typing the exact fsPath, then posts to the convert URL', () => {
        renderWithFile();
        fireEvent.click(screen.getByRole('button', { name: 'Convert to directory' }));

        const confirmBtn = screen.getAllByRole('button', { name: 'Convert to directory' })[1];
        expect(confirmBtn).toBeDisabled();

        const input = screen.getByPlaceholderText('Type "/host/nginx.conf" to confirm conversion');
        fireEvent.change(input, { target: { value: '/host/nginx.conf' } });
        expect(confirmBtn).not.toBeDisabled();

        fireEvent.click(confirmBtn);
        expect(routerPost).toHaveBeenCalledWith('/files/1/convert', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('makes the content textarea read-only when not editable, and hides Save', () => {
        renderWithFile({ isReadOnly: true });
        expect(screen.getByTitle(/Load from server/)).toHaveAttribute('readonly');
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });

    it('saves edited content via router.patch to the file update URL', () => {
        renderWithFile();
        fireEvent.change(screen.getByTitle(/Load from server/), { target: { value: 'server { listen 80; }' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(routerPatch).toHaveBeenCalledWith('/files/1', { content: 'server { listen 80; }' }, { preserveScroll: true });
    });
});

describe('AddDropdown', () => {
    it('opens the Add Volume Mount modal and submits to storageUrls.volumeStore', () => {
        render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [] }] })} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add ▾' }));
        fireEvent.click(screen.getByRole('button', { name: 'Volume Mount' }));

        fireEvent.change(screen.getByPlaceholderText('pv-name'), { target: { value: 'new-pv' } });
        fireEvent.change(screen.getByPlaceholderText('/tmp/root'), { target: { value: '/data' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));

        expect(routerPost).toHaveBeenCalledWith(
            '/volumes',
            { name: 'new-pv', host_path: '', mount_path: '/data' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('opens the Add Directory Mount modal and submits to storageUrls.directoryStore', () => {
        render(<StoragesTab {...baseProps({ sections: [{ name: null, volumes: [], files: [] }] })} />);
        fireEvent.click(screen.getByRole('button', { name: '+ Add ▾' }));
        fireEvent.click(screen.getByRole('button', { name: 'Directory Mount' }));

        fireEvent.change(screen.getByLabelText('Source Directory (host)'), { target: { value: '/etc/nginx' } });
        fireEvent.change(screen.getByPlaceholderText('/etc/nginx'), { target: { value: '/etc/app' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));

        expect(routerPost).toHaveBeenCalledWith(
            '/directories',
            { source: '/etc/nginx', destination: '/etc/app' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
