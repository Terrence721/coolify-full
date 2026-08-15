import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import Index from './Index';

describe('SharedVariables/Project/Index', () => {
    it('renders heading and subtitle', () => {
        render(<Index projects={[]} />);

        expect(screen.getByRole('heading', { level: 1, name: /Projects/i })).toBeInTheDocument();
        expect(screen.getByText(/List of your projects\./)).toBeInTheDocument();
    });

    it('shows empty state when no projects', () => {
        render(<Index projects={[]} />);

        expect(screen.getByText(/No project found\./)).toBeInTheDocument();
    });

    it('renders all projects with correct links and descriptions', () => {
        const projects = [
            { href: '/projects/1', name: 'Project A', description: 'First project' },
            { href: '/projects/2', name: 'Project B', description: 'Second project' },
        ];

        render(<Index projects={projects} />);

        projects.forEach((project) => {
            const link = screen.getByRole('link', { name: new RegExp(project.name, 'i') });
            expect(link).toHaveAttribute('href', project.href);
            expect(screen.getByText(project.description)).toBeInTheDocument();
        });
    });
});
