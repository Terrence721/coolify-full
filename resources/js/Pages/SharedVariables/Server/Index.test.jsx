import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import Index from './Index';

describe('SharedVariables/Server/Index', () => {
    it('renders heading and subtitle', () => {
        render(<Index servers={[]} />);

        expect(screen.getByRole('heading', { level: 1, name: /Servers/i })).toBeInTheDocument();
        expect(screen.getByText(/List of your servers\./)).toBeInTheDocument();
    });

    it('shows empty state when no servers', () => {
        render(<Index servers={[]} />);

        expect(screen.getByText(/No server found\./)).toBeInTheDocument();
    });

    it('renders all servers with correct links and descriptions', () => {
        const servers = [
            { href: '/servers/1', name: 'Server A', description: 'Production server' },
            { href: '/servers/2', name: 'Server B', description: 'Staging server' },
        ];

        render(<Index servers={servers} />);

        servers.forEach((server) => {
            const link = screen.getByRole('link', { name: new RegExp(server.name, 'i') });
            expect(link).toHaveAttribute('href', server.href);
            expect(screen.getByText(server.description)).toBeInTheDocument();
        });
    });
});
