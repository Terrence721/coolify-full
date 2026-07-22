import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { describe, expect, it } from 'vitest';
import GitApplicationFields from './GitApplicationFields';

// Covers the build-pack-driven field visibility/defaults (nixpacks/railpack show Port + static
// checkbox, static/dockerfile/dockercompose don't), the port/publish_directory swap when toggling
// "static site", the dockercompose-only base/compose-path fields with their onBlur
// normalizeGitPath() behavior and live preview, and setBuildPack()'s port-preservation rule when
// already static - none of it previously tested.

function baseData(overrides = {}) {
    return {
        build_pack: 'nixpacks',
        is_static: false,
        port: 3000,
        publish_directory: '',
        base_directory: '/',
        docker_compose_location: '/docker-compose.yaml',
        ...overrides,
    };
}

function Harness({ initial }) {
    const [data, setData] = useState(initial);
    return <GitApplicationFields data={data} setData={setData} errors={{}} />;
}

// useState only reads `initial` on first mount, so swapping scenarios needs the SAME render's
// rerender() with a changed `key` - React treats a changed key as a new component identity and
// unmounts/remounts, unlike calling render() again (which just leaves a second, un-cleaned-up
// tree sitting in document.body alongside the first).
function renderHarness(initial) {
    const result = render(<Harness key={JSON.stringify(initial)} initial={initial} />);
    return {
        ...result,
        renderScenario: (nextInitial) => result.rerender(<Harness key={JSON.stringify(nextInitial)} initial={nextInitial} />),
    };
}

describe('GitApplicationFields', () => {
    it('shows Port and the static-site checkbox for nixpacks/railpack, hides them for static/dockerfile/dockercompose', () => {
        const { renderScenario } = renderHarness(baseData({ build_pack: 'nixpacks' }));
        expect(screen.getByLabelText('Port')).toBeInTheDocument();
        expect(screen.getByLabelText('Is it a static site?')).toBeInTheDocument();

        renderScenario(baseData({ build_pack: 'dockerfile' }));
        expect(screen.queryByLabelText('Port')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Is it a static site?')).not.toBeInTheDocument();
    });

    it('sets port to 3000 when switching to nixpacks/railpack, unless already a static site', () => {
        renderHarness(baseData({ build_pack: 'dockerfile', port: 8080 }));

        act(() => {
            const select = screen.getByLabelText('Build Pack');
            const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
            setter.call(select, 'nixpacks');
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(screen.getByLabelText('Port')).toHaveValue(3000);
    });

    it('preserves the current port when switching to nixpacks/railpack while already a static site', () => {
        renderHarness(baseData({ build_pack: 'dockerfile', is_static: true, port: 80 }));

        // is_static is true, but Port only renders for nixpacks/railpack/static's showIsStatic
        // condition - toggle to nixpacks first to observe the port value post-switch.
        act(() => {
            const select = screen.getByLabelText('Build Pack');
            const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
            setter.call(select, 'nixpacks');
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        expect(screen.getByLabelText('Port')).toHaveValue(80);
    });

    it('switching to "static" build pack hides Port/checkbox and clears is_static (confirmed by the nixpacks-default reset on the way back)', () => {
        renderHarness(baseData({ build_pack: 'nixpacks', is_static: true, port: 3000 }));

        act(() => {
            const select = screen.getByLabelText('Build Pack');
            const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
            setter.call(select, 'static');
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect(screen.queryByLabelText('Port')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Is it a static site?')).not.toBeInTheDocument();

        // is_static was cleared by the 'static' switch - switching back to nixpacks now resets
        // port to the plain nixpacks default (3000), not the 80 it was carrying before, because
        // the "preserve port while already static" rule no longer applies.
        act(() => {
            const select = screen.getByLabelText('Build Pack');
            const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
            setter.call(select, 'nixpacks');
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect(screen.getByLabelText('Port')).toHaveValue(3000);
        expect(screen.getByLabelText('Is it a static site?')).not.toBeChecked();
    });

    it('toggling "static site" on sets port 80 + /dist, off sets port 3000 + empty publish directory', () => {
        renderHarness(baseData({ build_pack: 'nixpacks', is_static: false, port: 3000 }));

        act(() => screen.getByLabelText('Is it a static site?').click());
        expect(screen.getByLabelText('Port')).toHaveValue(80);
        expect(screen.getByLabelText('Publish Directory')).toHaveValue('/dist');

        act(() => screen.getByLabelText('Is it a static site?').click());
        expect(screen.getByLabelText('Port')).toHaveValue(3000);
        expect(screen.queryByLabelText('Publish Directory')).not.toBeInTheDocument();
    });

    it('only renders Publish Directory while is_static is true', () => {
        const { renderScenario } = renderHarness(baseData({ is_static: false }));
        expect(screen.queryByLabelText('Publish Directory')).not.toBeInTheDocument();

        renderScenario(baseData({ is_static: true }));
        expect(screen.getByLabelText('Publish Directory')).toBeInTheDocument();
    });

    it('shows Base Directory + Docker Compose Location with a live preview only for the dockercompose build pack', () => {
        const { renderScenario } = renderHarness(baseData({ build_pack: 'nixpacks' }));
        expect(screen.queryByLabelText('Docker Compose Location')).not.toBeInTheDocument();

        renderScenario(baseData({ build_pack: 'dockercompose', base_directory: '/app', docker_compose_location: '/docker-compose.yml' }));
        expect(screen.getByLabelText('Docker Compose Location')).toBeInTheDocument();
        expect(screen.getByText('/app/docker-compose.yml')).toBeInTheDocument();
    });

    it("computes the compose preview correctly when base_directory is the root '/'", () => {
        renderHarness(baseData({ build_pack: 'dockercompose', base_directory: '/', docker_compose_location: '/docker-compose.yaml' }));
        expect(screen.getByText('/docker-compose.yaml')).toBeInTheDocument();
    });

    it('normalizes the base directory on blur: trims, strips trailing slashes, enforces a leading slash', () => {
        renderHarness(baseData({ build_pack: 'dockercompose', base_directory: '' }));

        const input = screen.getByLabelText('Base Directory');
        act(() => input.focus());
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(input, 'apps/web/');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => input.blur());

        expect(screen.getByLabelText('Base Directory')).toHaveValue('/apps/web');
    });

    it('normalizes an empty/whitespace base directory to just "/" on blur', () => {
        renderHarness(baseData({ build_pack: 'dockercompose', base_directory: '' }));

        const input = screen.getByLabelText('Base Directory');
        act(() => input.focus());
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        act(() => {
            setter.call(input, '   ');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        act(() => input.blur());

        expect(screen.getByLabelText('Base Directory')).toHaveValue('/');
    });

    it('does not run onBlur normalization for Base Directory outside the dockercompose build pack', () => {
        renderHarness(baseData({ build_pack: 'nixpacks', base_directory: 'apps/web/' }));

        const input = screen.getByLabelText('Base Directory');
        act(() => input.focus());
        act(() => input.blur());

        expect(screen.getByLabelText('Base Directory')).toHaveValue('apps/web/');
    });

    it('makes Port read-only when the site is static', () => {
        renderHarness(baseData({ build_pack: 'nixpacks', is_static: true, port: 80 }));
        expect(screen.getByLabelText('Port')).toHaveAttribute('readonly');
    });
});
