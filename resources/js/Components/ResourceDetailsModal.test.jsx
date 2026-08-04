import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ResourceDetailsModal from './ResourceDetailsModal';

// Real logic: the identifier sections (Resource/Environment/Project/Server) are filtered to only
// those with real data (data && data.uuid) - a standalone resource missing an Environment/Project
// wouldn't have that section render at all. Stack Applications/Databases sections only render
// when their respective arrays are non-empty. Each identifier row copies to the clipboard and
// shows a timed "Copied!" label that reverts after 1.5s.

function baseDetails(overrides = {}) {
    return {
        resource: { name: 'my-app', uuid: 'res-uuid-1' },
        environment: { name: 'production', uuid: 'env-uuid-1' },
        project: { name: 'my-project', uuid: 'proj-uuid-1' },
        server: { name: 'main-server', uuid: 'srv-uuid-1' },
        stackApplications: [],
        stackDatabases: [],
        ...overrides,
    };
}

describe('ResourceDetailsModal', () => {
    beforeEach(() => {
        Object.assign(navigator, { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders a section for every identifier that has real data', () => {
        render(<ResourceDetailsModal details={baseDetails()} onClose={() => {}} />);
        expect(screen.getByText('Resource')).toBeInTheDocument();
        expect(screen.getByText('Environment')).toBeInTheDocument();
        expect(screen.getByText('Project')).toBeInTheDocument();
        expect(screen.getByText('Server')).toBeInTheDocument();
    });

    it('omits sections whose data is missing or has no uuid', () => {
        render(<ResourceDetailsModal details={baseDetails({ environment: null, project: { name: 'no-uuid' } })} onClose={() => {}} />);
        expect(screen.queryByText('Environment')).not.toBeInTheDocument();
        expect(screen.queryByText('Project')).not.toBeInTheDocument();
        expect(screen.getByText('Resource')).toBeInTheDocument();
        expect(screen.getByText('Server')).toBeInTheDocument();
    });

    it('hides the Stack Applications/Databases sections when both are empty', () => {
        render(<ResourceDetailsModal details={baseDetails()} onClose={() => {}} />);
        expect(screen.queryByText('Stack Applications')).not.toBeInTheDocument();
        expect(screen.queryByText('Stack Databases')).not.toBeInTheDocument();
    });

    it('shows Stack Applications/Databases when present', () => {
        render(
            <ResourceDetailsModal
                details={baseDetails({
                    stackApplications: [{ name: 'app-1', uuid: 'app-uuid-1' }],
                    stackDatabases: [{ name: 'db-1', uuid: 'db-uuid-1' }],
                })}
                onClose={() => {}}
            />,
        );
        expect(screen.getByText('Stack Applications')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /app-1: app-uuid-1/ })).toBeInTheDocument();
        expect(screen.getByText('Stack Databases')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /db-1: db-uuid-1/ })).toBeInTheDocument();
    });

    it('copies the row text to the clipboard and shows a timed "Copied!" label', () => {
        vi.useFakeTimers();
        render(<ResourceDetailsModal details={baseDetails()} onClose={() => {}} />);

        const row = screen.getByRole('button', { name: /Name: my-app/ });
        act(() => row.click());

        expect(navigator.clipboard.writeText).toHaveBeenCalledWith('my-app');
        expect(screen.getByText('Copied!')).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(1500));
        expect(screen.queryByText('Copied!')).not.toBeInTheDocument();
    });

    it('closes when the backdrop is clicked, but not when the modal content is clicked', () => {
        const onClose = vi.fn();
        const { container } = render(<ResourceDetailsModal details={baseDetails()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Identifiers for this resource. Read-only'));
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.click(container.querySelector('.bg-black\\/20'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('closes via the X button', () => {
        const onClose = vi.fn();
        render(<ResourceDetailsModal details={baseDetails()} onClose={onClose} />);

        fireEvent.click(screen.getByRole('button', { name: '✕' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
