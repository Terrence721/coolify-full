import { render, screen, waitFor } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Patches from './Patches';

// The Server Patching page, directly tied to this session's real backend fix in
// CheckUpdates.php (Alpine/apk support, the corrected tooltip text) and the same-day
// Security Patches smoke test (issue #26) - previously entirely untested itself. Covers the
// Check for Updates fetch flow (in-flight/error/up-to-date/found-updates branches), the
// Update All Packages window.prompt() confirmation gate, the per-package Update button (no
// confirmation), the flash-triggered Updating Packages modal and its ActivityLog wiring, and
// the isDev-gated Send Test Email button.

const postSpy = vi.fn();
let mockFlash = {};
let activityLogOnFinished = null;

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
    },
    usePage: () => ({ props: { flash: mockFlash } }),
}));

vi.mock('../../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));
vi.mock('../../../Components/ActivityLog', () => ({
    default: ({ activityId, header, onFinished }) => {
        activityLogOnFinished = onFinished;
        return (
            <div data-testid="activity-log">
                {header} - {activityId}
            </div>
        );
    },
}));

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isDev: false,
        checkUpdatesUrl: '/server/srv-uuid/security/patches/check-updates',
        updateAllUrl: '/server/srv-uuid/security/patches/update-all',
        updatePackageUrl: '/server/srv-uuid/security/patches/update-package',
        notifyUpdatedUrl: '/server/srv-uuid/security/patches/notify-updated',
        sendTestEmailUrl: '/server/srv-uuid/security/patches/send-test-email',
        ...overrides,
    };
}

function jsonResponse(data) {
    return Promise.resolve({ json: () => Promise.resolve(data) });
}

function deferred() {
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });
    return { promise, resolve };
}

async function checkForUpdatesWith(data) {
    global.fetch = vi.fn(() => jsonResponse(data));
    await act(async () => {
        screen.getByRole('button', { name: 'Check for Updates' }).click();
    });
}

