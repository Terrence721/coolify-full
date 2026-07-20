import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Vitest doesn't expose global test hooks by default (test.globals is off in
// vitest.config.js), so Testing Library's own auto-cleanup - which detects a
// global afterEach - never fires. Registering it explicitly here instead of
// repeating it in every test file.
afterEach(() => {
    cleanup();
});
