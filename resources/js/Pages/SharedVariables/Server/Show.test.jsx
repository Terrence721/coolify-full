import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import Show from './Show';

vi.mock('../../../Components/SharedVariablesManager', () => ({
    default: ({ testProp }) => <div data-testid="shared-variables-manager">Manager: {testProp}</div>,
}));

describe('SharedVariables/Server/Show', () => {
    it('renders SharedVariablesManager component', () => {
        render(<Show />);

        expect(screen.getByTestId('shared-variables-manager')).toBeInTheDocument();
    });

    it('passes through props to SharedVariablesManager', () => {
        const testProps = { testProp: 'server-show', other: 'value' };

        render(<Show {...testProps} />);

        expect(screen.getByText(/Manager: server-show/)).toBeInTheDocument();
    });
});
