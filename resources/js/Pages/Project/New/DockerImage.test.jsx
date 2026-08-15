import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import DockerImage from './DockerImage';

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

describe('Project/New/DockerImage', () => {
    const mockSubmitUrl = '/projects/applications/create';

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and form', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('heading', { level: 1, name: /New Resource/i })).toBeInTheDocument();
        expect(screen.getByText(/Docker Image/)).toBeInTheDocument();
    });

    it('renders Image Name field', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        const imageName = screen.getByLabelText(/Image Name/);
        expect(imageName).toBeInTheDocument();
        expect(imageName).toBeRequired();
        expect(imageName).toHaveAttribute('placeholder', 'nginx');
    });

    it('renders Tag field', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        const imageTag = screen.getByLabelText(/Tag/);
        expect(imageTag).toBeInTheDocument();
        expect(imageTag).toHaveAttribute('placeholder', 'latest');
        expect(imageTag).not.toBeRequired();
    });

    it('renders SHA256 Digest field', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        const imageSha = screen.getByLabelText(/SHA256 Digest/);
        expect(imageSha).toBeInTheDocument();
        expect(imageSha).toHaveAttribute('placeholder', 'sha256:...');
        expect(imageSha).not.toBeRequired();
    });

    it('renders OR label between Tag and SHA256 fields', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        expect(screen.getByText(/OR/)).toBeInTheDocument();
    });

    it('renders Save button', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });

    it('has proper form field structure', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        const form = screen.getByRole('button', { name: /Save/i }).closest('form');
        expect(form).toBeInTheDocument();

        const imageName = screen.getByLabelText(/Image Name/);
        expect(imageName).toHaveAttribute('id', 'docker-image-name');
        expect(imageName).toHaveAttribute('name', 'docker-image-name');
    });

    it('starts with empty form fields', () => {
        render(<DockerImage submitUrl={mockSubmitUrl} />);

        expect(screen.getByLabelText(/Image Name/)).toHaveValue('');
        expect(screen.getByLabelText(/Tag/)).toHaveValue('');
        expect(screen.getByLabelText(/SHA256 Digest/)).toHaveValue('');
    });
});
