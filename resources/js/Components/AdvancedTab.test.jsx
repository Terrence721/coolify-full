import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AdvancedTab from './AdvancedTab';

// The largest untested component left in the backlog (380 lines) - a large collection of
// instant-save checkboxes plus 3 standalone forms and a GPU section. The riskiest logic: every
// instant-save checkbox submits the *entire* current form state to one endpoint (Livewire's
// syncData(toModel: true) behavior this port replicates), not a field-scoped PATCH - a classic
// stale-closure trap if a later toggle doesn't see an earlier one's update. Also several
// cross-field disabled/hidden conditions (preview-deployments gating public-PR-deployments,
// container-label-readonly gating the proxy checkboxes' own enabled state and helper text).

const routerPatch = vi.fn();
const routerPost = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, opts) => routerPatch(url, data, opts),
        post: (url, data, opts) => routerPost(url, data, opts),
    },
}));

afterEach(() => {
    routerPatch.mockClear();
    routerPost.mockClear();
});

function baseAdvanced(overrides = {}) {
    return {
        isForceHttpsEnabled: false,
        isGzipEnabled: false,
        isStripprefixEnabled: false,
        isLogDrainEnabled: false,
        isGitSubmodulesEnabled: false,
        isGitLfsEnabled: false,
        isGitShallowCloneEnabled: false,
        isPreviewDeploymentsEnabled: false,
        isPrDeploymentsPublicEnabled: false,
        isAutoDeployEnabled: false,
        isGpuEnabled: false,
        gpuDriver: '',
        gpuCount: '',
        gpuDeviceIds: '',
        gpuOptions: '',
        isBuildServerEnabled: false,
        isConsistentContainerNameEnabled: false,
        isRawComposeDeploymentEnabled: false,
        isConnectToDockerNetworkEnabled: false,
        disableBuildCache: false,
        injectBuildArgsToDockerfile: false,
        includeSourceCommitInBuild: false,
        isContainerLabelReadonlyEnabled: true,
        gitBased: true,
        buildPack: 'nixpacks',
        customInternalName: '',
        stopGracePeriod: 30,
        maxRestartCount: 0,
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        advanced: baseAdvanced(),
        advancedUrls: {
            instantSave: '/advanced/instant-save',
            update: '/advanced/update',
            customName: '/advanced/custom-name',
            stopGracePeriod: '/advanced/stop-grace-period',
            maxRestartCount: '/advanced/max-restart-count',
        },
        canUpdate: true,
        ...overrides,
    };
}

describe('instant-save checkboxes', () => {
    it('submits the whole current form on a single toggle, not just the changed field', () => {
        render(<AdvancedTab {...baseProps()} />);
        fireEvent.click(screen.getByRole('checkbox', { name: /Disable Build Cache/ }));

        expect(routerPatch).toHaveBeenCalledWith(
            '/advanced/instant-save',
            expect.objectContaining({ disableBuildCache: true, isGitSubmodulesEnabled: false }),
            { preserveScroll: true },
        );
    });

    it('accumulates a second toggle on top of the first, proving state is not a stale closure', () => {
        render(<AdvancedTab {...baseProps()} />);
        fireEvent.click(screen.getByRole('checkbox', { name: /Disable Build Cache/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: /Submodules/ }));

        expect(routerPatch).toHaveBeenLastCalledWith(
            '/advanced/instant-save',
            expect.objectContaining({ disableBuildCache: true, isGitSubmodulesEnabled: true }),
            { preserveScroll: true },
        );
    });

    it('disables every instant-save checkbox when canUpdate is false', () => {
        render(<AdvancedTab {...baseProps({ canUpdate: false })} />);
        expect(screen.getByRole('checkbox', { name: /Disable Build Cache/ })).toBeDisabled();
    });
});

describe('conditional sections', () => {
    it('hides the Deployment/Git sections when the application is not git-based', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ gitBased: false }) })} />);
        expect(screen.queryByRole('checkbox', { name: /Auto Deploy/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox', { name: /Submodules/ })).not.toBeInTheDocument();
    });

    it('shows the Docker Compose section only for the dockercompose build pack', () => {
        const { rerender } = render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ buildPack: 'nixpacks' }) })} />);
        expect(screen.queryByRole('checkbox', { name: /Raw Compose Deployment/ })).not.toBeInTheDocument();

        rerender(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ buildPack: 'dockercompose' }) })} />);
        expect(screen.getByRole('checkbox', { name: /Raw Compose Deployment/ })).toBeInTheDocument();
    });

    it('hides the GPU section entirely for the dockercompose build pack', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ buildPack: 'dockercompose' }) })} />);
        expect(screen.queryByRole('checkbox', { name: /Enable GPU/ })).not.toBeInTheDocument();
    });

    it('shows the Custom Container Name form when consistent-container-names is off', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isConsistentContainerNameEnabled: false }) })} />);
        expect(screen.getByLabelText(/Custom Container Name/)).toBeInTheDocument();
    });

    it('hides the Custom Container Name form when consistent-container-names is on', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isConsistentContainerNameEnabled: true }) })} />);
        expect(screen.queryByLabelText(/Custom Container Name/)).not.toBeInTheDocument();
    });
});

