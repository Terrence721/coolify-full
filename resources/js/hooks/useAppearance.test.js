import test from 'node:test';
import assert from 'node:assert/strict';
import { pageWidthClass } from './useAppearance.js';

test('pageWidthClass constrains width unless "full" is selected, matching the old app.blade.php binding', () => {
    assert.equal(pageWidthClass('full'), '');
    assert.equal(pageWidthClass('center'), 'max-w-7xl mx-auto');
    assert.equal(pageWidthClass('anything-else'), 'max-w-7xl mx-auto');
});

// applyZoom() manipulates document.head/document.getElementById - no DOM available under
// plain node:test, so its coverage lives separately in useAppearance.test.jsx (Vitest's
// jsdom environment). Originally verified only via a real browser session (throwaway
// Playwright container, confirming html font-size actually changes 16px -> 14px at the
// lg breakpoint when zoom is set to '90') before that automated coverage existed.
