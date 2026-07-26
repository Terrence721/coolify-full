import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';

// Live-verified 2026-07-26 during the Server management smoke test (issue #26): Regenerate (typed
// CA-cert-path confirmation) produced a real "CA Certificate regenerated successfully." toast and
// a correctly-colored "Valid until" date, confirmed against a real X.509 cert on the actual managed
// server. This suite locks in the previously-untested logic: the expiry-color computation (not
// rendered at all / normal / expiring-soon / expired), the Show/Hide toggle, the canManage/canView
// gates, and the typed-path confirmation guard on both Save and Regenerate.

const postSpy = vi.fn();
const routerPostSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, options),
            processing: false,
        };
    },
    router: {
        post: (url, data, options) => routerPostSpy(url, data, options),
    },
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

const CA_CERT_PATH = '/data/coolify/ssl/coolify-ca.crt';
const DAY = 24 * 60 * 60 * 1000;

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        certificateContent: '-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----',
        certificateValidUntil: null,
        canManage: true,
        canView: true,
        saveUrl: '/server/srv-uuid/ca-certificate',
        regenerateUrl: '/server/srv-uuid/ca-certificate/regenerate',
        ...overrides,
    };
}

describe('Server/CaCertificate/Show', () => {
    beforeEach(() => {
        postSpy.mockClear();
        routerPostSpy.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows Save/Regenerate only when canManage, and Show/Hide only when canView', () => {
        const { unmount } = render(<Show {...baseProps({ canManage: false, canView: false })} />);
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Regenerate' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Show' })).not.toBeInTheDocument();
        unmount();

        render(<Show {...baseProps({ canManage: true, canView: true })} />);
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Regenerate' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Show' })).toBeInTheDocument();
    });

    it('toggles between the masked placeholder and the real certificate textarea', () => {
        render(<Show {...baseProps()} />);
        expect(screen.getByText('CERTIFICATE CONTENT', { exact: false })).toBeInTheDocument();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Show' }).click());
        expect(screen.getByRole('textbox')).toHaveValue(baseProps().certificateContent);
        expect(screen.getByRole('button', { name: 'Hide' })).toBeInTheDocument();
    });

    it('renders no "Valid until" label at all when certificateValidUntil is not set', () => {
        render(<Show {...baseProps({ certificateValidUntil: null })} />);
        expect(screen.queryByText('Valid until', { exact: false })).not.toBeInTheDocument();
    });

    it('renders a plain (non-red) label for a certificate valid well into the future', () => {
        const farFuture = new Date(Date.now() + 365 * DAY).toISOString();
        render(<Show {...baseProps({ certificateValidUntil: farFuture })} />);
        const label = screen.getByText('Valid until', { exact: false });
        expect(label.querySelector('.text-red-500')).not.toBeInTheDocument();
        expect(label).not.toHaveTextContent('Expired');
        expect(label).not.toHaveTextContent('Expiring soon');
    });

    it('renders a red "Expiring soon" label within 30 days of expiry', () => {
        const soon = new Date(Date.now() + 10 * DAY).toISOString();
        render(<Show {...baseProps({ certificateValidUntil: soon })} />);
        const label = screen.getByText('Valid until', { exact: false });
        expect(label).toHaveTextContent('Expiring soon');
        expect(label.querySelector('.text-red-500')).toBeInTheDocument();
    });

    it('renders a red "Expired" label for a certificate already past its valid-until date', () => {
        const past = new Date(Date.now() - 10 * DAY).toISOString();
        render(<Show {...baseProps({ certificateValidUntil: past })} />);
        const label = screen.getByText('Valid until', { exact: false });
        expect(label).toHaveTextContent('Expired');
        expect(label.querySelector('.text-red-500')).toBeInTheDocument();
    });

    it('does not save when the typed confirmation does not match the CA certificate path', () => {
        vi.spyOn(window, 'prompt').mockReturnValue('wrong/path');
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('saves once the typed confirmation exactly matches the CA certificate path', () => {
        vi.spyOn(window, 'prompt').mockReturnValue(CA_CERT_PATH);
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Save' }).click());
        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/ca-certificate', { preserveScroll: true });
    });

    it('does not regenerate when the typed confirmation does not match', () => {
        vi.spyOn(window, 'prompt').mockReturnValue('nope');
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Regenerate' }).click());
        expect(routerPostSpy).not.toHaveBeenCalled();
    });

    it('regenerates once the typed confirmation exactly matches the CA certificate path', () => {
        vi.spyOn(window, 'prompt').mockReturnValue(CA_CERT_PATH);
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Regenerate' }).click());
        expect(routerPostSpy).toHaveBeenCalledWith('/server/srv-uuid/ca-certificate/regenerate', {}, { preserveScroll: true });
    });

    it('updates the form data when the revealed textarea is edited', () => {
        render(<Show {...baseProps()} />);
        act(() => screen.getByRole('button', { name: 'Show' }).click());

        const textarea = screen.getByRole('textbox');
        const setter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
        act(() => {
            setter.call(textarea, 'edited-cert-content');
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(textarea).toHaveValue('edited-cert-content');
    });
});
