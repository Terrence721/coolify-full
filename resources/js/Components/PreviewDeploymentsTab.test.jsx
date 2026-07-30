import { render, screen, within } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PreviewDeploymentsTab from './PreviewDeploymentsTab';

// Previously entirely untested. This suite focuses on the domain-conflict modal's flash-driven
// open/reset logic (issue #33's react-hooks/set-state-in-effect fix: adjusting state during
// render instead of via a useEffect) - not full page coverage of every preview-deployment
// action, since most of those are plain router calls already covered by the same pattern
// exercised elsewhere in this repo's test suite.

const patchSpy = vi.fn();
let mockFlash = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
        patch: (url, data, options) => patchSpy(url, data, options),
    },
    usePage: () => ({ props: { flash: mockFlash } }),
}));

vi.mock('./DomainConflictModal', () => ({
    default: (props) => (
        <div data-testid="domain-conflict-modal">
            <button type="button" onClick={props.onCancel}>
                Cancel
            </button>
            <button type="button" onClick={props.onConfirm}>
                Confirm
            </button>
        </div>
    ),
}));

vi.mock('./PasswordConfirmModal', () => ({ default: () => <div data-testid="password-confirm-modal" /> }));

function basePreview(overrides = {}) {
    return {
        id: 1,
        fqdn: 'preview.example.com',
        dockerRegistryImageTag: null,
        composeDomains: [],
        status: 'running',
        pullRequestId: 42,
        pullRequestHtmlUrl: null,
        deploymentLogsUrl: '/logs/deployment',
        applicationLogsUrl: '/logs/application',
        urls: {
            domainUpdate: '/preview/1/domain',
            domainGenerate: '/preview/1/domain/generate',
            stop: '/preview/1/stop',
            destroy: '/preview/1',
            deploy: '/preview/1/deploy',
            forceDeploy: '/preview/1/force-deploy',
        },
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        previews: {
            previewUrlTemplate: '{{pr_id}}.{{domain}}',
            realPreviewUrlTemplate: null,
            deployments: [basePreview()],
            additionalServersCount: 0,
            isGithubBased: false,
            buildPack: 'dockerfile',
            canDeploy: true,
            canDelete: true,
            primaryServerName: 'production-01',
        },
        previewUrls: {
            loadPullRequests: '/preview/load-prs',
            updateTemplate: '/preview/template',
            store: '/preview/store',
            addAndDeploy: '/preview/deploy',
        },
        canUpdate: true,
        ...overrides,
    };
}

function clickDomainSave() {
    const form = screen.getByLabelText(/^Domain/).closest('form');
    act(() => within(form).getByRole('button', { name: 'Save' }).click());
}

describe('PreviewDeploymentsTab', () => {
    beforeEach(() => {
        patchSpy.mockClear();
        mockFlash = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('does not show the domain-conflict modal before any domain save was attempted', () => {
        mockFlash = { showDomainConflictModal: true, domainConflicts: ['example.com'] };
        render(<PreviewDeploymentsTab {...baseProps()} />);
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();
    });

    it('shows the conflict modal after saving a domain triggers a conflict flash', () => {
        mockFlash = {};
        const { rerender } = render(<PreviewDeploymentsTab {...baseProps()} />);

        clickDomainSave();
        expect(patchSpy).toHaveBeenCalled();

        mockFlash = { showDomainConflictModal: true, domainConflicts: ['example.com'] };
        rerender(<PreviewDeploymentsTab {...baseProps()} />);

        expect(screen.getByTestId('domain-conflict-modal')).toBeInTheDocument();
    });

    it('dismisses the modal via Cancel, and a genuinely new conflicts flash re-opens it', () => {
        mockFlash = { showDomainConflictModal: true, domainConflicts: ['example.com'] };
        const { rerender } = render(<PreviewDeploymentsTab {...baseProps()} />);
        clickDomainSave();
        expect(screen.getByTestId('domain-conflict-modal')).toBeInTheDocument();

        act(() => screen.getByRole('button', { name: 'Cancel' }).click());
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();

        // Same flash reference-equal value re-renders (e.g. an unrelated state change) - stays dismissed.
        rerender(<PreviewDeploymentsTab {...baseProps()} />);
        expect(screen.queryByTestId('domain-conflict-modal')).not.toBeInTheDocument();

        // A genuinely new conflicts flash (different array) must re-open it even after a prior dismissal.
        mockFlash = { showDomainConflictModal: true, domainConflicts: ['other.example.com'] };
        rerender(<PreviewDeploymentsTab {...baseProps()} />);
        expect(screen.getByTestId('domain-conflict-modal')).toBeInTheDocument();
    });

    it('confirming the conflict modal saves the domain with force_save_domains: true', () => {
        mockFlash = { showDomainConflictModal: true, domainConflicts: ['example.com'] };
        render(<PreviewDeploymentsTab {...baseProps()} />);
        clickDomainSave();
        patchSpy.mockClear();

        act(() => screen.getByRole('button', { name: 'Confirm' }).click());

        expect(patchSpy).toHaveBeenCalledWith(
            '/preview/1/domain',
            expect.objectContaining({ force_save_domains: true }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
