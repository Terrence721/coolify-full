import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// React 19's own act() (imported from 'react', not react-dom/test-utils) only wraps updates when
// it sees this global set - without it, act() runs but React still logs "not configured to
// support act(...)" for any state update it triggers outside a direct render() call (e.g.
// simulating an async callback firing after mount, like a WebSocket event handler).
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

// jsdom doesn't implement Element.scrollIntoView (a real, supported browser API) - stub it so
// components that call it (e.g. keyboard-navigating a scrollable result list) don't throw in
// tests. No-op is fine here: nothing in these tests asserts on actual scroll position.
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
}

// Vitest doesn't expose global test hooks by default (test.globals is off in
// vitest.config.js), so Testing Library's own auto-cleanup - which detects a
// global afterEach - never fires. Registering it explicitly here instead of
// repeating it in every test file.
afterEach(() => {
    cleanup();
});
