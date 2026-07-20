import { describe, expect, it, afterEach } from 'vitest';
import { applyZoom } from './useAppearance';

// Regression coverage for the other half of useAppearance.js (see pageWidthClass()'s
// existing node:test coverage in useAppearance.test.js) - applyZoom() manipulates
// document.head/document.getElementById, which plain node:test can't exercise (no jsdom
// dependency at the time that file was written). Vitest's jsdom environment closes that gap.
// Previously verified only via a one-off manual browser session (see todo.md's "Cleanup
// opportunities" - "Zoom/page-width settings not applied on React pages").

const ZOOM_STYLE_ID = 'appearance-zoom-style';

afterEach(() => {
    document.getElementById(ZOOM_STYLE_ID)?.remove();
});

describe('applyZoom', () => {
    it('injects a style tag scaling the root font-size when zoom is "90"', () => {
        applyZoom('90');

        const style = document.getElementById(ZOOM_STYLE_ID);
        expect(style).not.toBeNull();
        expect(style.tagName).toBe('STYLE');
        expect(style.textContent).toContain('font-size: 93.75%');
        expect(style.textContent).toContain('font-size: 87.5%');
        expect(style.textContent).toContain('min-width: 1024px');
    });

    it('does not inject a style tag for the default zoom level', () => {
        applyZoom('100');

        expect(document.getElementById(ZOOM_STYLE_ID)).toBeNull();
    });

    it('does not inject a style tag for an unrecognized zoom value', () => {
        applyZoom('nonsense');

        expect(document.getElementById(ZOOM_STYLE_ID)).toBeNull();
    });

    it('removes the style tag when zoom changes away from "90"', () => {
        applyZoom('90');
        expect(document.getElementById(ZOOM_STYLE_ID)).not.toBeNull();

        applyZoom('100');

        expect(document.getElementById(ZOOM_STYLE_ID)).toBeNull();
    });

    it('reuses the existing style tag instead of creating a duplicate on repeated calls', () => {
        applyZoom('90');
        const first = document.getElementById(ZOOM_STYLE_ID);

        applyZoom('90');
        const second = document.getElementById(ZOOM_STYLE_ID);

        expect(second).toBe(first);
        expect(document.head.querySelectorAll(`#${ZOOM_STYLE_ID}`)).toHaveLength(1);
    });
});
