import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import DomainConflictModal from './DomainConflictModal';

// Untested despite tying to real bug history from the migration: Project/Service/Index never
// actually exposed domainConflicts/showDomainConflictModal as Inertia props (this modal had
// never been reachable in production), and checkDomainUsage() crashed on a null fqdn in 2 of
// its 4 conflict-detection loops (todo.md's Backend bug fixes log, 2026-07-12). This suite
// covers the render logic itself: the null/empty guard, the instance-vs-link resource
// rendering split, default vs. custom consequences, and the cancel/confirm actions.

function conflict(overrides = {}) {
    return {
        domain: 'example.com',
        resource_name: 'my-app',
        resource_type: 'application',
        resource_link: '/project/proj/environment/env/application/app-uuid',
        ...overrides,
    };
}

describe('DomainConflictModal', () => {
    it('renders nothing when conflicts is null', () => {
        const { container } = render(<DomainConflictModal conflicts={null} onCancel={vi.fn()} onConfirm={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when conflicts is an empty array', () => {
        const { container } = render(<DomainConflictModal conflicts={[]} onCancel={vi.fn()} onConfirm={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('lists each conflict with its domain and resource type', () => {
        render(
            <DomainConflictModal
                conflicts={[conflict({ domain: 'a.com' }), conflict({ domain: 'b.com', resource_type: 'service' })]}
                onCancel={vi.fn()}
                onConfirm={vi.fn()}
            />,
        );

        expect(screen.getByText('a.com')).toBeInTheDocument();
        expect(screen.getByText('b.com')).toBeInTheDocument();
        expect(screen.getByText(/\(service\)/)).toBeInTheDocument();
    });

    it('renders a non-instance resource as a link to its resource page', () => {
        render(
            <DomainConflictModal
                conflicts={[conflict({ resource_type: 'application', resource_name: 'my-app', resource_link: '/app/uuid' })]}
                onCancel={vi.fn()}
                onConfirm={vi.fn()}
            />,
        );

        const link = screen.getByRole('link', { name: 'my-app' });
        expect(link).toHaveAttribute('href', '/app/uuid');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('renders an instance-type resource as plain text, not a link', () => {
        render(
            <DomainConflictModal
                conflicts={[conflict({ resource_type: 'instance', resource_name: 'Coolify Dashboard' })]}
                onCancel={vi.fn()}
                onConfirm={vi.fn()}
            />,
        );

        expect(screen.getByText('Coolify Dashboard')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Coolify Dashboard' })).not.toBeInTheDocument();
    });

    it('shows the default consequences list when none is provided', () => {
        render(<DomainConflictModal conflicts={[conflict()]} onCancel={vi.fn()} onConfirm={vi.fn()} />);

        expect(screen.getByText('SSL certificates might not work correctly')).toBeInTheDocument();
        expect(screen.getByText('Routing behavior will be unpredictable')).toBeInTheDocument();
    });

    it('shows custom consequences when provided, instead of the default list', () => {
        render(
            <DomainConflictModal
                conflicts={[conflict()]}
                onCancel={vi.fn()}
                onConfirm={vi.fn()}
                consequences={['A very specific custom consequence']}
            />,
        );

        expect(screen.getByText('A very specific custom consequence')).toBeInTheDocument();
        expect(screen.queryByText('SSL certificates might not work correctly')).not.toBeInTheDocument();
    });

    it('calls onCancel when the Cancel button is clicked', () => {
        const onCancel = vi.fn();
        render(<DomainConflictModal conflicts={[conflict()]} onCancel={onCancel} onConfirm={vi.fn()} />);

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());

        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('calls onCancel when the close (X) button is clicked', () => {
        const onCancel = vi.fn();
        render(<DomainConflictModal conflicts={[conflict()]} onCancel={onCancel} onConfirm={vi.fn()} />);

        act(() => screen.getByText('✕').click());

        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('calls onCancel when the backdrop is clicked', () => {
        const onCancel = vi.fn();
        const { container } = render(<DomainConflictModal conflicts={[conflict()]} onCancel={onCancel} onConfirm={vi.fn()} />);

        act(() => container.querySelector('.backdrop-blur-xs').click());

        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('calls onConfirm when "I understand, proceed anyway" is clicked', () => {
        const onConfirm = vi.fn();
        render(<DomainConflictModal conflicts={[conflict()]} onCancel={vi.fn()} onConfirm={onConfirm} />);

        act(() => screen.getByRole('button', { name: 'I understand, proceed anyway' }).click());

        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('disables the proceed button while confirming', () => {
        render(<DomainConflictModal conflicts={[conflict()]} onCancel={vi.fn()} onConfirm={vi.fn()} confirming />);

        expect(screen.getByRole('button', { name: 'I understand, proceed anyway' })).toBeDisabled();
    });
});
