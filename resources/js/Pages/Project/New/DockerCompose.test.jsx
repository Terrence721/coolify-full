import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import DockerCompose from './DockerCompose';

vi.mock('../../../Components/MonacoEditor', () => ({
    default: ({ value, onChange, language }) => (
        <textarea
            data-testid="monaco-editor"
            data-language={language}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder="Enter Docker Compose YAML..."
        />
    ),
}));

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

describe('Project/New/DockerCompose', () => {
    const mockSubmitUrl = '/projects/services/create';

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and form', () => {
        render(<DockerCompose defaultDockerComposeRaw="" submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('heading', { level: 1, name: /New Resource/i })).toBeInTheDocument();
        expect(screen.getByText(/Docker Compose/)).toBeInTheDocument();
    });

    it('renders Monaco editor with YAML language', () => {
        render(<DockerCompose defaultDockerComposeRaw="" submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toBeInTheDocument();
        expect(editor).toHaveAttribute('data-language', 'yaml');
    });

    it('renders Save button', () => {
        render(<DockerCompose defaultDockerComposeRaw="" submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });

    it('initializes with default Docker Compose content', () => {
        const defaultContent = 'version: "3"\nservices:\n  web:\n    image: nginx';

        render(<DockerCompose defaultDockerComposeRaw={defaultContent} submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toHaveValue(defaultContent);
    });

    it('starts with empty content when no default provided', () => {
        render(<DockerCompose submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toHaveValue('');
    });

    it('has required form structure', () => {
        render(<DockerCompose defaultDockerComposeRaw="" submitUrl={mockSubmitUrl} />);

        const form = screen.getByRole('button', { name: /Save/i }).closest('form');
        expect(form).toBeInTheDocument();
        expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });
});
