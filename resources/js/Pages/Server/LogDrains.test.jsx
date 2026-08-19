import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LogDrains from './LogDrains';

// The Custom provider was live-verified end-to-end during the 2026-07-28 Server management
// smoke test (issue #26), which also found and fixed a real bug: enabling Custom with the
// (not `required` in the UI) Custom Parser Configuration field left empty crashed
// StartLogDrain::handle() on base64_encode(null). That deliberate not-required field is
// covered directly below. The page itself was previously entirely untested.

const postSpy = vi.fn();
const routerPostSpy = vi.fn();
let mockErrors = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => routerPostSpy(url, data, options),
    },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, data, options),
            processing: false,
            errors: mockErrors,
        };
    },
}));

vi.mock('../../Components/ServerNavbar', () => ({ default: () => <div data-testid="server-navbar" /> }));
vi.mock('../../Components/ServerSidebar', () => ({ default: () => <div data-testid="server-sidebar" /> }));

function typeInto(element, value) {
    const proto = element.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseProps(overrides = {}) {
    return {
        serverNavbar: {},
        sidebar: {},
        isFunctional: true,
        canUpdate: true,
        isLogDrainEnabled: false,
        isLogDrainNewRelicEnabled: false,
        logDrainNewRelicLicenseKey: null,
        logDrainNewRelicBaseUri: null,
        isLogDrainAxiomEnabled: false,
        logDrainAxiomDatasetName: null,
        logDrainAxiomApiKey: null,
        isLogDrainCustomEnabled: false,
        logDrainCustomConfig: null,
        logDrainCustomConfigParser: null,
        toggleUrl: '/server/srv-uuid/log-drains/toggle',
        submitUrl: '/server/srv-uuid/log-drains/submit',
        ...overrides,
    };
}

describe('Server/LogDrains', () => {
    beforeEach(() => {
        postSpy.mockClear();
        routerPostSpy.mockClear();
        mockErrors = {};
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows a "not validated" message and nothing else when isFunctional is false', () => {
        render(<LogDrains {...baseProps({ isFunctional: false })} />);
        expect(screen.getByText('Server is not validated. Validate first.')).toBeInTheDocument();
        expect(screen.queryByText('Log Drains')).not.toBeInTheDocument();
    });

    it('renders the heading and all 3 provider sections when functional', () => {
        render(<LogDrains {...baseProps()} />);
        expect(screen.getByText('Log Drains')).toBeInTheDocument();
        expect(screen.getByText('New Relic')).toBeInTheDocument();
        expect(screen.getByText('Axiom')).toBeInTheDocument();
        expect(screen.getByText('Custom FluentBit')).toBeInTheDocument();
    });

    it('defaults every nullable field prop to an empty string, not the literal "null"', () => {
        render(<LogDrains {...baseProps()} />);
        expect(screen.getByLabelText('License Key')).toHaveValue('');
        expect(screen.getByLabelText('API Key')).toHaveValue('');
        expect(screen.getByLabelText('Custom FluentBit Configuration')).toHaveValue('');
    });

    describe('mutual exclusivity', () => {
        it('leaves all 3 checkboxes enabled when none are on', () => {
            render(<LogDrains {...baseProps()} />);
            expect(screen.getAllByLabelText('Enabled')[0]).not.toBeDisabled();
            expect(screen.getAllByLabelText('Enabled')[1]).not.toBeDisabled();
            expect(screen.getAllByLabelText('Enabled')[2]).not.toBeDisabled();
        });

        it('disables New Relic when Axiom or Custom is enabled', () => {
            const { rerender } = render(<LogDrains {...baseProps({ isLogDrainAxiomEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[0]).toBeDisabled();

            rerender(<LogDrains {...baseProps({ isLogDrainCustomEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[0]).toBeDisabled();
        });

        it('disables Axiom when New Relic or Custom is enabled', () => {
            const { rerender } = render(<LogDrains {...baseProps({ isLogDrainNewRelicEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[1]).toBeDisabled();

            rerender(<LogDrains {...baseProps({ isLogDrainCustomEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[1]).toBeDisabled();
        });

        it('disables Custom when New Relic or Axiom is enabled', () => {
            const { rerender } = render(<LogDrains {...baseProps({ isLogDrainNewRelicEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[2]).toBeDisabled();

            rerender(<LogDrains {...baseProps({ isLogDrainAxiomEnabled: true })} />);
            expect(screen.getAllByLabelText('Enabled')[2]).toBeDisabled();
        });
    });

    describe('toggling', () => {
        it('enabling New Relic posts type/enabled plus the current form fields to toggleUrl', () => {
            render(<LogDrains {...baseProps()} />);
            act(() => typeInto(screen.getByLabelText('License Key'), 'nr-key-123'));
            act(() => typeInto(screen.getByLabelText('Endpoint'), 'https://log-api.eu.newrelic.com/log/v1'));
            act(() => screen.getAllByLabelText('Enabled')[0].click());

            expect(routerPostSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/toggle',
                {
                    type: 'newrelic',
                    enabled: true,
                    logDrainNewRelicLicenseKey: 'nr-key-123',
                    logDrainNewRelicBaseUri: 'https://log-api.eu.newrelic.com/log/v1',
                },
                { preserveScroll: true },
            );
        });

        it('disabling Axiom posts enabled: false with the current fields', () => {
            render(<LogDrains {...baseProps({ isLogDrainAxiomEnabled: true, logDrainAxiomApiKey: 'axm-key', logDrainAxiomDatasetName: 'prod' })} />);
            act(() => screen.getAllByLabelText('Enabled')[1].click());

            expect(routerPostSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/toggle',
                { type: 'axiom', enabled: false, logDrainAxiomDatasetName: 'prod', logDrainAxiomApiKey: 'axm-key' },
                { preserveScroll: true },
            );
        });

        it('enabling Custom posts the config and parser fields, even when the parser is left blank', () => {
            render(<LogDrains {...baseProps()} />);
            act(() => typeInto(screen.getByLabelText('Custom FluentBit Configuration'), '[OUTPUT]\n    Name stdout'));
            act(() => screen.getAllByLabelText('Enabled')[2].click());

            expect(routerPostSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/toggle',
                { type: 'custom', enabled: true, logDrainCustomConfig: '[OUTPUT]\n    Name stdout', logDrainCustomConfigParser: '' },
                { preserveScroll: true },
            );
        });
    });

    describe('the Custom Parser Configuration field is deliberately optional', () => {
        it('marks Custom FluentBit Configuration as required but the parser field as not required', () => {
            render(<LogDrains {...baseProps()} />);
            expect(screen.getByLabelText('Custom FluentBit Configuration')).toBeRequired();
            expect(screen.getByLabelText('Custom Parser Configuration')).not.toBeRequired();
        });
    });

    describe('locking fields and Save while any drain is enabled', () => {
        it("disables every section's fields and Save button when isLogDrainEnabled is true", () => {
            render(<LogDrains {...baseProps({ isLogDrainEnabled: true, isLogDrainNewRelicEnabled: true })} />);
            expect(screen.getByLabelText('License Key')).toBeDisabled();
            expect(screen.getByLabelText('API Key')).toBeDisabled();
            expect(screen.getByLabelText('Custom FluentBit Configuration')).toBeDisabled();
            screen.getAllByRole('button', { name: 'Save' }).forEach((btn) => expect(btn).toBeDisabled());
        });

        it('leaves fields and Save enabled when isLogDrainEnabled is false', () => {
            render(<LogDrains {...baseProps({ isLogDrainEnabled: false })} />);
            expect(screen.getByLabelText('License Key')).not.toBeDisabled();
            screen.getAllByRole('button', { name: 'Save' }).forEach((btn) => expect(btn).not.toBeDisabled());
        });
    });

    describe('withholding mutating controls from plain members', () => {
        it('disables every checkbox and field, and hides every Save button, when canUpdate is false', () => {
            render(<LogDrains {...baseProps({ canUpdate: false })} />);
            screen.getAllByLabelText('Enabled').forEach((checkbox) => expect(checkbox).toBeDisabled());
            expect(screen.getByLabelText('License Key')).toBeDisabled();
            expect(screen.getByLabelText('Endpoint')).toBeDisabled();
            expect(screen.getByLabelText('API Key')).toBeDisabled();
            expect(screen.getByLabelText('Dataset Name')).toBeDisabled();
            expect(screen.getByLabelText('Custom FluentBit Configuration')).toBeDisabled();
            expect(screen.getByLabelText('Custom Parser Configuration')).toBeDisabled();
            expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        });

        it('leaves every checkbox, field, and Save button enabled when canUpdate is true', () => {
            render(<LogDrains {...baseProps({ canUpdate: true })} />);
            screen.getAllByLabelText('Enabled').forEach((checkbox) => expect(checkbox).not.toBeDisabled());
            expect(screen.getByLabelText('License Key')).not.toBeDisabled();
            screen.getAllByRole('button', { name: 'Save' }).forEach((btn) => expect(btn).not.toBeDisabled());
        });
    });

    describe('saving each provider independently', () => {
        it('submits the New Relic form to submitUrl with its own data', () => {
            render(<LogDrains {...baseProps()} />);
            act(() => typeInto(screen.getByLabelText('License Key'), 'nr-key'));
            act(() => typeInto(screen.getByLabelText('Endpoint'), 'https://example.com/log'));
            act(() => screen.getAllByRole('button', { name: 'Save' })[0].click());

            expect(postSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/submit',
                { type: 'newrelic', logDrainNewRelicLicenseKey: 'nr-key', logDrainNewRelicBaseUri: 'https://example.com/log' },
                { preserveScroll: true },
            );
        });

        it('submits the Axiom form to submitUrl with its own data, independent of New Relic', () => {
            render(<LogDrains {...baseProps()} />);
            act(() => typeInto(screen.getByLabelText('API Key'), 'axm-key'));
            act(() => typeInto(screen.getByLabelText('Dataset Name'), 'prod-logs'));
            act(() => screen.getAllByRole('button', { name: 'Save' })[1].click());

            expect(postSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/submit',
                { type: 'axiom', logDrainAxiomDatasetName: 'prod-logs', logDrainAxiomApiKey: 'axm-key' },
                { preserveScroll: true },
            );
        });

        it('submits the Custom form to submitUrl with its own data', () => {
            render(<LogDrains {...baseProps()} />);
            act(() => typeInto(screen.getByLabelText('Custom FluentBit Configuration'), '[OUTPUT]'));
            act(() => screen.getAllByRole('button', { name: 'Save' })[2].click());

            expect(postSpy).toHaveBeenCalledWith(
                '/server/srv-uuid/log-drains/submit',
                { type: 'custom', logDrainCustomConfig: '[OUTPUT]', logDrainCustomConfigParser: '' },
                { preserveScroll: true },
            );
        });
    });

    describe('per-field error rendering', () => {
        it("renders each section's own error independently", () => {
            mockErrors = {
                logDrainNewRelicLicenseKey: 'The license key field is required.',
                logDrainAxiomApiKey: 'The api key field is required.',
                logDrainCustomConfig: 'The custom config field is required.',
            };
            render(<LogDrains {...baseProps()} />);

            expect(screen.getByText('The license key field is required.')).toBeInTheDocument();
            expect(screen.getByText('The api key field is required.')).toBeInTheDocument();
            expect(screen.getByText('The custom config field is required.')).toBeInTheDocument();
        });
    });
});
