/**
 * Zoom/width appearance settings, ported from the old navbar.blade.php Alpine
 * component's checkZoom()/pageWidth logic (both deleted along with the rest of
 * app.blade.php/navbar.blade.php once the Livewire->React migration completed) - neither
 * had a React replacement, so both silently stopped doing anything even though
 * Pages/Profile/Appearance.jsx kept writing to the same localStorage keys as if they still
 * worked. See todo.md's "Correction, 2026-07-19" note for how that was found.
 */

const ZOOM_STYLE_ID = 'appearance-zoom-style';

/**
 * Injects/updates/removes a <style> tag scaling the root font-size, matching the original
 * Alpine checkZoom()'s exact percentages (93.75% base, 87.5% at the lg breakpoint) so the
 * visual result is unchanged from before the migration, not reinvented.
 */
export function applyZoom(zoom) {
    let style = document.getElementById(ZOOM_STYLE_ID);

    if (zoom !== '90') {
        style?.remove();
        return;
    }

    if (!style) {
        style = document.createElement('style');
        style.id = ZOOM_STYLE_ID;
        document.head.appendChild(style);
    }

    style.textContent = `
        html {
            font-size: 93.75%;
        }

        @media (min-width: 1024px) {
            html {
                font-size: 87.5%;
            }
        }
    `;
}

/**
 * Matches the original app.blade.php's :class="pageWidth === 'full' ? '' : 'max-w-7xl'"
 * (plus the mx-auto it always carried) applied to the main content area.
 */
export function pageWidthClass(pageWidth) {
    return pageWidth === 'full' ? '' : 'max-w-7xl mx-auto';
}