describe('cross-field disabled/helper logic', () => {
    it('disables "Allow Public PR Deployments" when Preview Deployments is off', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isPreviewDeploymentsEnabled: false }) })} />);
        expect(screen.getByRole('checkbox', { name: /Allow Public PR Deployments/ })).toBeDisabled();
    });

    it('enables "Allow Public PR Deployments" when Preview Deployments is on', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isPreviewDeploymentsEnabled: true }) })} />);
        expect(screen.getByRole('checkbox', { name: /Allow Public PR Deployments/ })).not.toBeDisabled();
    });

    it('disables Force Https/Gzip/Strip Prefix and swaps their helper text when container labels are not readonly', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isContainerLabelReadonlyEnabled: false }) })} />);

        expect(screen.getByRole('checkbox', { name: /Force Https/ })).toBeDisabled();
        expect(screen.getAllByText(/Readonly labels are disabled/).length).toBeGreaterThanOrEqual(3);
    });

    it('enables Force Https/Gzip/Strip Prefix with their normal helper text when container labels are readonly', () => {
        render(<AdvancedTab {...baseProps({ advanced: baseAdvanced({ isContainerLabelReadonlyEnabled: true }) })} />);

        expect(screen.getByRole('checkbox', { name: /Force Https/ })).not.toBeDisabled();
        expect(screen.getByText(/available only on https/)).toBeInTheDocument();
    });
});

describe('standalone forms', () => {
    // Multiple "Save" buttons are on screen at once (Custom Name, Stop Grace Period, Max Restart
    // Count all render unconditionally) - scope each submit to its own <form> via within().

    it('Custom Container Name form posts to advancedUrls.customName', () => {
        render(<AdvancedTab {...baseProps()} />);
        const field = screen.getByLabelText(/Custom Container Name/);
        fireEvent.change(field, { target: { value: 'my-app-1' } });
        fireEvent.click(within(field.closest('form')).getByRole('button', { name: 'Save' }));

        expect(routerPost).toHaveBeenCalledWith('/advanced/custom-name', { customInternalName: 'my-app-1' }, { preserveScroll: true });
    });

    it('Stop Grace Period form patches advancedUrls.stopGracePeriod', () => {
        render(<AdvancedTab {...baseProps()} />);
        const field = screen.getByLabelText(/Stop Grace Period/);
        fireEvent.change(field, { target: { value: '60' } });
        fireEvent.click(within(field.closest('form')).getByRole('button', { name: 'Save' }));

        expect(routerPatch).toHaveBeenCalledWith('/advanced/stop-grace-period', { stopGracePeriod: '60' }, { preserveScroll: true });
    });

    it('Max Restart Count form patches advancedUrls.maxRestartCount', () => {
        render(<AdvancedTab {...baseProps()} />);
        const field = screen.getByLabelText(/Max Restart Count/);
        fireEvent.change(field, { target: { value: '5' } });
        fireEvent.click(within(field.closest('form')).getByRole('button', { name: 'Save' }));

        expect(routerPatch).toHaveBeenCalledWith('/advanced/max-restart-count', { maxRestartCount: '5' }, { preserveScroll: true });
    });
});

describe('GPU section', () => {
    it('reveals the GPU sub-fields and a Save button only once Enable GPU is checked', () => {
        render(<AdvancedTab {...baseProps()} />);
        expect(screen.queryByLabelText('GPU Driver')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('checkbox', { name: /Enable GPU/ }));
        expect(screen.getByLabelText('GPU Driver')).toBeInTheDocument();
        expect(screen.getByLabelText('GPU Count')).toBeInTheDocument();
    });

    it('does not instant-save when toggling Enable GPU - it is plain local state until the GPU form is submitted', () => {
        render(<AdvancedTab {...baseProps()} />);
        fireEvent.click(screen.getByRole('checkbox', { name: /Enable GPU/ }));
        expect(routerPatch).not.toHaveBeenCalled();
    });

    it('submits the whole form, including GPU fields, via router.patch to advancedUrls.update', () => {
        render(<AdvancedTab {...baseProps()} />);
        fireEvent.click(screen.getByRole('checkbox', { name: /Enable GPU/ }));
        const driverField = screen.getByLabelText('GPU Driver');
        fireEvent.change(driverField, { target: { value: 'nvidia' } });
        fireEvent.click(within(driverField.closest('form')).getByRole('button', { name: 'Save' }));

        expect(routerPatch).toHaveBeenCalledWith('/advanced/update', expect.objectContaining({ isGpuEnabled: true, gpuDriver: 'nvidia' }), {
            preserveScroll: true,
        });
    });
});
