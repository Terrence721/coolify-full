import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import Index from './Index';

describe('SharedVariables/Index', () => {
    it('renders the heading and subtitle', () => {
        render(<Index links={[]} />);

        expect(screen.getByRole('heading', { level: 1, name: /Shared Variables/i })).toBeInTheDocument();
        expect(screen.getByText(/Set Team \/ Project \/ Environment \/ Server wide variables\./i)).toBeInTheDocument();
    });

    it('renders all links with correct text and href', () => {
        const links = [
            { href: '/shared-variables/team', title: 'Team', description: 'Team-wide variables' },
            { href: '/shared-variables/project', title: 'Project', description: 'Project-wide variables' },
            { href: '/shared-variables/environment', title: 'Environment', description: 'Environment-wide variables' },
        ];

        render(<Index links={links} />);

        links.forEach((link) => {
            const linkElement = screen.getByRole('link', { name: new RegExp(link.title, 'i') });
            expect(linkElement).toHaveAttribute('href', link.href);
            expect(screen.getByText(link.title)).toBeInTheDocument();
            expect(screen.getByText(link.description)).toBeInTheDocument();
        });
    });

    it('handles empty links array gracefully', () => {
        const { container } = render(<Index links={[]} />);

        expect(screen.getByRole('heading', { level: 1, name: /Shared Variables/i })).toBeInTheDocument();
        expect(screen.getByText(/Set Team \/ Project \/ Environment \/ Server wide variables\./i)).toBeInTheDocument();

        // Should render no links
        const linkElements = container.querySelectorAll('a[class*="coolbox"]');
        expect(linkElements).toHaveLength(0);
    });
});
