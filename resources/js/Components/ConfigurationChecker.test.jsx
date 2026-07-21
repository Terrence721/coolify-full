import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConfigurationChecker from './ConfigurationChecker';

// Shared across Application/Database/Service configuration pages - untested despite real logic:
// the singular/plural + rebuild-vs-redeploy messaging, section grouping (defaulting to "Other"),
// and the per-row expand/collapse for long or explicitly-expandable values. Also the real
// end-to-end consumer of useTeamChannel's ApplicationConfigurationChanged event, which reloads
// just the configurationChecker prop rather than the whole page.

let teamChannelCallback = null;
const reloadSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (opts) => reloadSpy(opts),
    },
}));

vi.mock('../hooks/useTeamChannel', () => ({
    useTeamChannel: (events, onEvent) => {
        teamChannelCallback = onEvent;
    },
}));

function makeChecker(overrides = {}) {
    return {
        isConfigurationChanged: true,
        isExited: false,
        configHash: 'abc123',
        diff: { changes: [] },
        ...overrides,
    };
}

function change(overrides = {}) {
    return {
        key: 'field',
        label: 'Field',
        section_label: 'General',
        impact: 'redeploy',
        old_display_value: 'old',
        new_display_value: 'new',
        ...overrides,
    };
}

describe('ConfigurationChecker', () => {
    beforeEach(() => {
        reloadSpy.mockClear();
        teamChannelCallback = null;
    });

    it('renders nothing when there is no configuration change', () => {
        const { container } = render(<ConfigurationChecker configurationChecker={makeChecker({ isConfigurationChanged: false })} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when configHash is null', () => {
        const { container } = render(<ConfigurationChecker configurationChecker={makeChecker({ configHash: null })} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the resource is exited', () => {
        const { container } = render(<ConfigurationChecker configurationChecker={makeChecker({ isExited: true })} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('shows the plain redeploy message with no count when there are no individual changes tracked', () => {
        render(<ConfigurationChecker configurationChecker={makeChecker()} />);

        expect(screen.getByText('Please redeploy to apply the new configuration.')).toBeInTheDocument();
        expect(screen.queryByText('View changes')).not.toBeInTheDocument();
    });

    it('uses singular "change" for exactly one change', () => {
        render(<ConfigurationChecker configurationChecker={makeChecker({ diff: { changes: [change()] } })} />);

        expect(screen.getByText(/1 unapplied configuration change detected/)).toBeInTheDocument();
    });

    it('uses plural "changes" for more than one change', () => {
        render(<ConfigurationChecker configurationChecker={makeChecker({ diff: { changes: [change(), change({ key: 'other' })] } })} />);

        expect(screen.getByText(/2 unapplied configuration changes detected/)).toBeInTheDocument();
    });

    it('says a rebuild is required when any change has build impact', () => {
        render(
            <ConfigurationChecker
                configurationChecker={makeChecker({ diff: { changes: [change({ impact: 'build' }), change({ key: 'other' })] } })}
            />,
        );

        expect(screen.getByText(/A rebuild is required/)).toBeInTheDocument();
    });

    it('says to redeploy (not rebuild) when no change requires a build', () => {
        render(<ConfigurationChecker configurationChecker={makeChecker({ diff: { changes: [change()] } })} />);

        expect(screen.getByText(/Please redeploy to apply the new configuration/)).toBeInTheDocument();
        expect(screen.queryByText(/A rebuild is required/)).not.toBeInTheDocument();
    });

    it('groups changes by section_label, defaulting to "Other" when missing', () => {
        render(
            <ConfigurationChecker
                configurationChecker={makeChecker({
                    diff: { changes: [change({ section_label: 'Network' }), change({ key: 'unlabeled', section_label: undefined })] },
                })}
            />,
        );

        act(() => screen.getByText('View changes').click());

        expect(screen.getByText('Network')).toBeInTheDocument();
        expect(screen.getByText('Other')).toBeInTheDocument();
    });

    it('opens and closes the changes modal via the View changes link, the backdrop, and the close button', () => {
        const { container } = render(<ConfigurationChecker configurationChecker={makeChecker({ diff: { changes: [change()] } })} />);

        expect(screen.queryByText('Configuration changes')).not.toBeInTheDocument();

        act(() => screen.getByText('View changes').click());
        expect(screen.getByText('Configuration changes')).toBeInTheDocument();

        act(() => screen.getByText('✕').click());
        expect(screen.queryByText('Configuration changes')).not.toBeInTheDocument();

        act(() => screen.getByText('View changes').click());
        expect(screen.getByText('Configuration changes')).toBeInTheDocument();

        act(() => container.querySelector('.backdrop-blur-xs').click());
        expect(screen.queryByText('Configuration changes')).not.toBeInTheDocument();
    });

    it('truncates a long label and expands it on toggle', () => {
        const longLabel = 'A'.repeat(25);
        render(
            <ConfigurationChecker
                configurationChecker={makeChecker({
                    diff: { changes: [change({ label: longLabel, old_display_value: 'x', new_display_value: 'y' })] },
                })}
            />,
        );
        act(() => screen.getByText('View changes').click());

        expect(screen.getByText(longLabel.slice(0, 20))).toBeInTheDocument();
        expect(screen.queryByText(longLabel)).not.toBeInTheDocument();

        act(() => screen.getByTitle('Toggle full value').click());

        expect(screen.getByText(longLabel)).toBeInTheDocument();
    });

    it('shows the full value instead of the truncated display value once expanded', () => {
        render(
            <ConfigurationChecker
                configurationChecker={makeChecker({
                    diff: {
                        changes: [
                            change({
                                expandable: true,
                                old_display_value: 'short old',
                                old_full_value: 'the full old value',
                                new_display_value: 'short new',
                                new_full_value: 'the full new value',
                            }),
                        ],
                    },
                })}
            />,
        );
        act(() => screen.getByText('View changes').click());

        expect(screen.getByText('short old')).toBeInTheDocument();
        expect(screen.getByText('short new')).toBeInTheDocument();

        act(() => screen.getByTitle('Toggle full value').click());

        expect(screen.getByText('the full old value')).toBeInTheDocument();
        expect(screen.getByText('the full new value')).toBeInTheDocument();
    });

    it('reloads only the configurationChecker prop when an ApplicationConfigurationChanged event arrives', () => {
        render(<ConfigurationChecker configurationChecker={makeChecker()} />);

        act(() => teamChannelCallback());

        expect(reloadSpy).toHaveBeenCalledWith({ only: ['configurationChecker'] });
    });
});
