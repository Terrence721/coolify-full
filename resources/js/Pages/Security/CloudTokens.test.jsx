import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CloudTokens from './CloudTokens';

// Real logic: the canCreate gate hides the "New Token" form entirely, the empty state, a
// window.prompt typed-confirmation delete (must match the token's name exactly, same pattern as
// Destination/Show.jsx), the Validate action posting to a per-token URL, and the create form's
// reset('name', 'token') on success (leaving provider alone, since it's locked to digitalocean).

const formPostSpy = vi.fn();
const formResetSpy = vi.fn();
const routerPostSpy = vi.fn();
const routerDeleteSpy = vi.fn();
let formErrors = {};

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => formPostSpy(url, options),
            reset: (...fields) => formResetSpy(...fields),
            processing: false,
            errors: formErrors,
        };
    },
    router: {
        post: (url) => routerPostSpy(url),
        delete: (url) => routerDeleteSpy(url),
    },
}));

function token(overrides = {}) {
    return {
        id: 1,
        provider: 'digitalocean',
        name: 'Production DigitalOcean',
        createdAgo: '2 days ago',
        validateUrl: '/cloud-tokens/1/validate',
        destroyUrl: '/cloud-tokens/1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        tokens: [],
        canCreate: true,
        storeUrl: '/cloud-tokens',
        ...overrides,
    };
}

describe('Security/CloudTokens', () => {
    beforeEach(() => {
        formPostSpy.mockClear();
        formResetSpy.mockClear();
        routerPostSpy.mockClear();
        routerDeleteSpy.mockClear();
        formErrors = {};
        vi.spyOn(window, 'prompt');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('hides the New Token form when canCreate is false', () => {
        render(<CloudTokens {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: 'Validate & Add Token' })).not.toBeInTheDocument();
    });

    it('shows the New Token form when canCreate is true', () => {
        render(<CloudTokens {...baseProps({ canCreate: true })} />);
        expect(screen.getByRole('button', { name: 'Validate & Add Token' })).toBeInTheDocument();
    });

    it('shows the empty state when there are no tokens', () => {
        render(<CloudTokens {...baseProps({ tokens: [] })} />);
        expect(screen.getByText('No cloud provider tokens found.')).toBeInTheDocument();
    });

    it('does not show the empty state once a token exists', () => {
        render(<CloudTokens {...baseProps({ tokens: [token()] })} />);
        expect(screen.queryByText('No cloud provider tokens found.')).not.toBeInTheDocument();
    });

    it('renders the provider badge uppercased and the token name', () => {
        render(<CloudTokens {...baseProps({ tokens: [token({ provider: 'digitalocean' })] })} />);
        expect(screen.getByText('DIGITALOCEAN')).toBeInTheDocument();
        expect(screen.getByText('Production DigitalOcean')).toBeInTheDocument();
    });

    it('submits the create form to storeUrl', () => {
        render(<CloudTokens {...baseProps()} />);
        fireEvent.change(screen.getByLabelText('Token Name'), { target: { value: 'My Token' } });
        fireEvent.change(screen.getByLabelText('API Token'), { target: { value: 'secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Validate & Add Token' }));

        expect(formPostSpy).toHaveBeenCalledWith('/cloud-tokens', expect.objectContaining({ onSuccess: expect.any(Function) }));
    });

    it('resets only name and token on successful create, not provider', () => {
        render(<CloudTokens {...baseProps()} />);
        fireEvent.change(screen.getByLabelText('Token Name'), { target: { value: 'My Token' } });
        fireEvent.change(screen.getByLabelText('API Token'), { target: { value: 'secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Validate & Add Token' }));

        const [, options] = formPostSpy.mock.calls[0];
        options.onSuccess();

        expect(formResetSpy).toHaveBeenCalledWith('name', 'token');
    });

    it("clicking Validate posts to the token's validateUrl", () => {
        render(<CloudTokens {...baseProps({ tokens: [token({ validateUrl: '/cloud-tokens/1/validate' })] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Validate' }));
        expect(routerPostSpy).toHaveBeenCalledWith('/cloud-tokens/1/validate');
    });

    it('does not delete when the typed confirmation does not match the token name', () => {
        window.prompt.mockReturnValueOnce('wrong-name');
        render(<CloudTokens {...baseProps({ tokens: [token({ name: 'Production DigitalOcean' })] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(routerDeleteSpy).not.toHaveBeenCalled();
    });

    it('deletes when the typed confirmation exactly matches the token name', () => {
        window.prompt.mockReturnValueOnce('Production DigitalOcean');
        render(<CloudTokens {...baseProps({ tokens: [token({ name: 'Production DigitalOcean', destroyUrl: '/cloud-tokens/1' })] })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(routerDeleteSpy).toHaveBeenCalledWith('/cloud-tokens/1');
    });
});
