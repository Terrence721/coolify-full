import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import Index from './Index';

vi.mock('../../../Components/SharedVariablesManager', () => ({
    default: ({ testProp }) => <div data-testid="shared-variables-manager">Manager: {testProp}</div>,
}));

describe('SharedVariables/Team/Index', () => {
    it('renders SharedVariablesManager component', () => {
        render(<Index />);

        expect(screen.getByTestId('shared-variables-manager')).toBeInTheDocument();
    });

    it('passes through props to SharedVariablesManager', () => {
        const testProps = { testProp: 'team-scope', other: 'value' };

        render(<Index {...testProps} />);

        expect(screen.getByText(/Manager: team-scope/)).toBeInTheDocument();
    });
});
