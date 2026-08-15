import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import AddStorageModal from './AddStorageModal';

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

describe('AddStorageModal', () => {
    let mockOnClose;
    const mockCreateUrl = '/storage/create';

    beforeEach(() => {
        mockOnClose = vi.fn();
    });

    it('renders modal with title and close button', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByRole('heading', { level: 3, name: /New S3 Storage/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /✕/i })).toBeInTheDocument();
    });

    it('renders all required form fields', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByLabelText(/Name/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Description/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Region/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Access Key/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Secret Key/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Bucket/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Endpoint/)).toBeInTheDocument();
    });

    it('renders save button', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });

    it('has default region value set to us-east-1', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const regionInput = screen.getByLabelText(/Region/);
        expect(regionInput).toHaveValue('us-east-1');
    });

    it('secret field is password type', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const secretInput = screen.getByLabelText(/Secret Key/);
        expect(secretInput).toHaveAttribute('type', 'password');
    });

    it('calls onClose when close button is clicked', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const closeButton = screen.getByRole('button', { name: /✕/i });
        fireEvent.click(closeButton);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when backdrop is clicked', () => {
        const { container } = render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const backdrop = container.querySelector('.absolute.inset-0');
        fireEvent.click(backdrop);

        expect(mockOnClose).toHaveBeenCalledTimes(1);
    });

    it('has required fields properly marked', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        expect(screen.getByLabelText(/Name/)).toBeRequired();
        expect(screen.getByLabelText(/Region/)).toBeRequired();
        expect(screen.getByLabelText(/Access Key/)).toBeRequired();
        expect(screen.getByLabelText(/Secret Key/)).toBeRequired();
        expect(screen.getByLabelText(/Bucket/)).toBeRequired();
        expect(screen.getByLabelText(/Description/)).not.toBeRequired();
        expect(screen.getByLabelText(/Endpoint/)).not.toBeRequired();
    });

    it('endpoint field has placeholder text', () => {
        render(<AddStorageModal createUrl={mockCreateUrl} onClose={mockOnClose} />);

        const endpointInput = screen.getByLabelText(/Endpoint/);
        expect(endpointInput).toHaveAttribute('placeholder', 'https://s3.us-east-1.amazonaws.com');
    });
});
