import test from 'node:test';
import assert from 'node:assert/strict';
import { pageWidthClass } from './useAppearance.js';

test('pageWidthClass constrains width unless "full" is selected, matching the old app.blade.php binding', () => {
    assert.equal(pageWidthClass('full'), '');
    assert.equal(pageWidthClass('center'), 'max-w-7xl mx-auto');
    assert.equal(pageWidthClass('anything-else'), 'max-w-7xl mx-auto');
});

// applyZoom() manipulates document.head/document.getElementById - no DOM available under
// node:test (no jsdom dependency in this project). Verified live instead via a real browser
// session (throwaway Playwright container) confirming html font-size actually changes
// 16px -> 14px at the lg breakpoint when zoom is set to '90' - see todo.md.
