import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AuditLog from './AuditLog';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { permissions: {} } }),
}));

function entry(overrides = {}) {
    return {
        id: 1,
        event: 'updated',
        description: 'updated',
        causerName: 'Jane Admin',
        subjectType: 'Server',
        changes: {},
        createdAt: '2026-09-14T12:00:00Z',
        ...overrides,
    };
}

describe('Team/AuditLog', () => {
    it('shows an empty state when there are no entries', () => {
        render(<AuditLog entries={[]} />);

        expect(screen.getByText('No audit log entries yet.')).toBeInTheDocument();
    });

    it('renders a row for each entry with who, event, and subject', () => {
        render(
            <AuditLog
                entries={[
                    entry({ id: 1, causerName: 'Jane Admin', event: 'updated', subjectType: 'Server' }),
                    entry({ id: 2, causerName: 'John Owner', event: 'member.removed', subjectType: null, description: 'Removed Bob' }),
                ]}
            />,
        );

        expect(screen.getByText('Jane Admin')).toBeInTheDocument();
        expect(screen.getByText('Updated')).toBeInTheDocument();
        expect(screen.getByText('Server')).toBeInTheDocument();

        expect(screen.getByText('John Owner')).toBeInTheDocument();
        expect(screen.getByText('Member Removed')).toBeInTheDocument();
        expect(screen.getByText('Removed Bob')).toBeInTheDocument();
    });

    it('falls back to the raw event string for an unmapped event name', () => {
        render(<AuditLog entries={[entry({ event: 'something.unmapped' })]} />);

        expect(screen.getByText('something.unmapped')).toBeInTheDocument();
    });

    it('shows a before/after diff for a changed attribute', () => {
        render(
            <AuditLog
                entries={[
                    entry({
                        changes: {
                            attributes: { name: 'new-name' },
                            old: { name: 'old-name' },
                        },
                    }),
                ]}
            />,
        );

        const item = screen.getByRole('listitem');
        expect(item).toHaveTextContent('name');
        expect(item).toHaveTextContent('old-name');
        expect(item).toHaveTextContent('new-name');
    });

    it('renders nothing extra for an entry with no attribute changes', () => {
        render(<AuditLog entries={[entry({ changes: {} })]} />);

        // Only the description text should render, no stray diff list.
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });
});
