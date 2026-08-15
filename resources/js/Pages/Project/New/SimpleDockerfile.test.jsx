import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import SimpleDockerfile from './SimpleDockerfile';

vi.mock('../../../Components/MonacoEditor', () => ({
    default: ({ value, onChange, language }) => (
        <textarea
            data-testid="monaco-editor"
            data-language={language}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder="Enter Dockerfile..."
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

describe('Project/New/SimpleDockerfile', () => {
    const mockSubmitUrl = '/projects/applications/create';

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders heading and form', () => {
        render(<SimpleDockerfile defaultDockerfile="" submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('heading', { level: 1, name: /New Resource/i })).toBeInTheDocument();
        expect(screen.getByText(/Dockerfile/)).toBeInTheDocument();
    });

    it('renders Monaco editor with Dockerfile language', () => {
        render(<SimpleDockerfile defaultDockerfile="" submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toBeInTheDocument();
        expect(editor).toHaveAttribute('data-language', 'dockerfile');
    });

    it('renders Save button', () => {
        render(<SimpleDockerfile defaultDockerfile="" submitUrl={mockSubmitUrl} />);

        expect(screen.getByRole('button', { name: /Save/i })).toBeInTheDocument();
    });

    it('initializes with default Dockerfile content', () => {
        const defaultContent = 'FROM node:16\nRUN npm install\nCMD ["npm", "start"]';

        render(<SimpleDockerfile defaultDockerfile={defaultContent} submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toHaveValue(defaultContent);
    });

    it('starts with empty content when no default provided', () => {
        render(<SimpleDockerfile submitUrl={mockSubmitUrl} />);

        const editor = screen.getByTestId('monaco-editor');
        expect(editor).toHaveValue('');
    });

    it('has required form structure', () => {
        render(<SimpleDockerfile defaultDockerfile="" submitUrl={mockSubmitUrl} />);

        const form = screen.getByRole('button', { name: /Save/i }).closest('form');
        expect(form).toBeInTheDocument();
        expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });
});
