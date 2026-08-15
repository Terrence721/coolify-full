import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import AddTeamModal from './AddTeamModal';

vi.mock('@inertiajs/react', () => ({
    useForm: vi.fn((initialData) => {
        const data = { ...initialData };
        return {
            data,
            setData: (key, value) => {
                data[key] = value;
            },
            post: vi.fn(),
            processing: false,
            errors: {},
        };
    }),
}));

describe('AddTeamModal', () => {
    let mockOnClose;
    const mockCreateUrl = '/teams/create';

    beforeEach(() => {
        mockOnClose = vi.fn();
    });

    it('renders modal with title and close button', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByRole('heading', { level: 3, name: /New Team/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /✕/i })).toBeInTheDocument();
    });

    it('renders name and description form fields', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByLabelText(/Name/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Description/)).toBeInTheDocument();
    });

    it('renders save button', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });

    it('name field is required', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const nameInput = screen.getByLabelText(/Name/);
        expect(nameInput).toBeRequired();
    });

    it('description field is optional', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const descriptionInput = screen.getByLabelText(/Description/);
        expect(descriptionInput).not.toBeRequired();
    });

    it('calls onClose when close button is clicked', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const closeButton = screen.getByRole('button', { name: /✕/i });
        fireEvent.click(closeButton);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when backdrop is clicked', () => {
        const { container } = render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const backdrop = container.querySelector('.absolute.inset-0');
        fireEvent.click(backdrop);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('has proper form structure and accessibility', () => {
        render(<AddTeamModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const form = screen.getByRole('button', { name: /Save/i }).closest('form');
        expect(form).toBeInTheDocument();

        const nameInput = screen.getByLabelText(/Name/);
        expect(nameInput).toHaveAttribute('id', 'add-team-name');
        expect(nameInput).toHaveAttribute('name', 'add-team-name');

        const descriptionInput = screen.getByLabelText(/Description/);
        expect(descriptionInput).toHaveAttribute('id', 'add-team-description');
        expect(descriptionInput).toHaveAttribute('name', 'add-team-description');
    });
});
