import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DatabaseGeneralTab from './DatabaseGeneralTab';

// Regression coverage for a real bug found via the "+ New" resource wizard smoke test
// (2026-07-22): ProxySection used to render its own <form> nested inside this component's outer
// <form>, invalid HTML that React logs a hydration error for on every database's General tab.
// Also covers the Enter-to-save behavior that nested form existed for in the first place, now
// wired via onKeyDown on a <div> instead - previously untested either way.

const patchSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
        reload: () => {},
    },
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: () => {},
}));

function baseProps(overrides = {}) {
    return {
        generalForm: {
            canUpdate: true,
            name: 'orders-db',
            description: '',
            image: 'postgres:18-alpine',
            customDockerRunOptions: '',
            portsMappings: '',
            configField: null,
            credentials: [],
            isLogDrainEnabled: false,
            initScripts: null,
            statusInfo: {
                label: 'Postgres',
                dbUrl: 'postgres://internal',
                dbUrlPublic: null,
                showPublicUrlPlaceholder: true,
                supportsSsl: false,
                enableSsl: false,
                isExited: true,
            },
            isPublic: false,
            publicPort: '',
            publicPortTimeout: 3600,
            ...overrides.generalForm,
        },
        generalUrls: {
            update: '/db/1',
            updateAdvanced: '/db/1/advanced',
            updateProxy: '/db/1/proxy',
            updateSsl: '/db/1/ssl',
            regenerateSsl: '/db/1/ssl/regenerate',
            ...overrides.generalUrls,
        },
        resourceDetails: overrides.resourceDetails ?? {},
    };
}

beforeEach(() => {
    patchSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('DatabaseGeneralTab', () => {
    it('never nests a <form> inside another <form> (invalid HTML, was a real hydration error)', () => {
        const { container } = render(<DatabaseGeneralTab {...baseProps()} />);

        const forms = container.querySelectorAll('form');
        forms.forEach((form) => {
            expect(form.querySelector('form')).toBeNull();
        });
    });

    it('saves the proxy settings when pressing Enter in the Public Port field', () => {
        render(<DatabaseGeneralTab {...baseProps()} />);

        act(() => screen.getByLabelText('Public Port').focus());
        act(() => screen.getByLabelText('Public Port').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })));

        expect(patchSpy).toHaveBeenCalledWith('/db/1/proxy', { isPublic: false, publicPort: '', publicPortTimeout: 3600 }, expect.any(Object));
    });

    it('saves the proxy settings when pressing Enter in the Proxy Timeout field', () => {
        render(<DatabaseGeneralTab {...baseProps()} />);

        act(() => screen.getByLabelText('Proxy Timeout (seconds)').focus());
        act(() => screen.getByLabelText('Proxy Timeout (seconds)').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })));

        expect(patchSpy).toHaveBeenCalledWith('/db/1/proxy', { isPublic: false, publicPort: '', publicPortTimeout: 3600 }, expect.any(Object));
    });
});
