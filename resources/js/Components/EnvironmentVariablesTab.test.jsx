import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EnvironmentVariablesTab from './EnvironmentVariablesTab';

// Covers the locked-variable read-only state, the multiline value-field swap, search filtering
// across both env and hardcoded-env lists, the delete-confirmation exact-key gate, the
// resourceType-driven checkbox set (service vs application/database, literal hidden while
// multiline), the Redis-credential fully-locked-down row, the Developer-view bulk-edit toggle,
// and the canManageEnvironment read-only gate - none of it previously tested.

const patchSpy = vi.fn();
const postSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: (url, data, options) => patchSpy(url, data, options),
        post: (url, data, options) => postSpy(url, data, options),
        delete: (url, options) => deleteSpy(url, options),
    },
}));

function baseEnv(overrides = {}) {
    return {
        id: 1,
        key: 'DATABASE_URL',
        value: 'secret-value',
        comment: '',
        isMultiline: false,
        isLiteral: false,
        isRuntime: true,
        isBuildtime: true,
        isMagic: false,
        isLocked: false,
        isShared: false,
        isRedisCredential: false,
        isRequired: false,
        isReallyRequired: false,
        isBuildpackControl: false,
        realValue: null,
        urls: { update: '/env/1', lock: '/env/1/lock', destroy: '/env/1' },
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        envs: [baseEnv()],
        hardcodedEnvs: [],
        devEnvs: 'DATABASE_URL=secret-value',
        canManageEnvironment: true,
        problematicVariables: {},
        availableSharedVariables: {},
        envUrls: { store: '/env', bulkUpdate: '/env/bulk' },
        resourceType: 'application',
        ...overrides,
    };
}

beforeEach(() => {
    patchSpy.mockClear();
    postSpy.mockClear();
    deleteSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('EnvironmentVariablesTab', () => {
    it('shows a locked variable as read-only, with no editable value field', () => {
        render(<EnvironmentVariablesTab {...baseProps({ envs: [baseEnv({ isLocked: true })] })} />);

        expect(screen.getByText('🔒 Locked')).toBeInTheDocument();
        expect(screen.queryByDisplayValue('secret-value')).not.toBeInTheDocument();
        expect(screen.getByPlaceholderText('Comment')).not.toBeDisabled();
    });

    it('swaps the value field between a password input and a textarea based on Is Multiline?', () => {
        // EnvCard seeds its form state from `env` only once (useState's initial value), so a
        // rerender with the same `env.id` (same React key) wouldn't remount it - use a different
        // id per scenario, matching the "changed key forces remount" pattern used elsewhere.
        render(<EnvironmentVariablesTab {...baseProps({ envs: [baseEnv({ id: 1, isMultiline: false })] })} />);
        expect(document.querySelector('#env-1-value').tagName).toBe('INPUT');

        render(<EnvironmentVariablesTab {...baseProps({ envs: [baseEnv({ id: 2, isMultiline: true })] })} />);
        expect(document.querySelector('#env-2-value').tagName).toBe('TEXTAREA');
    });

    it('filters both the env list and the hardcoded-env list by search term', () => {
        render(
            <EnvironmentVariablesTab
                {...baseProps({
                    envs: [baseEnv({ id: 1, key: 'DATABASE_URL' }), baseEnv({ id: 2, key: 'REDIS_URL' })],
                    hardcodedEnvs: [{ key: 'COMPOSE_PROJECT_NAME', value: 'app', service_name: null }],
                })}
            />,
        );

        act(() => screen.getByLabelText('Search environment variables').focus());
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(screen.getByLabelText('Search environment variables'), 'redis');
            screen.getByLabelText('Search environment variables').dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(screen.queryByDisplayValue('DATABASE_URL')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('REDIS_URL')).toBeInTheDocument();
        expect(screen.queryByDisplayValue('COMPOSE_PROJECT_NAME')).not.toBeInTheDocument();
    });

    it('only enables Permanently Delete once the typed confirmation exactly matches the key', () => {
        render(<EnvironmentVariablesTab {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());
        const deleteBtn = screen.getByRole('button', { name: 'Permanently Delete' });
        expect(deleteBtn).toBeDisabled();

        const confirmInput = screen.getByPlaceholderText('Type "DATABASE_URL" to confirm');
        act(() => confirmInput.focus());
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(confirmInput, 'DATABASE_URL');
            confirmInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(deleteBtn).not.toBeDisabled();

        act(() => deleteBtn.click());
        expect(deleteSpy).toHaveBeenCalledWith('/env/1', expect.any(Object));
    });

    it('shows only Is Multiline?/Is Literal? for service resources, and the full buildtime/runtime set otherwise', () => {
        const { rerender } = render(<EnvironmentVariablesTab {...baseProps({ resourceType: 'service' })} />);
        expect(screen.queryByLabelText('Available at Buildtime')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Available at Runtime')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Is Multiline?')).toBeInTheDocument();

        rerender(<EnvironmentVariablesTab {...baseProps({ resourceType: 'application' })} />);
        expect(screen.getByLabelText('Available at Buildtime')).toBeInTheDocument();
        expect(screen.getByLabelText('Available at Runtime')).toBeInTheDocument();
    });

    it('hides Is Literal? once Is Multiline? is checked, for non-service resources', () => {
        render(<EnvironmentVariablesTab {...baseProps({ resourceType: 'application' })} />);

        expect(screen.getByLabelText('Is Literal?')).toBeInTheDocument();
        act(() => screen.getByLabelText('Is Multiline?').click());
        expect(screen.queryByLabelText('Is Literal?')).not.toBeInTheDocument();
    });

    it('locks down a Redis-credential row entirely: no checkboxes, key field disabled', () => {
        render(<EnvironmentVariablesTab {...baseProps({ envs: [baseEnv({ isRedisCredential: true })] })} />);

        expect(document.querySelector('#env-1-key')).toBeDisabled();
        expect(screen.queryByLabelText('Is Multiline?')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Available at Runtime')).not.toBeInTheDocument();
    });

    it('switches to Developer view and saves the bulk-edited text via patch to bulkUpdate', () => {
        render(<EnvironmentVariablesTab {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Developer view' }).click());
        expect(screen.getByRole('button', { name: 'Normal view' })).toBeInTheDocument();

        const textarea = screen.getByLabelText('Production Environment Variables');
        act(() => textarea.focus());
        const setter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
        act(() => {
            setter.call(textarea, 'DATABASE_URL=changed');
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });

        act(() => screen.getByRole('button', { name: 'Save All Environment Variables' }).click());
        expect(patchSpy).toHaveBeenCalledWith('/env/bulk', { variables: 'DATABASE_URL=changed' }, expect.any(Object));
    });

    it('hides Add, Developer view, and all per-variable action buttons when canManageEnvironment is false', () => {
        render(<EnvironmentVariablesTab {...baseProps({ canManageEnvironment: false })} />);

        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Developer view' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Update' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Lock' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });
});