describe('Server/Security/Patches', () => {
    beforeEach(() => {
        postSpy.mockClear();
        mockFlash = {};
        activityLogOnFinished = null;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the heading and the accurate supported-package-manager tooltip', () => {
        render(<Patches {...baseProps()} />);
        expect(screen.getByText('Server Patching')).toBeInTheDocument();
        expect(screen.getByText('(experimental)')).toBeInTheDocument();
        expect(screen.getByTitle(/apt, dnf, zypper, pacman, and apk package managers/)).toBeInTheDocument();
    });

    it('hides Send Test Email outside of dev mode, shows it in dev mode', () => {
        const { rerender } = render(<Patches {...baseProps({ isDev: false })} />);
        expect(screen.queryByRole('button', { name: 'Send Test Email (dev only)' })).not.toBeInTheDocument();

        rerender(<Patches {...baseProps({ isDev: true })} />);
        expect(screen.getByRole('button', { name: 'Send Test Email (dev only)' })).toBeInTheDocument();
    });

    it('calls router.post(sendTestEmailUrl) when Send Test Email is clicked', () => {
        render(<Patches {...baseProps({ isDev: true })} />);
        act(() => screen.getByRole('button', { name: 'Send Test Email (dev only)' }).click());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/security/patches/send-test-email', {}, { preserveScroll: true });
    });

    it('calls fetch(checkUpdatesUrl) with POST/JSON headers, showing a checking message and a disabled button while in flight', async () => {
        const { promise, resolve } = deferred();
        global.fetch = vi.fn(() => promise);
        render(<Patches {...baseProps()} />);

        const button = screen.getByRole('button', { name: 'Check for Updates' });
        act(() => button.click());

        expect(global.fetch).toHaveBeenCalledWith(
            '/server/srv-uuid/security/patches/check-updates',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({ 'Content-Type': 'application/json', Accept: 'application/json' }),
            }),
        );
        expect(screen.getByText('Checking for updates. It may take a few minutes.')).toBeInTheDocument();
        expect(button).toBeDisabled();

        await act(async () => {
            resolve(jsonResponse({ totalUpdates: 0, updates: [], osId: 'alpine', packageManager: 'apk' }));
            await promise;
        });

        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('shows the green up-to-date message when totalUpdates is 0, with no table', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({ totalUpdates: 0, updates: [], osId: 'debian', packageManager: 'apt' });

        expect(screen.getByText('Your server is up to date.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('renders the update table with package/version/current-version/Update per row when updates are found', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({
            totalUpdates: 2,
            updates: [
                {
                    package: 'curl',
                    new_version: '7.88.1-10+deb12u15',
                    current_version: '7.88.1-10+deb12u14',
                    architecture: 'amd64',
                    repository: 'stable',
                },
                { package: 'libcrypto3', new_version: '3.1.8-r0', current_version: '3.1.0-r4', architecture: 'x86_64', repository: 'openssl' },
            ],
            osId: 'debian',
            packageManager: 'apt',
        });

        expect(screen.getByRole('button', { name: 'Update All Packages' })).toBeInTheDocument();
        expect(screen.getByText('curl')).toBeInTheDocument();
        expect(screen.getByText('7.88.1-10+deb12u15')).toBeInTheDocument();
        expect(screen.getByText('(current: 7.88.1-10+deb12u14)')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Update' })).toHaveLength(2);
    });

    it('hides the (current: ...) text when the package manager is dnf', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({
            totalUpdates: 1,
            updates: [{ package: 'httpd', new_version: '2.4.58', current_version: 'unknown', architecture: 'x86_64', repository: 'baseos' }],
            osId: 'rocky',
            packageManager: 'dnf',
        });

        expect(screen.getByText('httpd')).toBeInTheDocument();
        expect(screen.queryByText(/current:/)).not.toBeInTheDocument();
    });

    it('shows the real error text and no up-to-date/table state when the response has an error', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({ error: 'Server is not reachable or not ready.' });

        expect(screen.getByText('Server is not reachable or not ready.')).toBeInTheDocument();
        expect(screen.queryByText('Your server is up to date.')).not.toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('shows a generic "Something went wrong." error when the fetch itself throws', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('network down')));
        render(<Patches {...baseProps()} />);

        await act(async () => {
            screen.getByRole('button', { name: 'Check for Updates' }).click();
        });

        expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
    });

    it('does not call router.post for Update All Packages when the prompt confirmation text does not match', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({
            totalUpdates: 1,
            updates: [{ package: 'curl', new_version: '8.0', current_version: '7.9', architecture: 'amd64', repository: 'stable' }],
            osId: 'debian',
            packageManager: 'apt',
        });

        vi.spyOn(window, 'prompt').mockReturnValue('nope');
        act(() => screen.getByRole('button', { name: 'Update All Packages' }).click());

        expect(postSpy).not.toHaveBeenCalled();
    });

    it('calls router.post(updateAllUrl, {packageManager, osId}) when the prompt confirmation matches exactly', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({
            totalUpdates: 1,
            updates: [{ package: 'curl', new_version: '8.0', current_version: '7.9', architecture: 'amd64', repository: 'stable' }],
            osId: 'debian',
            packageManager: 'apt',
        });

        vi.spyOn(window, 'prompt').mockReturnValue('Update All Packages');
        act(() => screen.getByRole('button', { name: 'Update All Packages' }).click());

        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/security/patches/update-all',
            { packageManager: 'apt', osId: 'debian' },
            { preserveScroll: true },
        );
    });

    it('calls router.post(updatePackageUrl, {package, packageManager, osId}) for a single row, with no confirmation prompt', async () => {
        render(<Patches {...baseProps()} />);
        await checkForUpdatesWith({
            totalUpdates: 1,
            updates: [{ package: 'curl', new_version: '8.0', current_version: '7.9', architecture: 'amd64', repository: 'stable' }],
            osId: 'debian',
            packageManager: 'apt',
        });

        const promptSpy = vi.spyOn(window, 'prompt');
        act(() => screen.getByRole('button', { name: 'Update' }).click());

        expect(promptSpy).not.toHaveBeenCalled();
        expect(postSpy).toHaveBeenCalledWith(
            '/server/srv-uuid/security/patches/update-package',
            { package: 'curl', packageManager: 'apt', osId: 'debian' },
            { preserveScroll: true },
        );
    });

    it('opens the Updating Packages modal when flash carries a patches-update activity context', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-42' };
        render(<Patches {...baseProps()} />);

        expect(screen.getByText('Updating Packages')).toBeInTheDocument();
        expect(screen.getByTestId('activity-log')).toHaveTextContent('Logs - act-42');
    });

    it('does not open the modal for an unrelated flash activity context', () => {
        mockFlash = { activityContext: 'database', activityId: 'act-42' };
        render(<Patches {...baseProps()} />);

        expect(screen.queryByText('Updating Packages')).not.toBeInTheDocument();
    });

    it('closes the modal via the ✕ button', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-42' };
        render(<Patches {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(screen.queryByText('Updating Packages')).not.toBeInTheDocument();
    });

    it('closes the modal via the backdrop click', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-42' };
        render(<Patches {...baseProps()} />);

        const backdrop = document.querySelector('.absolute.inset-0.h-full.w-full');
        act(() => backdrop.click());
        expect(screen.queryByText('Updating Packages')).not.toBeInTheDocument();
    });

    it('calls router.post(notifyUpdatedUrl) when ActivityLog reports the update finished', () => {
        mockFlash = { activityContext: 'patches-update', activityId: 'act-42' };
        render(<Patches {...baseProps()} />);

        act(() => activityLogOnFinished());

        expect(postSpy).toHaveBeenCalledWith('/server/srv-uuid/security/patches/notify-updated', {}, { preserveScroll: true });
    });
});
